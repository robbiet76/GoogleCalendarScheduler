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
 * - Order bundles deterministically according to simplified FPP precedence model
 * - Flatten bundles into desiredEntries
 *
 * GUARANTEES:
 * - NEVER writes to the FPP scheduler
 * - NEVER mutates schedule.json
 * - Deterministic output
 *
 * DEBUGGING:
 * - When enabled, writes detailed traces to /tmp:
 *   /tmp/gcs_planner_order_debug.log          (JSONL)
 *   /tmp/gcs_planner_order_initial.txt        (human list)
 *   /tmp/gcs_planner_order_after_passes.txt   (human list)
 *
 * Enable debug via:
 * - config['runtime']['debug_ordering'] = true
 *   (or export GCS_DEBUG_ORDERING=1)
 */
final class SchedulerPlanner
{
    private const MAX_MANAGED_ENTRIES = 100;
    private const MAX_ORDER_PASSES    = 50;

    public static function plan(array $config): array
    {
        $debug = self::isDebugOrderingEnabled($config);
        if ($debug) {
            self::dbgReset();
            self::dbg($config, 'enter', [
                'ts' => date('c'),
                'tz' => date_default_timezone_get(),
                'php' => PHP_VERSION,
            ]);
        }

        /* -----------------------------------------------------------------
         * 0. Guard date
         * ----------------------------------------------------------------- */
        $currentYear = (int)date('Y');
        $guardYear   = $currentYear + 5;
        $guardDate   = sprintf('%04d-12-31', $guardYear);

        if ($debug) {
            self::dbg($config, 'guard', [
                'currentYear' => $currentYear,
                'guardYear'   => $guardYear,
                'guardDate'   => $guardDate,
            ]);
        }

        /* -----------------------------------------------------------------
         * 1. Calendar ingestion
         * ----------------------------------------------------------------- */
        $runner = new SchedulerRunner($config);
        $runnerResult = $runner->run();

        $series = (isset($runnerResult['series']) && is_array($runnerResult['series']))
            ? $runnerResult['series']
            : [];

        if ($debug) {
            self::dbg($config, 'runner', [
                'ok' => (bool)($runnerResult['ok'] ?? false),
                'series_count' => count($series),
                'errors' => $runnerResult['errors'] ?? [],
            ]);
        }

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
                if ($debug) {
                    self::dbg($config, 'skip_series_unresolved', [
                        'uid' => $uid,
                        'summary' => $summary,
                        'resolved' => $resolved,
                    ]);
                }
                continue;
            }

            $baseEv = (isset($s['base']) && is_array($s['base'])) ? $s['base'] : null;
            if (!$baseEv || empty($baseEv['start']) || empty($baseEv['end'])) {
                if ($debug) {
                    self::dbg($config, 'skip_series_no_base', [
                        'uid' => $uid,
                        'summary' => $summary,
                    ]);
                }
                continue;
            }

            try {
                $baseStartDT = new DateTime((string)$baseEv['start']);
                $baseEndDT   = new DateTime((string)$baseEv['end']);
            } catch (\Throwable $e) {
                if ($debug) {
                    self::dbg($config, 'skip_series_bad_base_dates', [
                        'uid' => $uid,
                        'summary' => $summary,
                        'err' => $e->getMessage(),
                        'start' => $baseEv['start'] ?? null,
                        'end' => $baseEv['end'] ?? null,
                    ]);
                }
                continue;
            }

            $seriesStartDate = $baseStartDT->format('Y-m-d');
            $seriesEndDate   = self::pickSeriesEndDateFromRrule($baseEv, $guardDate) ?? $guardDate;
            $daysShort       = self::deriveDaysShortFromBase($baseEv, $baseStartDT);

