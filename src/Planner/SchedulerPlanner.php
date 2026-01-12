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
 *
 * CONTRACT (Identity):
 * - Desired entries emitted by this planner ALWAYS have uid + _manifest.
 * - Existing FPP schedule entries MAY NOT have uid or manifest.
 * - SchedulerDiff is responsible for reconciling that asymmetry.
 */
final class SchedulerPlanner
{
    private const DEFAULT_MAX_MANAGED_ENTRIES = 100;

    /**
     * Safety cap for iterative ordering passes.
     * If we hit this, we stop and keep the best stabilized order so far.
     */
    private const MAX_ORDER_PASSES = 50;

    public static function plan(array $config): array
    {
        $debug = self::isDebugOrderingEnabled($config);
        if ($debug) {
            self::dbgReset();
            self::dbg($config, 'enter', [
                'ts'  => date('c'),
                'tz'  => date_default_timezone_get(),
                'php' => PHP_VERSION,
            ]);
        }

        /* -----------------------------------------------------------------
         * 0. Guard date
         * ----------------------------------------------------------------- */
        $guardDate = FPPSemantics::getSchedulerGuardDate()->format('Y-m-d');

        if ($debug) {
            self::dbg($config, 'guard', [
                'guardDate' => $guardDate,
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
                'ok'           => (bool)($runnerResult['ok'] ?? false),
                'series_count' => count($series),
                'errors'       => $runnerResult['errors'] ?? [],
            ]);
        }

        /* -----------------------------------------------------------------
         * 2. Build bundles (base + overrides)
         *
         * For now, overrides are kept as an empty array to keep bundle cohesion
         * logic stable and ready for future override emission.
         * ----------------------------------------------------------------- */
        $bundles = [];

        foreach ($series as $s) {
            if (!is_array($s)) {
                continue;
            }

            $uid = (string)($s['uid'] ?? '');
            if ($uid === '') {
                continue;
            }

            $summary  = (string)($s['summary'] ?? '');
            $resolved = (isset($s['resolved']) && is_array($s['resolved'])) ? $s['resolved'] : null;
            if (!$resolved || empty($resolved['type']) || !array_key_exists('target', $resolved)) {
                if ($debug) {
                    self::dbg($config, 'skip_series_unresolved', [
                        'uid'      => $uid,
                        'summary'  => $summary,
                        'resolved' => $resolved,
                    ]);
                }
                continue;
            }

            $baseEv = (isset($s['base']) && is_array($s['base'])) ? $s['base'] : null;
            if (!$baseEv || empty($baseEv['start']) || empty($baseEv['end'])) {
                if ($debug) {
                    self::dbg($config, 'skip_series_no_base', [
                        'uid'     => $uid,
                        'summary' => $summary,
                    ]);
                }
                continue;
            }

            // Extract opaque pass-through data
            $payload = (isset($s['payload']) && is_array($s['payload'])) ? $s['payload'] : [];
            $gcs     = (isset($s['gcs']) && is_array($s['gcs'])) ? $s['gcs'] : [];

            // ------------------------------------------------------------------
            // Invariant: payload and gcs are opaque metadata containers.
            // They MUST NOT be merged into the scheduler template or identity.
            // SchedulerPlanner only passes them through for downstream consumers.
            // ------------------------------------------------------------------

            // Defensive: ensure no accidental leakage of payload/gcs into template
            if (
                isset($baseEv['template']) &&
                (
                    (is_array($baseEv['template']) && (array_key_exists('payload', $baseEv['template']) || array_key_exists('gcs', $baseEv['template'])))
                )
            ) {
                throw new \RuntimeException("Invariant violation: payload/gcs found inside template for uid $uid");
            }

            try {
                $baseStartDT = new DateTime((string)$baseEv['start']);
                $baseEndDT   = new DateTime((string)$baseEv['end']);
            } catch (\Throwable $e) {
                if ($debug) {
                    self::dbg($config, 'skip_series_bad_base_dates', [
                        'uid'     => $uid,
                        'summary' => $summary,
                        'err'     => $e->getMessage(),
                        'start'   => $baseEv['start'] ?? null,
                        'end'     => $baseEv['end'] ?? null,
                    ]);
                }
                continue;
            }

            $seriesStartDate = $baseStartDT->format('Y-m-d');
            $seriesEndDate   = self::pickSeriesEndDateFromRrule($baseEv, $guardDate) ?? $guardDate;
            $daysShort       = self::deriveDaysShortFromBase($baseEv, $baseStartDT);

            $bundles[] = [
                'overrides' => [],
                'base' => [
                    'uid'     => $uid,
                    'payload' => $payload,
                    'gcs'     => $gcs,
                    'template' => [
                        'uid'        => $uid,
                        'summary'    => $summary,
                        'type'       => FPPSemantics::normalizeType((string)$resolved['type']),
                        'target'     => $resolved['target'],
                        'start'      => $baseStartDT->format('Y-m-d H:i:s'),
                        'end'        => $baseEndDT->format('Y-m-d H:i:s'),
                        'stopType'   => FPPSemantics::getDefaultStopType(),
                        'repeat'     => FPPSemantics::getDefaultRepeatForType(
                            FPPSemantics::normalizeType((string)$resolved['type'])
                        ),
                        'isOverride' => false,
                    ],
                    'range' => [
                        'start' => $seriesStartDate,
                        'end'   => $seriesEndDate,
                        'days'  => $daysShort,
                    ],
                ],
            ];

            // Defensive: ensure no metadata leakage into template
            unset($bundles[count($bundles) - 1]['base']['template']['payload']);
            unset($bundles[count($bundles) - 1]['base']['template']['gcs']);

            // DEBUG: verify fresh base intent creation (no mutation, no reuse)
            if ($debug) {
                $created = $bundles[count($bundles) - 1]['base'];
                self::dbg($config, 'planner_base_created', [
                    'uid'       => (string)($created['uid'] ?? ''),
                    'summary'   => (string)($created['template']['summary'] ?? ''),
                    'type'      => (string)($created['template']['type'] ?? ''),
                    'target'    => $created['template']['target'] ?? null,
                    'range'     => $created['range'] ?? null,
                    'start'     => $created['template']['start'] ?? null,
                    'end'       => $created['template']['end'] ?? null,
                    'object_id' => spl_object_id((object)$created),
                ]);
            }
        }

        if ($debug) {
            self::dbg($config, 'bundles_built', [
                'bundle_count' => count($bundles),
                'first10'      => array_slice(array_map([self::class, 'bundleDebugRow'], $bundles), 0, 10),
            ]);
        }

        /* -----------------------------------------------------------------
         * 3. ORDER BUNDLES — SIMPLIFIED MODEL
         *
         * Rule summary:
         *  1) Start in chronological order by (range.start, then daily start time)
         *  2) Iterate passes; for any overlapping bundles:
         *    dominance rules determine ordering
         *
         * Bundle cohesion:
         *  - When a bundle moves, it moves as a unit (overrides + base together).
         * ----------------------------------------------------------------- */

        // 3a) Baseline chronological order (date / time / type / target)
        usort($bundles, static function (array $a, array $b): int {
            $ar = $a['base']['range'] ?? [];
            $br = $b['base']['range'] ?? [];

            // 1) Series start date
            $as = (string)($ar['start'] ?? '');
            $bs = (string)($br['start'] ?? '');
            if ($as !== $bs) {
                return strcmp($as, $bs);
            }

            // 2) Daily start time
            $aStart = substr((string)($a['base']['template']['start'] ?? ''), 11, 8);
            $bStart = substr((string)($b['base']['template']['start'] ?? ''), 11, 8);
            if ($aStart !== $bStart) {
                return strcmp($aStart, $bStart); // earlier first
            }

            // 3) Type (playlist / command)
            $aType = (string)($a['base']['template']['type'] ?? '');
            $bType = (string)($b['base']['template']['type'] ?? '');
            if ($aType !== $bType) {
                return strcmp($aType, $bType);
            }

            // 4) Target (playlist name, command, etc.)
            $aTarget = is_scalar($a['base']['template']['target'] ?? null)
                ? (string)$a['base']['template']['target']
                : '';
            $bTarget = is_scalar($b['base']['template']['target'] ?? null)
                ? (string)$b['base']['template']['target']
                : '';

            return strcmp($aTarget, $bTarget);
        });

        if ($debug) {
            self::dbgWriteHuman('/tmp/gcs_planner_order_initial.txt', $bundles);
            self::dbg($config, 'order_baseline_done', ['bundle_count' => count($bundles)]);
        }

        // 3b) Global dominance relaxation (insertion-style)
        $passes     = 0;
        $swapsTotal = 0;
        $n          = count($bundles);

        while ($passes < self::MAX_ORDER_PASSES) {
            $passes++;
            $changed = false;

            // Walk top → bottom, bubbling each entry upward as needed
            for ($j = 0; $j < $n; $j++) {
                $B = $bundles[$j];
                $bBase = $B['base'] ?? null;
                if (!is_array($bBase)) {
                    continue;
                }

                // Compare against entries above B
                for ($i = $j - 1; $i >= 0; $i--) {
                    $A = $bundles[$i];
                    $aBase = $A['base'] ?? null;
                    if (!is_array($aBase)) {
                        continue;
                    }

                    // If B should be above A, move it upward
                    if (self::dominates($bBase, $aBase, $config, $debug)) {
                        if ($debug) {
                            self::dbg($config, 'swap_global_dominance', [
                                'from' => $j,
                                'to'   => $i,
                                'A'    => self::bundleDebugRow($A),
                                'B'    => self::bundleDebugRow($B),
                            ]);
                        }

                        // Remove B from position j
                        $moved = array_splice($bundles, $j, 1);
                        // Insert B at position i
                        array_splice($bundles, $i, 0, $moved);

                        $swapsTotal++;
                        $changed = true;

                        // Continue bubbling the same entry upward
                        $j = $i;
                        break;
                    }
                }
            }

            if ($debug) {
                self::dbg($config, 'order_pass_done', [
                    'pass'       => $passes,
                    'changed'    => $changed,
                    'swapsTotal' => $swapsTotal,
                ]);
            }

            // Fully stable — no swaps in this entire pass
            if (!$changed) {
                break;
            }
        }

        // Final ordering snapshot
        if ($debug) {
            self::dbg($config, 'order_done', [
                'passes'     => $passes,
                'swapsTotal' => $swapsTotal,
                'note'       => ($passes >= self::MAX_ORDER_PASSES) ? 'hit_max_passes' : 'stabilized',
            ]);
            self::dbgWriteHuman('/tmp/gcs_planner_order_after_passes.txt', $bundles);
        }

        /* -----------------------------------------------------------------
         * 4. Flatten bundles (bundle cohesion preserved)
         * ----------------------------------------------------------------- */
        $desiredEntries = [];

        foreach ($bundles as $bundle) {
            // Overrides would be emitted here (above base) when enabled.

            // ------------------------------------------------------------------
            // Build manifest identity ONCE at planning time (immutable thereafter)
            // ------------------------------------------------------------------
            // CONTRACT:
            // - Desired entries ALWAYS have uid + _manifest attached here.
            // - Existing FPP schedule entries MAY NOT have uid or manifest.
            // - SchedulerDiff is responsible for reconciling that asymmetry.
            if (!isset($bundle['base']['uid']) || (string)$bundle['base']['uid'] === '') {
                throw new \RuntimeException('Invariant violation: missing uid on base intent before manifest identity');
            }
            $bundle['base']['uid'] = (string) $bundle['base']['uid'];

            $manifest = ManifestIdentity::fromIntent($bundle['base']);

            $isPreview = (($config['mode'] ?? '') === 'adopt_preview');

            // ManifestIdentity now returns ARRAY ONLY
            if (!is_array($manifest) || empty($manifest['id']) || empty($manifest['hash'])) {
                if ($isPreview) {
                    if ($debug) {
                        self::dbg($config, 'manifest_identity_incomplete_preview', [
                            'uid'     => (string)($bundle['base']['uid'] ?? ''),
                            'summary' => (string)($bundle['base']['template']['summary'] ?? ''),
                            'raw'     => $manifest,
                        ]);
                    }

                    // Preview-only: attach placeholder manifest and continue planning
                    $manifest = [
                        'id'   => null,
                        'hash' => null,
                    ];
                } else {
                    throw new \RuntimeException('Invariant violation: invalid manifest identity');
                }
            }

            if ($debug) {
                self::dbg($config, 'manifest_identity', [
                    'uid'    => (string)($bundle['base']['uid'] ?? ''),
                    'id'     => $manifest['id'],
                    'hash'   => $manifest['hash'],
                    'range'  => $bundle['base']['range'] ?? null,
                    'type'   => $bundle['base']['template']['type'] ?? null,
                    'target' => $bundle['base']['template']['target'] ?? null,
                ]);
            }

            $entry = SchedulerSync::intentToScheduleEntryPublic($bundle['base']);
            if (!$entry || !is_array($entry)) {
                continue;
            }

            // Attach manifest AFTER entry materialization so it is preserved
            $entry['_manifest'] = [
                'uid'  => (string) ($bundle['base']['uid'] ?? ''),
                'id'   => $manifest['id'],
                'hash' => $manifest['hash'],
            ];

            $guarded = self::applyGuardRulesToEntry($entry, $guardDate);
            if ($guarded !== null) {
                $desiredEntries[] = $guarded;
            }
        }

        /* -----------------------------------------------------------------
         * 5. Global managed entry cap
         * ----------------------------------------------------------------- */
        $maxManaged = self::maxManagedEntries($config);
        if (count($desiredEntries) > $maxManaged) {
            return [
                'ok' => false,
                'error' => [
                    'type'      => 'scheduler_entry_limit_exceeded',
                    'limit'     => $maxManaged,
                    'attempted' => count($desiredEntries),
                    'guardDate' => $guardDate,
                ],
            ];
        }

        /* -----------------------------------------------------------------
         * 6. Load existing scheduler state + diff
         * ----------------------------------------------------------------- */
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
            'ts'   => date('c'),
            'tag'  => $tag,
            'data' => $data,
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
                is_scalar($b['base']['template']['target'] ?? null)
                    ? (string)$b['base']['template']['target']
                    : gettype($b['base']['template']['target'] ?? null)
            );
        }
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    private static function bundleDebugRow(array $bundle): array
    {
        $b = $bundle['base'] ?? [];
        return [
            'uid'       => (string)($b['uid'] ?? ''),
            'summary'   => (string)($b['template']['summary'] ?? ''),
            'type'      => (string)($b['template']['type'] ?? ''),
            'target'    => $b['template']['target'] ?? null,
            'range'     => $b['range'] ?? null,
            'startTime' => substr((string)($b['template']['start'] ?? ''), 11, 8),
            'endTime'   => substr((string)($b['template']['end'] ?? ''), 11, 8),
        ];
    }

    /* ===============================================================
     * Core helpers
     * =============================================================== */

    private static function maxManagedEntries(array $cfg): int
    {
        $v = $cfg['limits']['max_managed_entries'] ?? self::DEFAULT_MAX_MANAGED_ENTRIES;
        $v = is_int($v) ? $v : (int)$v;
        return ($v > 0) ? $v : self::DEFAULT_MAX_MANAGED_ENTRIES;
    }

    private static function applyGuardRulesToEntry(array $entry, string $guardDate): ?array
    {
        $start = $entry['startDate'] ?? '';
        if (!is_string($start) || $start === '') {
            return null;
        }
        if ($start >= $guardDate) {
            return null;
        }

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
                if ($days !== '') {
                    return $days;
                }
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
        if (!is_array($rrule) || empty($rrule['UNTIL'])) {
            return null;
        }

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
            if ($ymd > $guardDate) {
                return $guardDate;
            }

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
        foreach (explode(',', strtoupper(trim($bydayRaw))) as $d) {
            $d = preg_replace('/^[+-]?\d+/', '', trim($d));
            if (isset($map[$d])) {
                $out .= $map[$d];
            }
        }
        return $out;
    }

    private static function isValidYmd(string $s): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
    }

    /* ===============================================================
     * Overlap helpers (verbose)
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

        // Date range intersection (touching edges are NOT overlap)
        if ($aEndD <= $bStartD || $bEndD <= $aStartD) {
            return [
                'overlaps' => false,
                'reason'   => 'date_no_intersection',
                'A'        => [$aStartD, $aEndD],
                'B'        => [$bStartD, $bEndD],
            ];
        }

        $aDays = (string)($ar['days'] ?? '');
        $bDays = (string)($br['days'] ?? '');
        if (!self::daysOverlapShort($aDays, $bDays)) {
            return [
                'overlaps' => false,
                'reason'   => 'days_no_intersection',
                'A_days'   => $aDays,
                'B_days'   => $bDays,
            ];
        }

        $aStart = self::timeToSeconds(substr((string)($a['template']['start'] ?? ''), 11));
        $aEnd   = self::timeToSeconds(substr((string)($a['template']['end'] ?? ''), 11));
        $bStart = self::timeToSeconds(substr((string)($b['template']['start'] ?? ''), 11));
        $bEnd   = self::timeToSeconds(substr((string)($b['template']['end'] ?? ''), 11));

        if (!self::timeWindowsOverlapSeconds($aStart, $aEnd, $bStart, $bEnd)) {
            return [
                'overlaps' => false,
                'reason'   => 'time_no_intersection',
                'A_time'   => [substr((string)($a['template']['start'] ?? ''), 11, 8), substr((string)($a['template']['end'] ?? ''), 11, 8)],
                'B_time'   => [substr((string)($b['template']['start'] ?? ''), 11, 8), substr((string)($b['template']['end'] ?? ''), 11, 8)],
            ];
        }

        if ($debugCfg !== null && self::isDebugOrderingEnabled($debugCfg)) {
            self::dbg($debugCfg, 'overlap_hit', [
                'A' => self::bundleDebugRow(['base' => $a]),
                'B' => self::bundleDebugRow(['base' => $b]),
            ]);
        }

        return ['overlaps' => true, 'reason' => 'ok'];
    }

    /**
     * true if EVERY day in $inner exists in $outer (both compact 2-letter chunks)
     */
    private static function daysContainShort(string $inner, string $outer): bool
    {
        if ($inner === '' || $outer === '') {
            return false;
        }
        $len = strlen($inner);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $d = substr($inner, $i, 2);
            if ($d !== '' && strpos($outer, $d) === false) {
                return false;
            }
        }
        return true;
    }

    private static function daysOverlapShort(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        $len = strlen($a);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $d = substr($a, $i, 2);
            if ($d !== '' && strpos($b, $d) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function timeToSeconds(string $t): int
    {
        $t = trim($t);
        if ($t === '') {
            return 0;
        }
        if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) {
            return 0;
        }
        return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (int)($m[3] ?? 0);
    }

    private static function timeWindowsOverlapSeconds(int $as, int $ae, int $bs, int $be): bool
    {
        if ($ae <= $as) {
            $ae += 86400; // wrap
        }
        if ($be <= $bs) {
            $be += 86400; // wrap
        }
        return !($ae <= $bs || $be <= $as);
    }

    /**
     * True if A's daily time window fully CONTAINS B's window.
     * Supports overnight wrapping.
     */
    private static function timeWindowContains(array $aTemplate, array $bTemplate): bool
    {
        $aStart = self::timeToSeconds(substr((string)$aTemplate['start'], 11));
        $aEnd   = self::timeToSeconds(substr((string)$aTemplate['end'], 11));
        $bStart = self::timeToSeconds(substr((string)$bTemplate['start'], 11));
        $bEnd   = self::timeToSeconds(substr((string)$bTemplate['end'], 11));

        if ($aEnd <= $aStart) $aEnd += 86400;
        if ($bEnd <= $bStart) $bEnd += 86400;

        return
            $aStart <= $bStart &&
            $aEnd   >= $bEnd &&
            ($aStart !== $bStart || $aEnd !== $bEnd);
    }

    /**
     * True if placing A above B would prevent B from starting
     * at B's own intended start date + start time.
     */
    private static function blocksStartAtIntendedMoment(array $aBase, array $bBase): bool
    {
        // B's intended start date
        $bStartDate = (string)($bBase['range']['start'] ?? '');
        if ($bStartDate === '') {
            return false;
        }

        // A must be active on B's start date
        $aStartD = (string)($aBase['range']['start'] ?? '');
        $aEndD   = (string)($aBase['range']['end'] ?? '');

        if ($aStartD === '' || $aEndD === '' || $bStartDate < $aStartD || $bStartDate >= $aEndD) {
            return false;
        }

        // Day mask: A must run on B's start day
        $bDow = (int)(new DateTime($bStartDate))->format('w');
        $bDowShort = self::dowToShortDay($bDow);

        $aDays = (string)($aBase['range']['days'] ?? '');
        if ($aDays === '' || strpos($aDays, $bDowShort) === false) {
            return false;
        }

        // Time windows
        $aStartT = self::timeToSeconds(substr((string)$aBase['template']['start'], 11));
        $aEndT   = self::timeToSeconds(substr((string)$aBase['template']['end'], 11));
        $bStartT = self::timeToSeconds(substr((string)$bBase['template']['start'], 11));

        // Overnight wrap for A
        if ($aEndT <= $aStartT) {
            $aEndT += 86400;
        }

        // Earlier-starting daily schedules are background layers
        if ($aStartT < $bStartT) {
            return false;
        }

        // A must already be active at B's start time
        if (!($bStartT >= $aStartT && $bStartT < $aEndT)) {
            return false;
        }

        return true;
    }

    private static function dominates(array $aBase, array $bBase, array $cfg, bool $debug): bool
    {
        // Only meaningful if entries overlap
        $ov = self::basesOverlapVerbose($aBase, $bBase, $debug ? $cfg : null);
        if (empty($ov['overlaps'])) {
            return false;
        }

        $aStartT = self::timeToSeconds(substr((string)$aBase['template']['start'], 11));
        $bStartT = self::timeToSeconds(substr((string)$bBase['template']['start'], 11));

        $aStartD = (string)($aBase['range']['start'] ?? '');
        $bStartD = (string)($bBase['range']['start'] ?? '');

        /* -------------------------------------------------------------
         * 1) Later daily start time wins (overlap only)
         * ------------------------------------------------------------- */
        if ($aStartT > $bStartT) {
            if ($debug) {
                self::dbg($cfg, 'dominance_later_start_time_overlap', [
                    'A' => self::bundleDebugRow(['base' => $aBase]),
                    'B' => self::bundleDebugRow(['base' => $bBase]),
                ]);
            }
            return true;
        }

        /* -------------------------------------------------------------
         * 2) Same daily start time → later calendar start date wins
         *    (seasonal override, same phase)
         * ------------------------------------------------------------- */
        if ($aStartT === $bStartT && $aStartD !== '' && $bStartD !== '' && $aStartD > $bStartD) {
            if ($debug) {
                self::dbg($cfg, 'dominance_later_date_same_start_time', [
                    'A' => self::bundleDebugRow(['base' => $aBase]),
                    'B' => self::bundleDebugRow(['base' => $bBase]),
                ]);
            }
            return true;
        }
        // Later-start-date override wins — do NOT allow starvation logic to counter it
        if ($bStartT === $aStartT && $bStartD > $aStartD) {
            return false;
        }

        /* -------------------------------------------------------------
         * 3) Final safety: prevent start-time starvation
         * ------------------------------------------------------------- */
        if (self::blocksStartAtIntendedMoment($aBase, $bBase)) {
            if ($debug) {
                self::dbg($cfg, 'dominance_blocks_start_moment', [
                    'A' => self::bundleDebugRow(['base' => $aBase]),
                    'B' => self::bundleDebugRow(['base' => $bBase]),
                ]);
            }
            return true;
        }

        return false;
    }
}
