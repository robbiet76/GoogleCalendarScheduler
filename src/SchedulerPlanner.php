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
     * @return array{creates:array,updates:array,deletes:array,desiredEntries:array,existingRaw:array}
     */
    public static function plan(array $config): array
    {
        // 1. Calendar ingestion → intents
        $runner = new GcsSchedulerRunner(
            $config,
            GcsFppSchedulerHorizon::getDays()
        );

        $runnerResult = $runner->run();

        // 2. Desired schedule entries
        $desired = [];

        if (!empty($runnerResult['intents']) && is_array($runnerResult['intents'])) {
            foreach ($runnerResult['intents'] as $intent) {
                $entry = SchedulerSync::intentToScheduleEntryPublic($intent);
                if (is_array($entry)) {
                    $desired[] = $entry;
                }
            }
        }

        // 3. Load existing schedule.json
        $existingRaw = SchedulerSync::readScheduleJsonStatic(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $existingEntries = [];
        foreach ($existingRaw as $row) {
            if (is_array($row)) {
                $existingEntries[] = new GcsExistingScheduleEntry($row);
            }
        }

        // 4. Immutable scheduler state
        $state = new GcsSchedulerState($existingEntries);

        // 5. Compute diff
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
