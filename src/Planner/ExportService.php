<?php
declare(strict_types=1);

final class ExportService
{
    public static function exportUnmanaged(): array
    {
        $warnings = [];
        $errors = [];

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

        // -----------------------------------------------------------------
        // Collect unmanaged entries
        // -----------------------------------------------------------------
        $unmanaged = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && !SchedulerIdentity::isGcsManaged($entry)) {
                // Phase 31: normalize all FPP semantics in one place (currently no-op)
                $unmanaged[] = $entry;
            }
        }

        $unmanagedTotal = count($unmanaged);

        // -----------------------------------------------------------------
        // Apply FPP-faithful precedence (FIRST WINS)
        // -----------------------------------------------------------------
        $effective = self::applyPerPlaylistFirstWins($unmanaged, $warnings);

        $exportEvents = [];
        $skipped = 0;

        foreach ($effective as $entry) {
            $intent = ScheduleEntryExportAdapter::adapt($entry, $warnings);
            if ($intent === null) {
                $skipped++;
                continue;
            }
            $exportEvents[] = $intent;
        }

        $ics = '';
        try {
            $ics = IcsWriter::build($exportEvents);
        } catch (Throwable $e) {
            $errors[] = 'Failed to generate ICS: ' . $e->getMessage();
        }

        return [
            'ok' => empty($errors),
            'exported' => count($exportEvents),
            'skipped' => $skipped,
            'unmanaged_total' => $unmanagedTotal,
            'warnings' => $warnings,
            'errors' => $errors,
            'ics' => $ics,
        ];
    }

    /**
     * FPP-faithful export precedence (FIRST WINS):
     *
     * For each summary (playlist/command), and for each occurrence date:
     * - Build occurrences in scheduler.json order (top-down).
     * - If an occurrence is IDENTICAL to an already-kept occurrence
     *   (same start/end window), drop it silently (FPP would ignore it).
     * - If an occurrence OVERLAPS an already-kept occurrence, exclude the later
     *   one via EXDATE on the later entry (first wins).
     * - If it does not overlap, keep it (multiple same-name events per day allowed).
     *
     * EXDATE is stored as exact DTSTART timestamps in:
     *   __gcs_export_exdates_dtstart: array<int,string> "YYYY-MM-DD HH:MM:SS"
     */
    private static function applyPerPlaylistFirstWins(array $entries, array &$warnings): array
    {
        // occurrences[summary][date] = list of occurrences in scheduler order
        $occurrences = [];

        // exdtstartByIdx[idx]["YYYY-MM-DD HH:MM:SS"] = true
        $exdtstartByIdx = [];

        // claimed[summary][date][] = accepted windows
        $claimed = [];

        // Expand occurrences in scheduler order (TOP DOWN)
        foreach ($entries as $idx => $entry) {
            $summary = self::summaryForEntry($entry);
            if ($summary === '') {
                continue;
            }

            $sd = (string)($entry['startDate'] ?? '');
            $ed = (string)($entry['endDate'] ?? '');
            if (!self::isValidYmd($sd) || !self::isValidYmd($ed)) {
                continue;
            }

            $startTime = (string)($entry['startTime'] ?? '00:00:00');
            $endTime   = (string)($entry['endTime'] ?? '00:00:00');

            $win = self::windowSeconds($startTime, $endTime);
            if ($win === null) {
                continue;
            }

            $dates = self::expandDatesForEntry($entry, $sd, $ed);
            if (empty($dates)) {
                continue;
            }

            foreach ($dates as $ymd) {
                [$sAbs, $eAbs] = self::occurrenceAbsRange($ymd, $win);
                $dtstartText = $ymd . ' ' . $startTime;

                $claimed[$summary][$ymd] ??= [];
                $exdtstartByIdx[$idx] ??= [];

                // IDENTICAL window → ignore
                $identical = self::findIdenticalWindow(
                    $claimed[$summary][$ymd],
                    $sAbs,
                    $eAbs
                );
                if ($identical !== null) {
                    continue;
                }

                // OVERLAP → exclude later
                if (self::overlapsAny($claimed[$summary][$ymd], $sAbs, $eAbs)) {
                    $exdtstartByIdx[$idx][$dtstartText] = true;
                    continue;
                }

                // Accept
                $claimed[$summary][$ymd][] = [
                    's' => $sAbs,
                    'e' => $eAbs,
                    'idx' => $idx,
                    'dtstartText' => $dtstartText,
                ];
            }
        }

        // Apply EXDATEs to losing entries
        $out = [];
        foreach ($entries as $idx => $entry) {
            if (!empty($exdtstartByIdx[$idx])) {
                $entry['__gcs_export_exdates_dtstart'] =
                    array_values(array_keys($exdtstartByIdx[$idx]));
            }
            $out[] = $entry;
        }

        return $out;
    }

    /* ---------------- helpers ---------------- */

    private static function summaryForEntry(array $e): string
    {
        return trim((string)($e['playlist'] ?? $e['command'] ?? ''));
    }

    private static function isValidYmd(string $ymd): bool
    {
        return (bool)(
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) &&
            strpos($ymd, '0000-') !== 0
        );
    }

    private static function expandDatesForEntry(array $e, string $sd, string $ed): array
    {
        $day = (int)($e['day'] ?? 7);
        $d = new DateTime($sd);
        $end = new DateTime($ed);
        $out = [];

        while ($d <= $end) {
            if (self::matchesDayEnum($d, $day)) {
                $out[] = $d->format('Y-m-d');
            }
            $d->modify('+1 day');
        }
        return $out;
    }

    private static function matchesDayEnum(DateTime $d, int $e): bool
    {
        $w = (int)$d->format('w'); // 0=Sun … 6=Sat
        if ($e === 7) return true;

        return match ($e) {
            0,1,2,3,4,5,6 => $w === $e,
            8  => $w >= 1 && $w <= 5,
            9  => $w === 0 || $w === 6,
            10 => in_array($w, [1,3,5], true),
            11 => in_array($w, [2,4], true),
            12 => $w <= 4,
            13 => $w >= 5,
            default => true,
        };
    }

    /**
     * @return array{0:int,1:int}|null
     */
    private static function windowSeconds(string $s, string $e): ?array
    {
        $ss = self::hmsToSeconds($s);
        if ($ss === null) return null;

        if ($e === '24:00:00') {
            return [$ss, 86400];
        }

        $es = self::hmsToSeconds($e);
        if ($es === null) return null;

        return [$ss, ($es <= $ss) ? $es + 86400 : $es];
    }

    private static function hmsToSeconds(string $hms): ?int
    {
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hms)) return null;
        [$h, $m, $s] = array_map('intval', explode(':', $hms));
        return $h * 3600 + $m * 60 + $s;
    }

    private static function occurrenceAbsRange(string $_ymd, array $w): array
    {
        return [$w[0], $w[1]];
    }

    private static function findIdenticalWindow(array $accepted, int $s, int $e): ?int
    {
        foreach ($accepted as $i => $iv) {
            if ((int)$iv['s'] === $s && (int)$iv['e'] === $e) {
                return $i;
            }
        }
        return null;
    }

    private static function overlapsAny(array $accepted, int $s, int $e): bool
    {
        foreach ($accepted as $iv) {
            if (max((int)$iv['s'], $s) < min((int)$iv['e'], $e)) {
                return true;
            }
        }
        return false;
    }
}