            $bundles[] = [
                'overrides' => [], // kept for future; bundle must remain cohesive
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

        if ($debug) {
            self::dbg($config, 'bundles_built', [
                'bundle_count' => count($bundles),
                'first10' => array_slice(array_map([self::class, 'bundleDebugRow'], $bundles), 0, 10),
            ]);
        }

        /* -----------------------------------------------------------------
         * 3. ORDER BUNDLES — SIMPLIFIED MODEL
         *
         * 2) Start in chronological order (startDate, then daily start time)
         * 3) Repeated passes:
         *    - Scan top-down; for each position i, scan any lower bundle j>i
         *    - If A and B overlap (time overlap somewhere in their date ranges),
         *      enforce precedence without special-case tiers:
         *        a) DATE-RANGE CONTAINMENT: narrower date-range goes ABOVE container
         *        b) Otherwise: later DAILY start time goes ABOVE earlier
         *    - Repeat until a pass makes no moves or MAX passes reached
         *
         * NOTE:
         * - We intentionally do NOT restrict comparisons by type/target:
         *   FPP schedule precedence is global across all entries.
         * - Bundles move as a unit (overrides/base cohesion preserved).
         * ----------------------------------------------------------------- */

        // 3a) Baseline chronological order (stable fallback)
        usort($bundles, static function (array $a, array $b): int {
            $ar = $a['base']['range'];
            $br = $b['base']['range'];

            if (($ar['start'] ?? '') !== ($br['start'] ?? '')) {
                return strcmp((string)($ar['start'] ?? ''), (string)($br['start'] ?? ''));
            }

            $aStart = substr((string)($a['base']['template']['start'] ?? ''), 11, 8);
            $bStart = substr((string)($b['base']['template']['start'] ?? ''), 11, 8);

            return strcmp($aStart, $bStart); // earlier first
        });

        if ($debug) {
            self::dbgWriteHuman('/tmp/gcs_planner_order_initial.txt', $bundles);
            self::dbg($config, 'order_baseline_done', [
                'bundle_count' => count($bundles),
            ]);
        }

        // 3b) Iterative passes (non-adjacent bubbling)
        $passes      = 0;
        $movesTotal  = 0;
        $swapPairs   = [];

        while ($passes < self::MAX_ORDER_PASSES) {
            $passes++;
            $movesThisPass = 0;

            $n = count($bundles);

            for ($i = 0; $i < $n - 1; $i++) {
                $A = $bundles[$i];
                $aBase = $A['base'] ?? null;
                if (!is_array($aBase)) {
                    continue;
                }

                $aStartSec = self::timeToSeconds(substr((string)($aBase['template']['start'] ?? ''), 11));

                // Scan any lower bundle B and bubble it above A when required
                for ($j = $i + 1; $j < $n; $j++) {
                    $B = $bundles[$j];
                    $bBase = $B['base'] ?? null;
                    if (!is_array($bBase)) {
                        continue;
                    }

                    // Only act when they actually overlap in runtime somewhere in their date ranges
                    $overlap = self::basesOverlapVerbose($aBase, $bBase, $debug ? $config : null);
                    if (empty($overlap['overlaps'])) {
                        continue;
                    }

                    $aStartD = (string)($aBase['range']['start'] ?? '');
                    $aEndD   = (string)($aBase['range']['end'] ?? '');
                    $bStartD = (string)($bBase['range']['start'] ?? '');
                    $bEndD   = (string)($bBase['range']['end'] ?? '');

                    // Defensive: if any missing, skip containment logic
                    $canContain = ($aStartD !== '' && $aEndD !== '' && $bStartD !== '' && $bEndD !== '');

                    $movedReason = null;

                    // -------------------------------------------------
                    // (1) DATE-RANGE CONTAINMENT (most specific wins)
                    // If A contains B, then B must be ABOVE A.
                    // -------------------------------------------------
                    if ($canContain) {
                        $aContainsB =
                            ($aStartD <= $bStartD) &&
                            ($aEndD   >= $bEndD) &&
                            ($aStartD !== $bStartD || $aEndD !== $bEndD);

                        if ($aContainsB) {
                            $movedReason = 'date_containment';
                        }
                    }

                    // -------------------------------------------------
                    // (2) Otherwise: DAILY START precedence
                    // Later daily start must be ABOVE earlier daily start.
                    // -------------------------------------------------
                    if ($movedReason === null) {
                        $bStartSec = self::timeToSeconds(substr((string)($bBase['template']['start'] ?? ''), 11));
                        if ($aStartSec < $bStartSec) {
                            $movedReason = 'start_time_desc';
                        }
                    }

                    if ($movedReason === null) {
                        continue;
                    }

                    if ($debug) {
                        $swapPairs[] = [
                            'pass' => $passes,
                            'reason' => $movedReason,
                            'move' => ['from' => $j, 'to' => $i],
                            'A' => self::bundleDebugRow($A),
                            'B' => self::bundleDebugRow($B),
                            'overlap_reason' => $overlap,
                        ];
                    }

                    // Move B from position j to position i (bubble above A)
                    $moved = array_splice($bundles, $j, 1);
                    array_splice($bundles, $i, 0, $moved);

                    $movesThisPass++;
                    $movesTotal++;

                    // After mutation, restart scan just above i to avoid skipping constraints
                    $n = count($bundles);
                    $i = max(-1, $i - 1); // outer loop will ++ to 0+ safely
                    break; // restart scanning with updated ordering
                }
            }

            if ($debug) {
                self::dbg($config, 'order_pass_done', [
                    'pass' => $passes,
                    'movesThisPass' => $movesThisPass,
                    'movesTotal' => $movesTotal,
                ]);
            }

            if ($movesThisPass === 0) {
                break;
            }
        }

        if ($debug) {
            self::dbg($config, 'order_done', [
                'passes' => $passes,
                'movesTotal' => $movesTotal,
                'note' => ($passes >= self::MAX_ORDER_PASSES)
                    ? 'hit_max_passes_possible_unresolved_overlaps'
                    : 'stabilized',
            ]);

            if (!empty($swapPairs)) {
                $tail = array_slice($swapPairs, max(0, count($swapPairs) - 200));
                self::dbg($config, 'swap_pairs_tail', [
                    'count' => count($swapPairs),
                    'tail'  => $tail,
                ]);
            }

            self::dbgWriteHuman('/tmp/gcs_planner_order_after_passes.txt', $bundles);
        }

        /* -----------------------------------------------------------------
         * 4. Flatten bundles (bundle cohesion preserved)
         * ----------------------------------------------------------------- */
        $desiredEntries = [];

        foreach ($bundles as $bundle) {
            // Overrides would be emitted here (above base) when enabled in your bundle model.
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

        if ($debug) {
            self::dbg($config, 'diff', [
                'creates' => count($diff->creates()),
                'updates' => count($diff->updates()),
                'deletes' => count($diff->deletes()),
            ]);
        }

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
     * Debug helpers
     * =============================================================== */

    private static function isDebugOrderingEnabled(array $cfg): bool
    {
        if (!empty($cfg['runtime']['debug_ordering'])) {
            return true;
        }
        $env = getenv('GCS_DEBUG_ORDERING');
        return ($env !== false && $env !== '' && $env !== '0');
    }

    private static function dbgReset(): void
    {
        @unlink('/tmp/gcs_planner_order_debug.log');
        @unlink('/tmp/gcs_planner_order_initial.txt');
        @unlink('/tmp/gcs_planner_order_after_passes.txt');
    }

    private static function dbg(array $cfg, string $tag, array $data): void
    {
        if (!self::isDebugOrderingEnabled($cfg)) {
            return;
        }
        $line = json_encode([
            'ts'  => date('c'),
            'tag' => $tag,
            'data'=> $data,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;

        file_put_contents('/tmp/gcs_planner_order_debug.log', $line, FILE_APPEND);
    }

    private static function dbgWriteHuman(string $path, array $bundles): void
    {
        $lines = [];
        foreach ($bundles as $i => $b) {
            $lines[] = sprintf(
                "%03d %s | %s→%s | %s-%s | days=%s | %s/%s",
                $i,
                (string)($b['base']['template']['summary'] ?? ''),
                (string)($b['base']['range']['start'] ?? ''),
                (string)($b['base']['range']['end'] ?? ''),
                substr((string)($b['base']['template']['start'] ?? ''), 11, 8),
                substr((string)($b['base']['template']['end'] ?? ''), 11, 8),
                (string)($b['base']['range']['days'] ?? ''),
                (string)($b['base']['template']['type'] ?? ''),
                is_scalar($b['base']['template']['target'] ?? null) ? (string)$b['base']['template']['target'] : gettype($b['base']['template']['target'] ?? null)
            );
        }
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    private static function bundleDebugRow(array $bundle): array
    {
        $b = $bundle['base'] ?? [];
        return [
            'uid' => (string)($b['uid'] ?? ''),
            'summary' => (string)($b['template']['summary'] ?? ''),
            'type' => (string)($b['template']['type'] ?? ''),
            'target' => $b['template']['target'] ?? null,
            'range' => $b['range'] ?? null,
            'startTime' => substr((string)($b['template']['start'] ?? ''), 11, 8),
            'endTime' => substr((string)($b['template']['end'] ?? ''), 11, 8),
        ];
    }

    /* ===============================================================
     * Core helpers
     * =============================================================== */

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

    /**
     * RRULE UNTIL fix (anchored to DTSTART time-of-day semantics)
     */
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

    /* ===============================================================
     * Overlap helpers (with verbose debug)
     *
     * Overlap definition:
     * - date ranges intersect (touching edges are NOT overlap)
     * - day masks intersect (any common active day)
     * - daily time windows intersect (wrap supported)
     * =============================================================== */

    private static function basesOverlapVerbose(array $a, array $b, ?array $debugCfg): array
    {
        $ar = $a['range'] ?? [];
        $br = $b['range'] ?? [];

        $aStartD = (string)($ar['start'] ?? '');
        $aEndD   = (string)($ar['end'] ?? '');
        $bStartD = (string)($br['start'] ?? '');
        $bEndD   = (string)($br['end'] ?? '');

        if ($aStartD === '' || $aEndD === '' || $bStartD === '' || $bEndD === '') {
            return ['overlaps' => false, 'reason' => 'missing_date_range'];
        }

        // date intersection (touching is NOT overlap)
        if ($aEndD <= $bStartD || $bEndD <= $aStartD) {
            return ['overlaps' => false, 'reason' => 'date_no_intersection', 'A' => [$aStartD,$aEndD], 'B' => [$bStartD,$bEndD]];
        }

        $aDays = (string)($ar['days'] ?? '');
        $bDays = (string)($br['days'] ?? '');
        if (!self::daysOverlapShort($aDays, $bDays)) {
            return ['overlaps' => false, 'reason' => 'days_no_intersection', 'A_days' => $aDays, 'B_days' => $bDays];
        }

        $aStart = self::timeToSeconds(substr((string)($a['template']['start'] ?? ''), 11));
        $aEnd   = self::timeToSeconds(substr((string)($a['template']['end'] ?? ''), 11));
        $bStart = self::timeToSeconds(substr((string)($b['template']['start'] ?? ''), 11));
        $bEnd   = self::timeToSeconds(substr((string)($b['template']['end'] ?? ''), 11));

        if (!self::timeWindowsOverlapSeconds($aStart, $aEnd, $bStart, $bEnd)) {
            return [
                'overlaps' => false,
                'reason' => 'time_no_intersection',
                'A_time' => [substr((string)($a['template']['start'] ?? ''),11,8), substr((string)($a['template']['end'] ?? ''),11,8)],
                'B_time' => [substr((string)($b['template']['start'] ?? ''),11,8), substr((string)($b['template']['end'] ?? ''),11,8)],
            ];
        }

        // Overlaps
        if ($debugCfg !== null && self::isDebugOrderingEnabled($debugCfg)) {
            self::dbg($debugCfg, 'overlap_hit', [
                'A' => self::bundleDebugRow(['base' => $a]),
                'B' => self::bundleDebugRow(['base' => $b]),
            ]);
        }

        return ['overlaps' => true, 'reason' => 'ok'];
    }

    private static function daysOverlapShort(string $a, string $b): bool
    {
        if ($a === '' || $b === '') return false;
        for ($i = 0; $i + 1 < strlen($a); $i += 2) {
            if (strpos($b, substr($a, $i, 2)) !== false) return true;
        }
        return false;
    }

    private static function timeToSeconds(string $t): int
    {
        $t = trim($t);
        if ($t === '') return 0;
        if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) return 0;
        return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (int)($m[3] ?? 0);
    }

    private static function timeWindowsOverlapSeconds(int $as, int $ae, int $bs, int $be): bool
    {
        if ($ae <= $as) $ae += 86400;
        if ($be <= $bs) $be += 86400;
        return !($ae <= $bs || $be <= $as);
    }
}
