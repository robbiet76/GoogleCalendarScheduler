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
 * - (Phase 28) Emit ScheduleBundles (base + overrides) as semantic unit
 * - Flatten bundles into desiredEntries for existing diff/apply compatibility
 * - Load existing scheduler state from schedule.json
 * - Compute create / update / delete operations via SchedulerDiff
 *
 * GUARANTEES:
 * - NEVER writes to the FPP scheduler
 * - NEVER mutates schedule.json
 * - Deterministic plan based on current inputs
 *
 * All side effects occur exclusively in the Apply layer.
 */
final class SchedulerPlanner
{
    /**
     * Maximum number of managed scheduler entries allowed.
     *
     * Planner-owned, deterministic, and intentionally not configurable.
     */
    private const MAX_MANAGED_ENTRIES = 100;

    /**
     * Compute a scheduler plan (diff) without side effects.
     *
     * @param array $config Loaded plugin configuration
     * @return array{
     *   ok?: bool,
     *   error?: array{ type: string, limit: int, attempted: int, guardDate: string },
     *   creates?: array,
     *   updates?: array,
     *   deletes?: array,
     *   desiredEntries?: array,
     *   desiredBundles?: array,
     *   existingRaw?: array
     * }
     */
    public static function plan(array $config): array
    {
        /* -----------------------------------------------------------------
         * 0. Fixed guard date (calendar-aligned, based on FPP system time)
         *
         * Guard date = Dec 31 of (currentYear + 2)
         *
         * Rules (Planner-owned):
         * - Entry is valid only if startDate < guardDate
         * - endDate is capped to guardDate if it exceeds it
         * ----------------------------------------------------------------- */
        $currentYear = (int)date('Y');
        $guardYear   = $currentYear + 2;
        $guardDate   = sprintf('%04d-12-31', $guardYear);

        /* -----------------------------------------------------------------
         * 1. Calendar ingestion → analysis (Runner)
         * ----------------------------------------------------------------- */
        $runner = new SchedulerRunner($config);
        $runnerResult = $runner->run();

        $series = (isset($runnerResult['series']) && is_array($runnerResult['series']))
            ? $runnerResult['series']
            : [];

        /* -----------------------------------------------------------------
         * 2. Build ScheduleBundles (Phase 28)
         *
         * Bundle shape (array form to minimize churn):
         *   ['overrides' => array<int, array{template: array, range: array}>, 'base' => array{template: array, range: array}]
         *
         * Planner is the semantic authority for:
         * - base schedule existence (NOT gated by occurrence expansion)
         * - override modeling (narrow entries placed directly above base)
         * - bundling adjacency
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

            $summary = (string)($s['summary'] ?? '');
            $resolved = (isset($s['resolved']) && is_array($s['resolved'])) ? $s['resolved'] : null;
            if (!$resolved || empty($resolved['type']) || !array_key_exists('target', $resolved)) {
                // Unresolved targets are skipped (Runner already traces this).
                continue;
            }

            // Base event is required to create a series-level schedule.
            $baseEv = (isset($s['base']) && is_array($s['base'])) ? $s['base'] : null;
            if (!$baseEv || empty($baseEv['start']) || empty($baseEv['end'])) {
                continue;
            }

            // Compute base start/end timestamps from DTSTART/DTEND (series-level, not occurrence-gated)
            try {
                $baseStartDT = new DateTime((string)$baseEv['start']);
                $baseEndDT   = new DateTime((string)$baseEv['end']);
            } catch (\Throwable $e) {
                continue;
            }

            // Series start date must anchor to original DTSTART date when available
            $seriesStartDate = substr($baseStartDT->format('c'), 0, 10);

            // Series end date: prefer UNTIL if present, else guardDate (Planner will cap endDate anyway)
            $seriesEndDate = self::pickSeriesEndDateFromRrule($baseEv, $guardDate) ?? $guardDate;

            // Day mask: derive from RRULE when possible, fallback to DTSTART weekday
            $daysShort = self::deriveDaysShortFromBase($baseEv, $baseStartDT);

            // YAML (stable): Runner provides parsed YAML signature info; pick a representative YAML blob if available
            $baseYaml = (isset($s['yamlBase']) && is_array($s['yamlBase'])) ? $s['yamlBase'] : [];

            $baseEff = self::applyYamlToTemplate(
                ['stopType' => 'graceful', 'repeat' => 'immediate'],
                $baseYaml
            );

            // Build BASE intent (template + range)
            $baseIntent = [
                'uid' => $uid,
                'template' => [
                    'uid'       => $uid,
                    'summary'   => $summary,
                    'type'      => (string)$resolved['type'],
                    'target'    => $resolved['target'],
                    'start'     => $baseStartDT->format('Y-m-d H:i:s'),
                    'end'       => $baseEndDT->format('Y-m-d H:i:s'),
                    'stopType'  => $baseEff['stopType'],
                    'repeat'    => $baseEff['repeat'],
                    'isOverride'=> false,
                ],
                'range' => [
                    'start' => $seriesStartDate,
                    'end'   => $seriesEndDate,
                    'days'  => $daysShort,
                ],
            ];

            // OVERRIDES: Runner provides override occurrences (bounded) with per-occ YAML already parsed
            $overrideIntents = [];
            $overrideOccs = (isset($s['overrideOccs']) && is_array($s['overrideOccs'])) ? $s['overrideOccs'] : [];

            // Sort overrides chronologically (important for intuitive UI)
            usort($overrideOccs, static function ($a, $b): int {
                $as = is_array($a) ? (string)($a['start'] ?? '') : '';
                $bs = is_array($b) ? (string)($b['start'] ?? '') : '';
                return strcmp($as, $bs);
            });

            foreach ($overrideOccs as $ov) {
                if (!is_array($ov) || empty($ov['start']) || empty($ov['end'])) {
                    continue;
                }

                try {
                    $ovStartDT = new DateTime((string)$ov['start']);
                    $ovEndDT   = new DateTime((string)$ov['end']);
                } catch (\Throwable $e) {
                    continue;
                }

                $ovDate = substr($ovStartDT->format('c'), 0, 10);

                // Override YAML (per-occ)
                $yaml = (isset($ov['yaml']) && is_array($ov['yaml'])) ? $ov['yaml'] : [];
                $eff  = self::applyYamlToTemplate(
                    ['stopType' => 'graceful', 'repeat' => 'immediate'],
                    $yaml
                );

                // Override is a single-day entry: day mask should be "Everyday" per your rule-of-thumb for one-day schedules.
                $overrideIntents[] = [
                    'uid' => $uid,
                    'template' => [
                        'uid'       => $uid,
                        'summary'   => $summary,
                        'type'      => (string)$resolved['type'],
                        'target'    => $resolved['target'],
                        'start'     => $ovStartDT->format('Y-m-d H:i:s'),
                        'end'       => $ovEndDT->format('Y-m-d H:i:s'),
                        'stopType'  => $eff['stopType'],
                        'repeat'    => $eff['repeat'],
                        'isOverride'=> true,
                    ],
                    'range' => [
                        'start' => $ovDate,
                        'end'   => $ovDate,
                        'days'  => 'SuMoTuWeThFrSa',
                    ],
                ];
            }

            $bundles[] = [
                'overrides' => $overrideIntents,
                'base'      => $baseIntent,
            ];
        }

        /* -----------------------------------------------------------------
         * 3. Flatten bundles into desiredEntries (compatibility with existing Diff/Apply)
         *
         * Invariant:
         * - Overrides directly precede their base (bundle adjacency)
         *
         * PHASE 28 (FPP-native, mechanical ordering):
         * - PRIMARY: if two bundles do NOT overlap in DATE RANGE, order chronologically by date range
         * - ONLY IF date ranges overlap: apply FPP priority rule (later DAILY start time first)
         *
         * This ensures:
         * - Non-overlapping calendar entries appear in intuitive chronological order
         * - Overlapping entries are ordered to match FPP top-down precedence
         * ----------------------------------------------------------------- */
        usort($bundles, static function (array $a, array $b): int {
            $aBase = $a['base'] ?? null;
            $bBase = $b['base'] ?? null;

            if (!is_array($aBase) || !is_array($bBase)) {
                return 0;
            }

            $ar = $aBase['range'] ?? null;
            $br = $bBase['range'] ?? null;
            $at = $aBase['template'] ?? null;
            $bt = $bBase['template'] ?? null;

            if (!is_array($ar) || !is_array($br) || !is_array($at) || !is_array($bt)) {
                return 0;
            }

            $aStartDate = (string)($ar['start'] ?? '');
            $aEndDate   = (string)($ar['end'] ?? '');
            $bStartDate = (string)($br['start'] ?? '');
            $bEndDate   = (string)($br['end'] ?? '');

            // Defensive: if missing dates, fall back to template timestamp ordering
            $aTplStart = (string)($at['start'] ?? '');
            $bTplStart = (string)($bt['start'] ?? '');
            if ($aStartDate === '' || $aEndDate === '' || $bStartDate === '' || $bEndDate === '') {
                if ($aTplStart === '' || $bTplStart === '') {
                    return 0;
                }
                return strcmp($aTplStart, $bTplStart);
            }

            // -------------------------------------------------------------
            // PRIMARY: Non-overlapping DATE RANGES → pure chronological by date
            // (YYYY-MM-DD lexicographic compares correctly)
            // -------------------------------------------------------------
            if ($aEndDate < $bStartDate) {
                return -1; // A strictly before B
            }
            if ($bEndDate < $aStartDate) {
                return 1;  // B strictly before A
            }

            // -------------------------------------------------------------
            // DATE RANGES OVERLAP → apply full overlap model (days + times)
            // If they don't actually overlap on active days/times, keep chronological.
            // -------------------------------------------------------------
            if (!self::basesOverlap($aBase, $bBase)) {
                // Chronological within overlapping date spans (stable UI)
                if ($aStartDate !== $bStartDate) {
                    return strcmp($aStartDate, $bStartDate);
                }
                if ($aTplStart !== '' && $bTplStart !== '') {
                    return strcmp($aTplStart, $bTplStart);
                }
                return strcmp($aEndDate, $bEndDate);
            }

            // -------------------------------------------------------------
            // TRUE OVERLAP → FPP precedence:
            // later DAILY start time must be above (DESC)
            // -------------------------------------------------------------
            $aStartSec = self::timeToSeconds(substr((string)($at['start'] ?? ''), 11));
            $bStartSec = self::timeToSeconds(substr((string)($bt['start'] ?? ''), 11));

            if ($aStartSec !== $bStartSec) {
                return $bStartSec <=> $aStartSec; // DESC
            }

            // Tie-breaker: shorter daily window first (more specific)
            $aEndSec = self::timeToSeconds(substr((string)($at['end'] ?? ''), 11));
            $bEndSec = self::timeToSeconds(substr((string)($bt['end'] ?? ''), 11));

            $aDur = self::windowDurationSeconds($aStartSec, $aEndSec);
            $bDur = self::windowDurationSeconds($bStartSec, $bEndSec);

            if ($aDur !== $bDur) {
                return $aDur <=> $bDur; // ASC
            }

            // Next: earlier date-range start first (readability)
            if ($aStartDate !== $bStartDate) {
                return strcmp($aStartDate, $bStartDate);
            }

            // Final stable tie-breakers
            $aUid = (string)($aBase['uid'] ?? '');
            $bUid = (string)($bBase['uid'] ?? '');
            if ($aUid !== '' && $bUid !== '' && $aUid !== $bUid) {
                return strcmp($aUid, $bUid);
            }

            // Fall back to template timestamp
            if ($aTplStart !== '' && $bTplStart !== '' && $aTplStart !== $bTplStart) {
                return strcmp($aTplStart, $bTplStart);
            }

            return 0;
        });

        $desiredIntents = [];
        foreach ($bundles as $bundle) {
            foreach (($bundle['overrides'] ?? []) as $ovIntent) {
                if (is_array($ovIntent)) {
                    $desiredIntents[] = $ovIntent;
                }
            }
            $base = $bundle['base'] ?? null;
            if (is_array($base)) {
                $desiredIntents[] = $base;
            }
        }

        // Map intents → schedule entries (existing mapper)
        $desiredEntries = [];
        foreach ($desiredIntents as $intent) {
            $entry = SchedulerSync::intentToScheduleEntryPublic($intent);
            if (!is_array($entry)) {
                continue;
            }

            // Planner-owned guard enforcement
            $guarded = self::applyGuardRulesToEntry($entry, $guardDate);
            if ($guarded === null) {
                continue;
            }

            $desiredEntries[] = $guarded;
        }

        /* -----------------------------------------------------------------
         * 4. Global managed entry cap (hard fail; no partial scheduling)
         * ----------------------------------------------------------------- */
        $attempted = count($desiredEntries);
        if ($attempted > self::MAX_MANAGED_ENTRIES) {
            return [
                'ok' => false,
                'error' => [
                    'type'      => 'scheduler_entry_limit_exceeded',
                    'limit'     => self::MAX_MANAGED_ENTRIES,
                    'attempted' => $attempted,
                    'guardDate' => $guardDate,
                ],
            ];
        }

        /* -----------------------------------------------------------------
         * 5. Load existing scheduler state (raw + wrapped)
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

        /* -----------------------------------------------------------------
         * 6. Compute diff (unchanged)
         * ----------------------------------------------------------------- */
        $diff = (new SchedulerDiff($desiredEntries, $state))->compute();

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

    /**
     * Apply guard rules to a single schedule entry.
     *
     * @param array $entry
     * @param string $guardDate YYYY-MM-DD
     * @return array|null
     */
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

    /**
     * Derive compact days string from RRULE or DTSTART weekday.
     */
    private static function deriveDaysShortFromBase(array $baseEv, DateTime $dtStart): string
    {
        $rrule = $baseEv['rrule'] ?? null;
        if (is_array($rrule)) {
            $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
            if ($freq === 'DAILY') {
                return 'SuMoTuWeThFrSa';
            }
            if ($freq === 'WEEKLY') {
                $byday = (string)($rrule['BYDAY'] ?? '');
                $days = self::shortDaysFromByDay($byday);
                if ($days !== '') {
                    return $days;
                }
            }
        }

        // Fallback to DTSTART weekday
        return self::dowToShortDay((int)$dtStart->format('w'));
    }

    /**
     * Prefer series end date from RRULE UNTIL when available; otherwise null.
     * Planner will use guardDate for open-ended series.
     */
    private static function pickSeriesEndDateFromRrule(array $baseEv, string $guardDate): ?string
    {
        $rrule = $baseEv['rrule'] ?? null;
        if (!is_array($rrule)) {
            return null;
        }

        if (!empty($rrule['UNTIL'])) {
            $until = (string)$rrule['UNTIL'];
            // Accept YYYYMMDD or YYYYMMDDT... variants; normalize to YYYY-MM-DD
            $ymd = null;
            if (preg_match('/^\d{8}$/', $until)) {
                $ymd = substr($until, 0, 4) . '-' . substr($until, 4, 2) . '-' . substr($until, 6, 2);
            } elseif (preg_match('/^(\d{8})T/', $until, $m)) {
                $raw = $m[1];
                $ymd = substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);
            }
            if ($ymd !== null && self::isValidYmd($ymd)) {
                // Cap to guardDate if needed (planner guard will also cap schedule entry)
                return ($ymd > $guardDate) ? $guardDate : $ymd;
            }
        }

        return null;
    }

