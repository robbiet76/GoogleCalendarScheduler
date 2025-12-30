<?php
declare(strict_types=1);

/**
 * SchedulerInventory
 *
 * Read-only helper that summarizes scheduler ownership state.
 *
 * Responsibilities:
 * - Load schedule.json safely
 * - Classify entries as GCS-managed or unmanaged
 * - Provide aggregate counts for UI display, export, and cleanup gating
 *
 * HARD GUARANTEES:
 * - Ownership is determined solely by presence of a valid |GCS:v1| tag
 * - No heuristics or inference
 * - No mutation of scheduler data
 * - Never throws
 *
 * This class performs no policy decisions; it only reports facts.
 */
final class SchedulerInventory
{
    /**
     * Summarize scheduler ownership state.
     *
     * @param string $path Path to schedule.json
     * @return array{
     *   ok: bool,
     *   managed_count: int,
     *   unmanaged_count: int,
     *   invalid_count: int,
     *   errors: string[]
     * }
     */
    public static function summarize(
        string $path = SchedulerSync::SCHEDULE_JSON_PATH
    ): array {
        $managed = 0;
        $unmanaged = 0;
        $invalid = 0;
        $errors = [];

        try {
            $entries = SchedulerSync::readScheduleJsonStatic($path);
        } catch (Throwable $e) {
            return [
                'ok'              => false,
                'managed_count'   => 0,
                'unmanaged_count' => 0,
                'invalid_count'   => 0,
                'errors'          => [
                    'Failed to read schedule.json: ' . $e->getMessage(),
                ],
            ];
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                $invalid++;
                continue;
            }

            if (GcsSchedulerIdentity::isGcsManaged($entry)) {
                $managed++;
            } else {
                $unmanaged++;
            }
        }

        return [
            'ok'              => true,
            'managed_count'   => $managed,
            'unmanaged_count' => $unmanaged,
            'invalid_count'   => $invalid,
            'errors'          => $errors,
        ];
    }
}
