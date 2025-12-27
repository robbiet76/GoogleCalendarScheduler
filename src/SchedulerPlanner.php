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
     * @return array<string,mixed>
     */
    public static function plan(array $config): array
    {
        $errors = [];

        // 1. Build desired entries (calendar ingest + intent pipeline)
        $runner = new GcsSchedulerRunner(
            $config,
            GcsFppSchedulerHorizon::getDays(),
            true // runner never writes; apply layer decides
        );

        $desiredResult = $runner->run();

        $desiredEntries = [];
        if (isset($desiredResult['entries']) && is_array($desiredResult['entries'])) {
            $desiredEntries = $desiredResult['entries'];
        }

        // 2. Load existing scheduler entries (raw)
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
        $diff  = new GcsSchedulerDiff($desiredEntries, $state);
        $res   = $diff->compute();

        // 4. Normalize deletes for UI (no objects in JSON)
        $deletesUi = [];
        foreach ($res->deletes() as $d) {
            if ($d instanceof GcsExistingScheduleEntry) {
                $deletesUi[] = $d->toPreviewArray();
            }
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,

            'creates' => $res->creates(),
            'updates' => $res->updates(),
            'deletes' => $deletesUi,

            // Debug / inspection only (Phase 18-safe)
            'desiredEntries' => $desiredEntries,
            'existingRaw'    => $existingRaw,
        ];
    }
}
