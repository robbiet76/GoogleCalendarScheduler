<?php
declare(strict_types=1);

/**
 * SchedulerPlanner
 *
 * Planning-only orchestration layer for scheduler diffs.
 *
 * Responsibilities:
 * - Ingest calendar analysis from SchedulerRunner
 * - Translate series-level analysis into FPP-native schedule entries
 * - Emit ScheduleBundles (base + overrides)
 * - Order bundles using FPP-native overlap rules
 * - Flatten bundles for Diff / Apply compatibility
 *
 * GUARANTEES:
 * - NEVER writes to the FPP scheduler
 * - NEVER mutates schedule.json
 * - Deterministic output for a given input
 */
final class SchedulerPlanner
{
    private const MAX_MANAGED_ENTRIES = 100;

    public static function plan(array $config): array
    {
        /* ------------------------------------------------------------
         * Guard date (Dec 31 of currentYear + 2)
         * ---------------------------------------------------------- */
        $currentYear = (int)date('Y');
        $guardYear   = $currentYear + 2;
        $guardDate   = sprintf('%04d-12-31', $guardYear);

        /* ------------------------------------------------------------
         * Run analysis
         * ---------------------------------------------------------- */
        $runner = new SchedulerRunner($config);
        $runnerResult = $runner->run();

        $seriesList = $runnerResult['series'] ?? [];
        $bundles = [];

        /* ------------------------------------------------------------
         * Build bundles (base + overrides)
         * ---------------------------------------------------------- */
        foreach ($seriesList as $s) {
            if (!is_array($s)) continue;

            $uid      = (string)($s['uid'] ?? '');
            $summary  = (string)($s['summary'] ?? '');
            $resolved = $s['resolved'] ?? null;
            $baseEv   = $s['base'] ?? null;

            if (
                $uid === '' ||
                !is_array($resolved) ||
                !isset($resolved['type'], $resolved['target']) ||
                !is_array($baseEv) ||
                empty($baseEv['start']) ||
                empty($baseEv['end'])
            ) {
                continue;
            }

            try {
                $baseStartDT = new DateTime($baseEv['start']);
                $baseEndDT   = new DateTime($baseEv['end']);
            } catch (Throwable) {
                continue;
            }

            $seriesStartDate = substr($baseStartDT->format('c'), 0, 10);
            $seriesEndDate   = self::pickSeriesEndDateFromRrule($baseEv, $guardDate) ?? $guardDate;
            $daysShort       = self::deriveDaysShortFromBase($baseEv, $baseStartDT);

            $baseYaml = is_array($s['yamlBase'] ?? null) ? $s['yamlBase'] : [];
            $effBase  = self::applyYamlToTemplate(
                ['stopType' => 'graceful', 'repeat' => 'immediate'],
                $baseYaml
            );

            $baseIntent = [
                'uid' => $uid,
                'template' => [
                    'uid'        => $uid,
                    'summary'    => $summary,
                    'type'       => $resolved['type'],
                    'target'     => $resolved['target'],
                    'start'      => $baseStartDT->format('Y-m-d H:i:s'),
                    'end'        => $baseEndDT->format('Y-m-d H:i:s'),
                    'stopType'   => $effBase['stopType'],
                    'repeat'     => $effBase['repeat'],
                    'isOverride' => false,
                ],
                'range' => [
                    'start' => $seriesStartDate,
                    'end'   => $seriesEndDate,
                    'days'  => $daysShort,
                ],
            ];

            /* ---------------- Overrides ---------------- */
            $overrideIntents = [];
            $overrideOccs = $s['overrideOccs'] ?? [];

            usort($overrideOccs, static fn($a, $b) =>
                strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? ''))
            );

