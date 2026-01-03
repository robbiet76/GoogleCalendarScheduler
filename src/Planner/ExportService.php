<?php
declare(strict_types=1);

/**
 * ExportService
 *
 * Orchestrates read-only export of unmanaged FPP scheduler entries to ICS.
 *
 * Phase 30 (faithful export model):
 * - FPP schedule precedence is order-based (top-down schedule.json).
 * - Google Calendar has no precedence, so overlaps render as duplicates.
 * - To match FPP behavior without breaking legitimate same-day same-name entries:
 *   - Simulate scheduler evaluation across the date horizon.
 *   - Track occupied time windows per date (local wall clock).
 *   - For each entry occurrence, if its window overlaps an already-occupied window,
 *     mark that occurrence as excluded via EXDATE.
 *
 * Guarantees:
 * - Never mutates scheduler.json
 * - Never exports GCS-managed entries
 * - Best-effort processing: invalid entries are skipped with warnings
 */
final class ExportService
{
    /**
     * Export unmanaged scheduler entries to an ICS document.
     *
     * @return array{
     *   ok: bool,
     *   exported: int,
     *   skipped: int,
     *   unmanaged_total: int,
     *   warnings: string[],
     *   errors: string[],
     *   ics: string
     * }
     */
    public static function exportUnmanaged(): array
    {
        $warnings = [];
        $errors = [];

        // Read scheduler entries (read-only)
        try {
            $entries = SchedulerSync::readScheduleJsonStatic(
                SchedulerSync::SCHEDULE_JSON_PATH
            );
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exported' => 0,
                'skipped' => 0,
                'unmanaged_total' => 0,
                'warnings' => [],
                'errors' => ['Failed to read schedule.json: ' . $e->getMessage()],
                'ics' => '',
            ];
        }

