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

        $unmanaged = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && !SchedulerIdentity::isGcsManaged($entry)) {
                $unmanaged[] = $entry;
            }
        }

        $unmanagedTotal = count($unmanaged);

        $effective = self::applyPerPlaylistOverrideExdates($unmanaged, $warnings);

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
     * Apply per-playlist (summary) override logic.
     *
     * Rules:
     * - Conflicts are evaluated ONLY within the same summary and same date
     * - Higher entry (later in schedule.json) wins
     * - Identical DTSTART windows → lower priority is explicitly EXDATE’d
     * - Non-overlapping windows are both allowed
     */
    private static function applyPerPlaylistOverrideExdates(array $entries, array &$warnings): array
    {
        $occurrences = [];
        $totalByIdx = [];
        $excludedByIdx = [];
        $exdtstartByIdx = [];

        // Build occurrences
        foreach ($entries as $idx => $entry) {
            $summary = self::summaryForEntry($entry);
            if ($summary === '') continue;

            $sd = (string)($entry['startDate'] ?? '');
            $ed = (string)($entry['endDate'] ?? '');
            if (!self::isValidYmd($sd) || !self::isValidYmd($ed)) continue;

            $startTime = (string)($entry['startTime'] ?? '00:00:00');
            $endTime   = (string)($entry['endTime'] ?? '00:00:00');

            $win = self::windowSeconds($startTime, $endTime);
            if ($win === null) continue;

            $dates = self::expandDatesForEntry($entry, $sd, $ed);
            if (empty($dates)) continue;

            $totalByIdx[$idx] = count($dates);
            $excludedByIdx[$idx] = 0;
            $exdtstartByIdx[$idx] = [];

            foreach ($dates as $ymd) {
                [$s, $e] = self::occurrenceAbsRange($ymd, $win);
                $occurrences[$summary][$ymd][] = [
                    'idx' => $idx,
                    's' => $s,
                    'e' => $e,
                    'dtstartText' => $ymd . ' ' . $startTime,
                ];
            }
        }

        // Resolve conflicts per summary + per date
        foreach ($occurrences as $summary => $byDate) {
            foreach ($byDate as $ymd => $list) {
                if (count($list) <= 1) continue;

                // Higher index = higher priority
                usort($list, fn($a, $b) => $b['idx'] <=> $a['idx']);

                $accepted = [];

                foreach ($list as $occ) {
                    $conflictIdx = self::findConflictIndex($accepted, $occ['s'], $occ['e']);

                    if ($conflictIdx !== null) {
                        // Identical DTSTART window → exclude lower priority explicitly
                        if (
                            $accepted[$conflictIdx][0] === $occ['s'] &&
                            $accepted[$conflictIdx][1] === $occ['e']
                        ) {
                            $exdtstartByIdx[$occ['idx']][$occ['dtstartText']] = true;
                            $excludedByIdx[$occ['idx']]++;
                            continue;
                        }

                        // General overlap → lower priority loses
                        $exdtstartByIdx[$occ['idx']][$occ['dtstartText']] = true;
                        $excludedByIdx[$occ['idx']]++;
                    } else {
                        $accepted[] = [$occ['s'], $occ['e']];
                    }
                }
            }
        }

        // Apply EXDATEs / suppress fully overridden entries
        $out = [];
        foreach ($entries as $idx => $entry) {
            if (!isset($totalByIdx[$idx])) {
                $out[] = $entry;
                continue;
            }

            if ($excludedByIdx[$idx] >= $totalByIdx[$idx]) {
                $warnings[] = "Export suppress: entry #" . ($idx + 1) . " fully overridden";
                continue;
            }

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
            8  => $w >= 1 && $w <= 5,          // MO–FR
            9  => $w === 0 || $w === 6,        // SU,SA
            10 => in_array($w, [1,3,5], true),
            11 => in_array($w, [2,4], true),
            12 => $w <= 4,                     // SU–TH  ✅ Jan 7 works
            13 => $w >= 5,                     // FR,SA
            default => true,
        };
    }

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

    private static function occurrenceAbsRange(string $_, array $w): array
    {
        return [$w[0], $w[1]];
    }

    private static function findConflictIndex(array $acc, int $s, int $e): ?int
    {
        foreach ($acc as $i => [$a, $b]) {
            if (max($a, $s) <= min($b, $e)) {
                return $i;
            }
        }
        return null;
    }
}
