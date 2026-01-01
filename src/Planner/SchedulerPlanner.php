<?php
declare(strict_types=1);

/**
 * SchedulerPlanner
 *
 * Planning-only orchestration layer for scheduler diffs.
 *
 * Responsibilities:
 * - Ingest calendar data and resolve scheduling intents
 * - Translate intents into desired FPP scheduler entries
 * - Load existing scheduler state from schedule.json
 * - Compute create / update / delete operations
 *
 * GUARANTEES:
 * - NEVER writes to the FPP scheduler
 * - NEVER mutates schedule.json
 * - Produces a deterministic plan based on current inputs
 *
 * All side effects (writes, backups, verification) occur
 * exclusively in the Apply layer.
 */
final class SchedulerPlanner
{
    /**
     * Maximum number of managed scheduler entries allowed.
     *
     * Planner-owned, deterministic, and intentionally not configurable.
     * This bounds materialized scheduler size for release stability.
     */
    private const MAX_MANAGED_ENTRIES = 100;

    /**
     * Compute a scheduler plan (diff) without side effects.
     *
     * The returned structure is used by:
     * - Preview UI (diff visualization)
     * - Apply pipeline (execution boundary)
     *
     * On capacity violation, returns:
     * - ok=false
     * - error payload with limit + attempted count
     *
     * @param array $config Loaded plugin configuration
     * @return array{
     *   ok?: bool,
     *   error?: array{
     *     type: string,
     *     limit: int,
     *     attempted: int,
     *     guardDate: string
     *   },
     *   creates?: array,
     *   updates?: array,
     *   deletes?: array,
     *   desiredEntries?: array,
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
         * Rules:
         * - Entry is valid only if startDate < guardDate
         * - endDate is capped to guardDate if it exceeds it
         * ----------------------------------------------------------------- */
        $currentYear = (int)date('Y');
        $guardYear   = $currentYear + 2;
        $guardDate   = sprintf('%04d-12-31', $guardYear);

        /* -----------------------------------------------------------------
         * 1. Calendar ingestion → scheduling intents
         * ----------------------------------------------------------------- */
        // Note: Runner is still passed a fixed planning days value for compatibility.
        // The Planner enforces the authoritative guard rules below.
        $runner = new SchedulerRunner(
            $config,
            365
        );

        $runnerResult = $runner->run();

        /* -----------------------------------------------------------------
         * 2. Desired scheduler entries (intent → entry mapping)
         *    + Planner-owned guard enforcement
         * ----------------------------------------------------------------- */
        $desired = [];

        if (!empty($runnerResult['intents']) && is_array($runnerResult['intents'])) {
            foreach ($runnerResult['intents'] as $intent) {
                $entry = SchedulerSync::intentToScheduleEntryPublic($intent);
                if (!is_array($entry)) {
                    continue;
                }

                // Start-date validity gate: valid only if startDate < guardDate
                $start = $entry['startDate'] ?? '';
                if (!is_string($start) || $start === '') {
                    // Defensive: malformed entry, skip
                    continue;
                }
                if ($start >= $guardDate) {
                    // Invalid entry (starts on/after guard) → do not materialize
                    continue;
                }

                // End-date cap: if endDate exceeds guardDate, cap it
                $end = $entry['endDate'] ?? '';
                if (is_string($end) && $end !== '' && $end > $guardDate) {
                    $entry['endDate'] = $guardDate;
                }

                $desired[] = $entry;
            }
        }

        /* -----------------------------------------------------------------
         * 3. Global managed entry cap (hard fail; no partial scheduling)
         * ----------------------------------------------------------------- */
        $attempted = count($desired);
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
         * 4. Load existing scheduler state
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

        /* -----------------------------------------------------------------
         * 5. Immutable scheduler state
         * ----------------------------------------------------------------- */
        $state = new SchedulerState($existingEntries);

        /* -----------------------------------------------------------------
         * 6. Compute diff
         * ----------------------------------------------------------------- */
        $diff = (new SchedulerDiff($desired, $state))->compute();

        return [
            'ok'             => true,
            'creates'        => $diff->creates(),
            'updates'        => $diff->updates(),
            'deletes'        => $diff->deletes(),
            'desiredEntries' => $desired,
            'existingRaw'    => $existingRaw,
        ];
    }
}
