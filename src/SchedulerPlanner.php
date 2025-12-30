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
     * Compute a scheduler plan (diff) without side effects.
     *
     * The returned structure is used by:
     * - Preview UI (diff visualization)
     * - Apply pipeline (execution boundary)
     *
     * @param array $config Loaded plugin configuration
     * @return array{
     *   creates: array,
     *   updates: array,
     *   deletes: array,
     *   desiredEntries: array,
     *   existingRaw: array
     * }
     */
    public static function plan(array $config): array
    {
        /* -----------------------------------------------------------------
         * 1. Calendar ingestion → scheduling intents
         * ----------------------------------------------------------------- */
        $runner = new GcsSchedulerRunner(
            $config,
            GcsFppSchedulerHorizon::getDays()
        );

        $runnerResult = $runner->run();

        /* -----------------------------------------------------------------
         * 2. Desired scheduler entries (intent → entry mapping)
         * ----------------------------------------------------------------- */
        $desired = [];

        if (!empty($runnerResult['intents']) && is_array($runnerResult['intents'])) {
            foreach ($runnerResult['intents'] as $intent) {
                $entry = SchedulerSync::intentToScheduleEntryPublic($intent);
                if (is_array($entry)) {
                    $desired[] = $entry;
                }
            }
        }

        /* -----------------------------------------------------------------
         * 3. Load existing scheduler state
         * ----------------------------------------------------------------- */
        $existingRaw = SchedulerSync::readScheduleJsonStatic(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $existingEntries = [];
        foreach ($existingRaw as $row) {
            if (is_array($row)) {
                $existingEntries[] = new GcsExistingScheduleEntry($row);
            }
        }

        /* -----------------------------------------------------------------
         * 4. Immutable scheduler state
         * ----------------------------------------------------------------- */
        $state = new GcsSchedulerState($existingEntries);

        /* -----------------------------------------------------------------
         * 5. Compute diff
         * ----------------------------------------------------------------- */
        $diff = (new GcsSchedulerDiff($desired, $state))->compute();

        return [
            'creates'        => $diff->creates(),
            'updates'        => $diff->updates(),
            'deletes'        => $diff->deletes(),
            'desiredEntries' => $desired,
            'existingRaw'    => $existingRaw,
        ];
    }
}
