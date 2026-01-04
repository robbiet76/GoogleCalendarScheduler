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
         * 3. ORDER BUNDLES — CHRONOLOGY + OVERLAP RESOLUTION (MECHANICAL)
         *
         * Model:
         * 1) Start in chronological order (start date, then start time).
         * 2) Repeatedly perform a top-down scan:
         *    - If two adjacent bundles overlap (type+target + real overlap),
         *      order them by start time DESC (later start first).
         * 3) Stop when a full pass makes no swaps.
         * 4) Bundles always move as a unit; internal ordering remains intact.
         * ----------------------------------------------------------------- */

        // 3a) Baseline chronological order (stable fallback)
        usort($bundles, static function (array $a, array $b): int {
            $ar = $a['base']['range'] ?? [];
            $br = $b['base']['range'] ?? [];

            $as = (string)($ar['start'] ?? '');
            $bs = (string)($br['start'] ?? '');

            if ($as !== $bs) {
                return strcmp($as, $bs); // startDate ASC
            }

            $aStart = (string)($a['base']['template']['start'] ?? '');
            $bStart = (string)($b['base']['template']['start'] ?? '');

            // Compare HH:MM:SS lexicographically (safe)
            return strcmp(substr($aStart, 11, 8), substr($bStart, 11, 8)); // startTime ASC
        });

        // 3b) Repeated overlap-resolution scan (adjacent swaps only)
        $n = count($bundles);
        $maxPasses = max(1, $n * $n); // hard cap safety
        $pass = 0;

        while ($pass < $maxPasses) {
            $pass++;
            $swapped = false;

            for ($i = 0; $i < $n - 1; $i++) {
                $A = $bundles[$i];
                $B = $bundles[$i + 1];

                if (!self::bundlesRuntimeOverlap($A, $B)) {
                    continue;
                }

                $aStartSec = self::bundleDailyStartSeconds($A);
                $bStartSec = self::bundleDailyStartSeconds($B);

                // If overlapping, later daily start time must be higher priority (above).
                if ($aStartSec < $bStartSec) {
                    $bundles[$i]     = $B;
                    $bundles[$i + 1] = $A;
                    $swapped = true;
                }
            }

            if (!$swapped) {
                break;
            }
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
     * Helpers
     * =============================================================== */

    /**
     * Type+target is the uniqueness scope for precedence.
     * Only bundles within the same (type,target) group can "compete".
     */
    private static function bundlesRuntimeOverlap(array $bundleA, array $bundleB): bool
    {
        $a = $bundleA['base'] ?? null;
        $b = $bundleB['base'] ?? null;
        if (!is_array($a) || !is_array($b)) return false;

        $at = $a['template'] ?? null;
        $bt = $b['template'] ?? null;
        if (!is_array($at) || !is_array($bt)) return false;

        if ((string)($at['type'] ?? '') !== (string)($bt['type'] ?? '')) return false;
        if ((string)($at['target'] ?? '') !== (string)($bt['target'] ?? '')) return false;

        return self::basesOverlap($a, $b);
    }

    private static function bundleDailyStartSeconds(array $bundle): int
    {
        $base = $bundle['base'] ?? null;
        if (!is_array($base)) return 0;

        $tpl = $base['template'] ?? null;
        if (!is_array($tpl)) return 0;

        $ts = (string)($tpl['start'] ?? '');
        if ($ts === '' || strlen($ts) < 19) return 0;

        return self::timeToSeconds(substr($ts, 11, 8));
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