            foreach ($overrideOccs as $ov) {
                if (!is_array($ov) || empty($ov['start']) || empty($ov['end'])) continue;

                try {
                    $ovStart = new DateTime($ov['start']);
                    $ovEnd   = new DateTime($ov['end']);
                } catch (Throwable) {
                    continue;
                }

                $yaml = is_array($ov['yaml'] ?? null) ? $ov['yaml'] : [];
                $eff  = self::applyYamlToTemplate(
                    ['stopType' => 'graceful', 'repeat' => 'immediate'],
                    $yaml
                );

                $date = substr($ovStart->format('c'), 0, 10);

                $overrideIntents[] = [
                    'uid' => $uid,
                    'template' => [
                        'uid'        => $uid,
                        'summary'    => $summary,
                        'type'       => $resolved['type'],
                        'target'     => $resolved['target'],
                        'start'      => $ovStart->format('Y-m-d H:i:s'),
                        'end'        => $ovEnd->format('Y-m-d H:i:s'),
                        'stopType'   => $eff['stopType'],
                        'repeat'     => $eff['repeat'],
                        'isOverride' => true,
                    ],
                    'range' => [
                        'start' => $date,
                        'end'   => $date,
                        'days'  => 'SuMoTuWeThFrSa',
                    ],
                ];
            }

