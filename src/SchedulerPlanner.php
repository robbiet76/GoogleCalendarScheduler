<?php
declare(strict_types=1);

/**
 * SchedulerPlanner
 *
 * Planning-only entry point for scheduler diffs.
 *
 * Responsibilities:
 * - Fetch Google Calendar
 * - Resolve desired scheduler entries
 * - Load existing scheduler state
 * - Compute create/update/delete diff
 *
 * GUARANTEES:
 * - NEVER writes to FPP scheduler
 */
final class SchedulerPlanner
{
    /**
     * Compute a scheduler plan (diff) without side effects.
     *
     * @param array $config
     * @return array{creates:array,updates:array,deletes:array}
     */
    public static function plan(array $config): array
    {
        // 1. Build desired entries (dry-run runner)
        $runner = new GcsSchedulerRunner(
            $config,
            GcsFppSchedulerHorizon::getDays(),
            true // forced dry-run
        );

        $desiredResult = $runner->run();

        // Desired scheduler entries must be explicitly returned by runner
        $desired = [];
        if (isset($desiredResult['entries']) && is_array($desiredResult['entries'])) {
            $desired = $desiredResult['entries'];
        }

        // 2. Load existing scheduler entries
        $existingRaw = SchedulerSync::readScheduleJsonStatic(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $existing = [];
        foreach ($existingRaw as $row) {
            if (is_array($row)) {
                $existing[] = new GcsExistingScheduleEntry($row);
            }
        }

        // 3. Compute diff
        $state = new GcsSchedulerState($existing);
        $diff  = new GcsSchedulerDiff($desired, $state);
        $res   = $diff->compute();

        // 4. Normalize diff for UI
        return [
            'creates' => $res->creates(),
            'updates' => $res->updates(),
            'deletes' => $res->deletes(),
        ];
    }
}