    private static function applyYamlToTemplate(array $defaults, array $yaml): array
    {
        $out = $defaults;
        if (isset($yaml['stopType'])) {
            $out['stopType'] = strtolower((string)$yaml['stopType']);
        }
        if (isset($yaml['repeat'])) {
            $out['repeat'] = is_numeric($yaml['repeat']) ? (int)$yaml['repeat'] : strtolower((string)$yaml['repeat']);
        }
        return $out;
    }

    private static function dowToShortDay(int $dow): string
    {
        return match ($dow) {
            0 => 'Su',
            1 => 'Mo',
            2 => 'Tu',
            3 => 'We',
            4 => 'Th',
            5 => 'Fr',
            6 => 'Sa',
            default => '',
        };
    }

    private static function shortDaysFromByDay(string $bydayRaw): string
    {
        $bydayRaw = strtoupper(trim($bydayRaw));
        if ($bydayRaw === '') return '';

        $tokens = explode(',', $bydayRaw);
        $present = [
            'SU' => false, 'MO' => false, 'TU' => false, 'WE' => false,
            'TH' => false, 'FR' => false, 'SA' => false,
        ];

        foreach ($tokens as $tok) {
            $tok = preg_replace('/^[+-]?\d+/', '', trim($tok));
            if (isset($present[$tok])) {
                $present[$tok] = true;
            }
        }

        $out = '';
        if ($present['SU']) $out .= 'Su';
        if ($present['MO']) $out .= 'Mo';
        if ($present['TU']) $out .= 'Tu';
        if ($present['WE']) $out .= 'We';
        if ($present['TH']) $out .= 'Th';
        if ($present['FR']) $out .= 'Fr';
        if ($present['SA']) $out .= 'Sa';
        return $out;
    }