        // Select unmanaged scheduler entries only (preserve schedule.json order)
        $unmanaged = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!SchedulerIdentity::isGcsManaged($entry)) {
                $unmanaged[] = $entry;
            }
        }

        $unmanagedTotal = count($unmanaged);

        // Compute EXDATE exclusions by simulating effective schedule precedence (time-window overlap)
        $unmanagedWithExdates = self::applyOrderBasedExdates($unmanaged, $warnings);

        // Convert unmanaged scheduler entries into export intents
        $exportEvents = [];
        $skipped = 0;

        foreach ($unmanagedWithExdates as $entry) {
            $intent = ScheduleEntryExportAdapter::adapt($entry, $warnings);
            if ($intent === null) {
                $skipped++;
                continue;
            }
            $exportEvents[] = $intent;
        }

        $exported = count($exportEvents);

        // Generate ICS output (may be empty; caller can handle messaging)
        $ics = '';
        try {
            $ics = IcsWriter::build($exportEvents);
        } catch (Throwable $e) {
            $errors[] = 'Failed to generate ICS: ' . $e->getMessage();
            $ics = '';
        }

        return [
            'ok' => empty($errors),
            'exported' => $exported,
            'skipped' => $skipped,
            'unmanaged_total' => $unmanagedTotal,
            'warnings' => $warnings,
            'errors' => $errors,
            'ics' => $ics,
        ];
    }

    /**
     * Apply order-based precedence by generating EXDATE lists for occurrences that would be
     * shadowed by previously processed unmanaged entries due to overlapping time windows.
     *
     * - Processes entries in the same order as schedule.json (top-to-bottom).
     * - Tracks occupied time intervals per date in local seconds [0..86400).
     * - Allows multiple entries on the same day (even same name) as long as windows do not overlap.
     *
     * Adds a private export-only field on entries:
     *   __gcs_export_exdates: array<int,string> (YYYY-MM-DD dates to exclude for this entry)
     *
     * @param array<int,array<string,mixed>> $entries
     * @param array<int,string> $warnings
     * @return array<int,array<string,mixed>>
     */
    private static function applyOrderBasedExdates(array $entries, array &$warnings): array
    {
        // occupied[YYYY-MM-DD] = array<int,array{0:int,1:int}> intervals in seconds (start,end)
        $occupied = [];

        $out = [];

        foreach ($entries as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $sd = (string)($entry['startDate'] ?? '');
            $ed = (string)($entry['endDate'] ?? '');
            if (!self::isValidYmd($sd) || !self::isValidYmd($ed)) {
                $out[] = $entry;
                continue;
            }

            $startTime = (string)($entry['startTime'] ?? '00:00:00');
            $endTime   = (string)($entry['endTime'] ?? '00:00:00');

            $win = self::timeWindowSeconds($startTime, $endTime);
            if ($win === null) {
                $out[] = $entry;
                continue;
            }

            // Build candidate occurrence dates based on FPP day enum and date range
            $dates = self::expandOccurrenceDates($entry, $sd, $ed);

            if (empty($dates)) {
                $out[] = $entry;
                continue;
            }

            $exdates = [];

            foreach ($dates as $ymd) {
                // Determine the interval(s) on this date and possibly next date if crossing midnight
                $intervalsByDate = self::intervalsByDateForOccurrence($ymd, $win);

                // Check overlap against occupied map
                $overlaps = false;
                foreach ($intervalsByDate as $d => $intervals) {
                    foreach ($intervals as [$s, $e]) {
                        if ($e <= $s) {
                            continue;
                        }
                        if (self::intervalOverlaps($occupied[$d] ?? [], $s, $e)) {
                            $overlaps = true;
                            break 2;
                        }
                    }
                }

                if ($overlaps) {
                    $exdates[] = $ymd;
                    continue;
                }

                // Claim the intervals (mark occupied) if no overlap
                foreach ($intervalsByDate as $d => $intervals) {
                    foreach ($intervals as [$s, $e]) {
                        if ($e <= $s) continue;
                        $occupied[$d][] = [$s, $e];
                    }
                }
            }

            if (!empty($exdates)) {
                $entry2 = $entry;
                $entry2['__gcs_export_exdates'] = $exdates;

                $warnings[] =
                    "Export EXDATE: entry #" . ($idx + 1) .
                    " excluded " . count($exdates) . " occurrence(s) due to schedule precedence overlaps";

                $out[] = $entry2;
            } else {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Expand occurrence dates for an entry based on its 'day' enum and date range.
     *
     * @param array<string,mixed> $entry
     * @param string $startDate
     * @param string $endDate
     * @return array<int,string> list of YYYY-MM-DD
     */
    private static function expandOccurrenceDates(array $entry, string $startDate, string $endDate): array
    {
        $dayEnum = (int)($entry['day'] ?? -1);

        $start = DateTime::createFromFormat('Y-m-d', $startDate);
        $end   = DateTime::createFromFormat('Y-m-d', $endDate);
        if (!($start instanceof DateTime) || !($end instanceof DateTime)) {
            return [];
        }

        $dates = [];

        $cursor = clone $start;
        while ($cursor <= $end) {
            $ymd = $cursor->format('Y-m-d');

            if (self::matchesDayEnum($cursor, $dayEnum)) {
                $dates[] = $ymd;
            }

            $cursor->modify('+1 day');
        }

        return $dates;
    }

    private static function matchesDayEnum(DateTime $dt, int $enum): bool
    {
        // PHP: 0=Sun..6=Sat
        $w = (int)$dt->format('w');

        if ($enum === 7) { // everyday
            return true;
        }

        return match ($enum) {
            0,1,2,3,4,5,6 => ($w === $enum),
            8  => ($w >= 1 && $w <= 5),        // weekdays
            9  => ($w === 0 || $w === 6),      // weekends
            10 => ($w === 1 || $w === 3 || $w === 5), // MO,WE,FR
            11 => ($w === 2 || $w === 4),      // TU,TH
            12 => ($w === 0 || ($w >= 1 && $w <= 4)), // SU,MO,TU,WE,TH
            13 => ($w === 5 || $w === 6),      // FR,SA
            default => true, // Unknown enum: safest is include (adapter may filter later)
        };
    }

    /**
     * Convert startTime/endTime into a window representation in seconds.
     * Returns [startSec, endSec, crossesMidnight(bool)] where endSec is in [0..86400].
     *
     * @return array{0:int,1:int,2:bool}|null
     */
    private static function timeWindowSeconds(string $startTime, string $endTime): ?array
    {
        $s = self::hmsToSeconds($startTime);
        if ($s === null) return null;

        if ($endTime === '24:00:00') {
            // Treat as midnight rollover: ends at 00:00 next day
            return [$s, 0, true];
        }

        $e = self::hmsToSeconds($endTime);
        if ($e === null) return null;

        $cross = ($e <= $s);
        return [$s, $e, $cross];
    }

    private static function hmsToSeconds(string $hms): ?int
    {
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hms)) {
            return null;
        }
        [$hh, $mm, $ss] = array_map('intval', explode(':', $hms));
        if ($hh < 0 || $hh > 23) return null;
        if ($mm < 0 || $mm > 59) return null;
        if ($ss < 0 || $ss > 59) return null;
        return $hh * 3600 + $mm * 60 + $ss;
    }

    /**
     * For a given occurrence date and window, return intervals per date.
     *
     * @param string $ymd
     * @param array{0:int,1:int,2:bool} $win
     * @return array<string,array<int,array{0:int,1:int}>>
     */
    private static function intervalsByDateForOccurrence(string $ymd, array $win): array
    {
        [$s, $e, $cross] = $win;

        if (!$cross) {
            // same-day interval
            return [
                $ymd => [[$s, $e]],
            ];
        }

        // crosses midnight: [s..86400) on ymd, [0..e) on next day
        $next = DateTime::createFromFormat('Y-m-d', $ymd);
        if (!($next instanceof DateTime)) {
            return [
                $ymd => [[$s, 86400]],
            ];
        }
        $next->modify('+1 day');
        $ymdNext = $next->format('Y-m-d');

        $out = [
            $ymd => [[$s, 86400]],
        ];

        if ($e > 0) {
            $out[$ymdNext] = [[0, $e]];
        }

        return $out;
    }

    /**
     * Check whether [s,e) overlaps any existing interval.
     *
     * @param array<int,array{0:int,1:int}> $intervals
     */
    private static function intervalOverlaps(array $intervals, int $s, int $e): bool
    {
        foreach ($intervals as $iv) {
            $a = (int)($iv[0] ?? 0);
            $b = (int)($iv[1] ?? 0);
            if ($b <= $a) continue;

            // overlap if max(start) < min(end)
            if (max($a, $s) < min($b, $e)) {
                return true;
            }
        }
        return false;
    }

    private static function isValidYmd(string $ymd): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) && strpos($ymd, '0000-') !== 0;
    }
}
