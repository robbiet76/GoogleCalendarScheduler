<?php
declare(strict_types=1);

/**
 * SchedulerPlanner
 *
 * Planning-only orchestration layer for scheduler diffs.
 *
 * Responsibilities:
 * - Ingest calendar data and resolve scheduling analysis
 * - Translate analysis into desired FPP scheduler entries
 * - Emit ScheduleBundles (base + overrides) as semantic unit
 * - Order bundles deterministically according to FPP precedence rules
 * - Flatten bundles into desiredEntries
 *
 * GUARANTEES:
 * - NEVER writes to the FPP scheduler
 * - NEVER mutates schedule.json
 * - Deterministic output
 */
final class SchedulerPlanner
{
    private const MAX_MANAGED_ENTRIES = 100;

    public static function plan(array $config): array
    {
        /* -----------------------------------------------------------------
         * 0. Guard date
         * ----------------------------------------------------------------- */
        $currentYear = (int)date('Y');
        $guardYear   = $currentYear + 5;
        $guardDate   = sprintf('%04d-12-31', $guardYear);

        /* -----------------------------------------------------------------
         * 1. Calendar ingestion
         * ----------------------------------------------------------------- */
        $runner = new SchedulerRunner($config);
        $runnerResult = $runner->run();

        $series = (isset($runnerResult['series']) && is_array($runnerResult['series']))
            ? $runnerResult['series']
            : [];

        /* -----------------------------------------------------------------
         * 2. Build bundles
         * ----------------------------------------------------------------- */
        $bundles = [];

        foreach ($series as $s) {
            if (!is_array($s)) continue;

            $uid = (string)($s['uid'] ?? '');
            if ($uid === '') continue;

            $summary  = (string)($s['summary'] ?? '');
            $resolved = (isset($s['resolved']) && is_array($s['resolved'])) ? $s['resolved'] : null;
            if (!$resolved || empty($resolved['type']) || !array_key_exists('target', $resolved)) {
                continue;
            }

            $baseEv = (isset($s['base']) && is_array($s['base'])) ? $s['base'] : null;
            if (!$baseEv || empty($baseEv['start']) || empty($baseEv['end'])) continue;

            try {
                $baseStartDT = new DateTime((string)$baseEv['start']);
                $baseEndDT   = new DateTime((string)$baseEv['end']);
            } catch (\Throwable) {
                continue;
            }

            $seriesStartDate = $baseStartDT->format('Y-m-d');
            $seriesEndDate   = self::pickSeriesEndDateFromRrule($baseEv, $guardDate) ?? $guardDate;
            $daysShort       = self::deriveDaysShortFromBase($baseEv, $baseStartDT);

            $bundles[] = [
                'overrides' => [],
                'base' => [
                    'uid' => $uid,
                    'template' => [
                        'uid'       => $uid,
                        'summary'   => $summary,
                        'type'      => (string)$resolved['type'],
                        'target'    => $resolved['target'],
                        'start'     => $baseStartDT->format('Y-m-d H:i:s'),
                        'end'       => $baseEndDT->format('Y-m-d H:i:s'),
                        'stopType'  => 'graceful',
                        'repeat'    => 'immediate',
                        'isOverride'=> false,
                    ],
                    'range' => [
                        'start' => $seriesStartDate,
                        'end'   => $seriesEndDate,
                        'days'  => $daysShort,
                    ],
                ],
            ];
        }

        /* -----------------------------------------------------------------
         * 3. ORDER BUNDLES — PAIRWISE DOMINANCE MODEL
         * ----------------------------------------------------------------- */

        // 3a) Baseline chronological order (stable fallback)
        usort($bundles, static function (array $a, array $b): int {
            $ar = $a['base']['range'];
            $br = $b['base']['range'];

            if ($ar['start'] !== $br['start']) {
                return strcmp($ar['start'], $br['start']);
            }

            return strcmp(
                substr($a['base']['template']['start'], 11),
                substr($b['base']['template']['start'], 11)
            );
        });

        // 3b) Build dominance graph
        $edges = [];
        $inDeg = [];
        $count = count($bundles);

        for ($i = 0; $i < $count; $i++) {
            $edges[$i] = [];
            $inDeg[$i] = 0;
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) continue;

                if (self::bundleDominates($bundles[$i], $bundles[$j])) {
                    $edges[$i][] = $j;
                    $inDeg[$j]++;
                }
            }
        }

        // 3c) Stable topological sort
        $queue = [];
        foreach ($inDeg as $i => $deg) {
            if ($deg === 0) {
                $queue[] = $i;
            }
        }
        sort($queue, SORT_NUMERIC);

        $ordered = [];
        while ($queue) {
            $n = array_shift($queue);
            $ordered[] = $bundles[$n];

            foreach ($edges[$n] as $m) {
                $inDeg[$m]--;
                if ($inDeg[$m] === 0) {
                    $queue[] = $m;
                    sort($queue, SORT_NUMERIC);
                }
            }
        }

        // Safety fallback: preserve chronological order if cycle detected
        if (count($ordered) === $count) {
            $bundles = $ordered;
        }

        /* -----------------------------------------------------------------
         * 4. Flatten bundles
         * ----------------------------------------------------------------- */
        $desiredEntries = [];

        foreach ($bundles as $bundle) {
            $entry = SchedulerSync::intentToScheduleEntryPublic($bundle['base']);
            if (!$entry) continue;

            $guarded = self::applyGuardRulesToEntry($entry, $guardDate);
            if ($guarded) {
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

        $existingRaw = SchedulerSync::readScheduleJsonStatic(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $existingEntries = [];
        foreach ($existingRaw as $row) {
            if (is_array($row)) {
                $existingEntries[] = new ExistingScheduleEntry($row);
            }
        }

        $state = new SchedulerState($existingEntries);
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

    /* ===============================================================
     * DOMINANCE RULE
     * =============================================================== */
    private static function bundleDominates(array $A, array $B): bool
    {
        $a = $A['base'];
        $b = $B['base'];

        if ($a['template']['type'] !== $b['template']['type']) return false;
        if ($a['template']['target'] !== $b['template']['target']) return false;
        if (!self::basesOverlap($a, $b)) return false;

        $ar = $a['range'];
        $br = $b['range'];

        // Date + day containment
        if (
            $ar['start'] >= $br['start'] &&
            $ar['end']   <= $br['end'] &&
            ($ar['start'] !== $br['start'] || $ar['end'] !== $br['end']) &&
            self::daysContainShort($ar['days'], $br['days'])
        ) {
            return true;
        }

        // Same date range → narrower daily window wins
        if ($ar['start'] === $br['start'] && $ar['end'] === $br['end']) {
            $aDur = self::windowDurationSeconds(
                self::timeToSeconds(substr($a['template']['start'], 11)),
                self::timeToSeconds(substr($a['template']['end'], 11))
            );
            $bDur = self::windowDurationSeconds(
                self::timeToSeconds(substr($b['template']['start'], 11)),
                self::timeToSeconds(substr($b['template']['end'], 11))
            );
            return $aDur < $bDur;
        }

        return false;
    }

    /* ===============================================================
     * Helpers
     * =============================================================== */

    private static function daysContainShort(string $inner, string $outer): bool
    {
        if ($inner === '' || $outer === '') return false;

        for ($i = 0; $i + 1 < strlen($inner); $i += 2) {
            $d = substr($inner, $i, 2);
            if (strpos($outer, $d) === false) {
                return false;
            }
        }
        return true;
    }

    private static function applyGuardRulesToEntry(array $entry, string $guardDate): ?array
    {
        $start = $entry['startDate'] ?? '';
        if (!is_string($start) || $start === '') return null;
        if ($start >= $guardDate) return null;

        $end = $entry['endDate'] ?? '';
        if (is_string($end) && $end !== '' && $end > $guardDate) {
            $entry['endDate'] = $guardDate;
        }
        return $entry;
    }

    private static function deriveDaysShortFromBase(array $baseEv, DateTime $dtStart): string
    {
        $rrule = $baseEv['rrule'] ?? null;
        if (is_array($rrule)) {
            if (strtoupper((string)($rrule['FREQ'] ?? '')) === 'DAILY') {
                return 'SuMoTuWeThFrSa';
            }
            if (!empty($rrule['BYDAY'])) {
                $days = self::shortDaysFromByDay((string)$rrule['BYDAY']);
                if ($days !== '') return $days;
            }
        }
        return self::dowToShortDay((int)$dtStart->format('w'));
    }

    private static function pickSeriesEndDateFromRrule(array $baseEv, string $guardDate): ?string
    {
        $rrule = $baseEv['rrule'] ?? null;
        if (!is_array($rrule) || empty($rrule['UNTIL'])) return null;

        try {
            $untilRaw = (string)$rrule['UNTIL'];
            if (preg_match('/^\d{8}$/', $untilRaw)) {
                $until = new DateTime($untilRaw . ' 23:59:59');
            } elseif (preg_match('/^\d{8}T\d{6}Z$/', $untilRaw)) {
                $until = new DateTime($untilRaw, new DateTimeZone('UTC'));
            } elseif (preg_match('/^\d{8}T\d{6}$/', $untilRaw)) {
                $until = new DateTime($untilRaw);
            } else {
                return null;
            }

            $baseStart = new DateTime((string)$baseEv['start']);
            $until->setTimezone($baseStart->getTimezone());

            if ($until->format('H:i:s') < $baseStart->format('H:i:s')) {
                $until->modify('-1 day');
            }

            $ymd = $until->format('Y-m-d');
            if ($ymd > $guardDate) return $guardDate;

            return self::isValidYmd($ymd) ? $ymd : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function dowToShortDay(int $dow): string
    {
        return ['Su','Mo','Tu','We','Th','Fr','Sa'][$dow] ?? '';
    }

    private static function shortDaysFromByDay(string $bydayRaw): string
    {
        $map = ['SU'=>'Su','MO'=>'Mo','TU'=>'Tu','WE'=>'We','TH'=>'Th','FR'=>'Fr','SA'=>'Sa'];
        $out = '';
        foreach (explode(',', strtoupper($bydayRaw)) as $d) {
            $d = preg_replace('/^[+-]?\d+/', '', trim($d));
            if (isset($map[$d])) $out .= $map[$d];
        }
        return $out;
    }

    private static function isValidYmd(string $s): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
    }

    private static function basesOverlap(array $a, array $b): bool
    {
        if ($a['range']['end'] <= $b['range']['start']) return false;
        if ($b['range']['end'] <= $a['range']['start']) return false;
        if (!self::daysOverlapShort($a['range']['days'], $b['range']['days'])) return false;

        return self::timeWindowsOverlapSeconds(
            self::timeToSeconds(substr($a['template']['start'], 11)),
            self::timeToSeconds(substr($a['template']['end'], 11)),
            self::timeToSeconds(substr($b['template']['start'], 11)),
            self::timeToSeconds(substr($b['template']['end'], 11))
        );
    }

    private static function daysOverlapShort(string $a, string $b): bool
    {
        for ($i = 0; $i + 1 < strlen($a); $i += 2) {
            if (strpos($b, substr($a, $i, 2)) !== false) return true;
        }
        return false;
    }

    private static function timeToSeconds(string $t): int
    {
        if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) return 0;
        return ((int)$m[1])*3600 + ((int)$m[2])*60 + (int)($m[3] ?? 0);
    }

    private static function windowDurationSeconds(int $s, int $e): int
    {
        if ($e <= $s) $e += 86400;
        return max(0, $e - $s);
    }

    private static function timeWindowsOverlapSeconds(int $as, int $ae, int $bs, int $be): bool
    {
        if ($ae <= $as) $ae += 86400;
        if ($be <= $bs) $be += 86400;
        return !($ae <= $bs || $be <= $as);
    }
}
