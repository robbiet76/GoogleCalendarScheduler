<?php
declare(strict_types=1);

/**
 * SchedulerPlanner
 *
 * Pure planner:
 * - Calendar ingestion -> intents
 * - Intents -> desired scheduler entries
 * - Load existing schedule.json
 * - Compute diff
 *
 * NEVER writes; NEVER uses dryRun.
 */
final class SchedulerPlanner
{
    /**
     * @param array $config
     * @return array<string,mixed>
     */
    public static function plan(array $config): array
    {
        // 1) Ingest calendar -> intents
        $runner = new GcsSchedulerRunner(
            $config,
            GcsFppSchedulerHorizon::getDays()
        );
        $runnerResult = $runner->run();

        $intents = (isset($runnerResult['intents']) && is_array($runnerResult['intents']))
            ? $runnerResult['intents']
            : [];

        // 2) Map intents -> desired scheduler entries
        $desiredEntries = [];
        $errors = [];

        foreach ($intents as $idx => $intent) {
            if (!is_array($intent)) {
                $errors[] = "Intent #{$idx} is not an array";
                continue;
            }

            $entryOrError = SchedulerSync::intentToScheduleEntryPublic($intent);
            if (is_string($entryOrError)) {
                $errors[] = "Intent #{$idx}: {$entryOrError}";
                continue;
            }

            $desiredEntries[] = $entryOrError;
        }

        // 3) Load existing schedule.json (raw + wrappers)
        $existingRaw = SchedulerSync::readScheduleJsonStatic(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $existing = [];
        foreach ($existingRaw as $row) {
            if (is_array($row)) {
                $existing[] = new GcsExistingScheduleEntry($row);
            }
        }

        // 4) Compute diff
        $state = new GcsSchedulerState($existing);
        $diff  = new GcsSchedulerDiff($desiredEntries, $state);
        $res   = $diff->compute();

        // Preserve UI response shape (top-level creates/updates/deletes)
        return [
            'ok' => empty($errors),
            'errors' => $errors,

            'creates' => $res->creates(),
            'updates' => $res->updates(),
            'deletes' => $res->deletes(),

            // Expose for apply path
            'desiredEntries' => $desiredEntries,
            'existingRaw' => $existingRaw,
        ];
    }
}