    private static function isValidYmd(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $s);
        return ($dt instanceof DateTime) && ($dt->format('Y-m-d') === $s);
    }

    /* ---------------------------------------------------------------------
     * Phase 28: overlap helpers (Planner-only, no I/O)
     *
     * Overlap definition (base intents):
     * - date ranges intersect (range.start..range.end)
     * - day masks intersect (range.days)
     * - daily time windows intersect (template.start/end time-of-day)
     *
     * Notes:
     * - Treat endTime <= startTime as "wrap past midnight" for overlap math.
     *   This matches common FPP usage like 07:00 → 00:00 (runs until midnight).
     * ------------------------------------------------------------------ */

    private static function basesOverlap(array $baseA, array $baseB): bool
    {
        $ra = $baseA['range'] ?? null;
        $rb = $baseB['range'] ?? null;
        $ta = $baseA['template'] ?? null;
        $tb = $baseB['template'] ?? null;

        if (!is_array($ra) || !is_array($rb) || !is_array($ta) || !is_array($tb)) {
            return false;
        }

        $aStartDate = (string)($ra['start'] ?? '');
        $aEndDate   = (string)($ra['end'] ?? '');
        $bStartDate = (string)($rb['start'] ?? '');
        $bEndDate   = (string)($rb['end'] ?? '');

        if ($aStartDate === '' || $aEndDate === '' || $bStartDate === '' || $bEndDate === '') {
            return false;
        }

        // Date range intersection (lexicographic safe for YYYY-MM-DD)
        if ($aEndDate < $bStartDate || $bEndDate < $aStartDate) {
            return false;
        }

        // Days overlap
        $aDays = (string)($ra['days'] ?? '');
        $bDays = (string)($rb['days'] ?? '');
        if (!self::daysOverlapShort($aDays, $bDays)) {
            return false;
        }

        // Time window overlap (time-of-day)
        $aStartTs = (string)($ta['start'] ?? '');
        $aEndTs   = (string)($ta['end'] ?? '');
        $bStartTs = (string)($tb['start'] ?? '');
        $bEndTs   = (string)($tb['end'] ?? '');

        if ($aStartTs === '' || $aEndTs === '' || $bStartTs === '' || $bEndTs === '') {
            return false;
        }

        $aStart = self::timeToSeconds(substr($aStartTs, 11));
        $aEnd   = self::timeToSeconds(substr($aEndTs, 11));
        $bStart = self::timeToSeconds(substr($bStartTs, 11));
        $bEnd   = self::timeToSeconds(substr($bEndTs, 11));

        return self::timeWindowsOverlapSeconds($aStart, $aEnd, $bStart, $bEnd);
    }

    private static function daysOverlapShort(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        // Days strings are compact two-letter chunks (SuMoTuWeThFrSa)
        $len = strlen($a);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $chunk = substr($a, $i, 2);
            if ($chunk !== '' && strpos($b, $chunk) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function timeToSeconds(string $hhmmss): int
    {
        $t = trim($hhmmss);
        if ($t === '') return 0;

        // Accept HH:MM or HH:MM:SS
        if (preg_match('/^\d{2}:\d{2}$/', $t)) {
            $t .= ':00';
        }

        if (!preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $t, $m)) {
            return 0;
        }

        $h = (int)$m[1];
        $i = (int)$m[2];
        $s = (int)$m[3];

        return ($h * 3600) + ($i * 60) + $s;
    }

    private static function windowDurationSeconds(int $start, int $end): int
    {
        $e = $end;
        if ($e <= $start) {
            $e += 86400; // wrap past midnight
        }
        return max(0, $e - $start);
    }

    private static function timeWindowsOverlapSeconds(int $startA, int $endA, int $startB, int $endB): bool
    {
        $aStart = $startA;
        $aEnd   = $endA;
        $bStart = $startB;
        $bEnd   = $endB;

        // Normalize wrap windows to [start, end+86400] if needed
        if ($aEnd <= $aStart) $aEnd += 86400;
        if ($bEnd <= $bStart) $bEnd += 86400;

        // Overlap test on the same day-axis
        return !($aEnd <= $bStart || $bEnd <= $aStart);
    }
}