            $bundles[] = [
                'base'      => $baseIntent,
                'overrides' => $overrideIntents,
            ];
        }

        /* ------------------------------------------------------------
         * OVERLAP-AWARE ORDERING (core Phase 28 rule)
         *
         * - If bundles overlap → later start takes priority
         * - If they do not overlap → chronological order
         * ---------------------------------------------------------- */
        usort($bundles, static function (array $a, array $b): int {
            $baseA = $a['base'];
            $baseB = $b['base'];

            $startA = $baseA['template']['start'] ?? '';
            $startB = $baseB['template']['start'] ?? '';

            if ($startA === '' || $startB === '') {
                return 0;
            }

            if (self::basesOverlap($baseA, $baseB)) {
                return strcmp($startB, $startA); // later first
            }

            return strcmp($startA, $startB); // chronological
        });

        /* ------------------------------------------------------------
         * Flatten bundles (overrides directly above base)
         * ---------------------------------------------------------- */
        $desiredIntents = [];
        foreach ($bundles as $bundle) {
            foreach ($bundle['overrides'] as $ov) {
                $desiredIntents[] = $ov;
            }
            $desiredIntents[] = $bundle['base'];
        }

        /* ------------------------------------------------------------
         * Map to scheduler entries + guard enforcement
         * ---------------------------------------------------------- */
        $desiredEntries = [];
        foreach ($desiredIntents as $intent) {
            $entry = SchedulerSync::intentToScheduleEntryPublic($intent);
            if (!is_array($entry)) continue;

            $guarded = self::applyGuardRulesToEntry($entry, $guardDate);
            if ($guarded !== null) {
                $desiredEntries[] = $guarded;
            }
        }

        if (count($desiredEntries) > self::MAX_MANAGED_ENTRIES) {
            return [
                'ok' => false,
                'error' => [
                    'type'      => 'scheduler_entry_limit_exceeded',
                    'limit'     => self::MAX_MANAGED_ENTRIES,
                    'attempted' => count($desiredEntries),
                    'guardDate' => $guardDate,
                ],
            ];
        }

        /* ------------------------------------------------------------
         * Load existing scheduler state + diff
         * ---------------------------------------------------------- */
        $existingRaw = SchedulerSync::readScheduleJsonStatic(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $existingWrapped = [];
        foreach ($existingRaw as $row) {
            if (is_array($row)) {
                $existingWrapped[] = new ExistingScheduleEntry($row);
            }
        }

        $state = new SchedulerState($existingWrapped);
        $diff  = (new SchedulerDiff($desiredEntries, $state))->compute();

        return [
            'ok'             => true,
            'creates'        => $diff->creates(),
            'updates'        => $diff->updates(),
            'deletes'        => $diff->deletes(),
            'desiredEntries' => $desiredEntries,
            'desiredBundles' => $bundles,
            'existingRaw'    => $existingRaw,
        ];
    }

    /* ============================================================
     * Overlap helpers
     * ========================================================== */

    private static function basesOverlap(array $a, array $b): bool
    {
        $ra = $a['range'];
        $rb = $b['range'];

        if ($ra['end'] < $rb['start'] || $rb['end'] < $ra['start']) {
            return false;
        }

        if (!self::daysOverlap($ra['days'], $rb['days'])) {
            return false;
        }

        return self::timeWindowsOverlap(
            $a['template']['start'],
            $a['template']['end'],
            $b['template']['start'],
            $b['template']['end']
        );
    }

    private static function daysOverlap(string $a, string $b): bool
    {
        for ($i = 0; $i < strlen($a); $i += 2) {
            if (strpos($b, substr($a, $i, 2)) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function timeWindowsOverlap(
        string $startA,
        string $endA,
        string $startB,
        string $endB
    ): bool {
        $sa = substr($startA, 11);
        $ea = substr($endA, 11);
        $sb = substr($startB, 11);
        $eb = substr($endB, 11);

        return !($ea <= $sb || $eb <= $sa);
    }

    /* ============================================================
     * Misc helpers (unchanged)
     * ========================================================== */

    private static function applyGuardRulesToEntry(array $entry, string $guardDate): ?array
    {
        $start = $entry['startDate'] ?? '';
        if ($start === '' || $start >= $guardDate) {
            return null;
        }

        if (!empty($entry['endDate']) && $entry['endDate'] > $guardDate) {
            $entry['endDate'] = $guardDate;
        }

        return $entry;
    }

    private static function applyYamlToTemplate(array $defaults, array $yaml): array
    {
        $out = $defaults;
        if (isset($yaml['stopType'])) {
            $out['stopType'] = strtolower((string)$yaml['stopType']);
        }
        if (isset($yaml['repeat'])) {
            $out['repeat'] = is_numeric($yaml['repeat'])
                ? (int)$yaml['repeat']
                : strtolower((string)$yaml['repeat']);
        }
        return $out;
    }

    private static function deriveDaysShortFromBase(array $baseEv, DateTime $dtStart): string
    {
        $rrule = $baseEv['rrule'] ?? null;

        if (is_array($rrule)) {
            $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
            if ($freq === 'DAILY') {
                return 'SuMoTuWeThFrSa';
            }
            if ($freq === 'WEEKLY') {
                $byday = self::shortDaysFromByDay((string)($rrule['BYDAY'] ?? ''));
                if ($byday !== '') {
                    return $byday;
                }
            }
        }

        return self::dowToShortDay((int)$dtStart->format('w'));
    }

    private static function pickSeriesEndDateFromRrule(array $baseEv, string $guardDate): ?string
    {
        $rrule = $baseEv['rrule'] ?? null;
        if (!is_array($rrule) || empty($rrule['UNTIL'])) {
            return null;
        }

        if (preg_match('/^(\d{8})/', (string)$rrule['UNTIL'], $m)) {
            $ymd = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
            return ($ymd > $guardDate) ? $guardDate : $ymd;
        }

        return null;
    }

    private static function shortDaysFromByDay(string $raw): string
    {
        $map = ['SU'=>'Su','MO'=>'Mo','TU'=>'Tu','WE'=>'We','TH'=>'Th','FR'=>'Fr','SA'=>'Sa'];
        $out = '';
        foreach (explode(',', strtoupper($raw)) as $tok) {
            $tok = preg_replace('/^[+-]?\d+/', '', trim($tok));
            if (isset($map[$tok])) {
                $out .= $map[$tok];
            }
        }
        return $out;
    }

    private static function dowToShortDay(int $dow): string
    {
        return match ($dow) {
            0 => 'Su', 1 => 'Mo', 2 => 'Tu', 3 => 'We',
            4 => 'Th', 5 => 'Fr', 6 => 'Sa',
            default => '',
        };
    }
}
