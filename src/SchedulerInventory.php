<?php
declare(strict_types=1);

/**
 * SchedulerInventory
 *
 * Phase 23.1
 *
 * Read-only helper that summarizes scheduler ownership state.
 *
 * Responsibilities:
 * - Read schedule.json safely
 * - Classify entries as managed vs unmanaged
 * - Provide counts for UI, export, and cleanup gating
 *
 * HARD RULES:
 * - Ownership is determined ONLY by presence of a valid |GCS:v1| tag
 * - No heuristics
 * - No mutation
 * - Never throws
 *
 * NOTE:
 * Uses legacy-named GcsSchedulerIdentity for ownership detection.
 * Naming normalization is planned in a future refactor phase.
 */
final class SchedulerInventory
{
    /**
     * Summarize scheduler ownership.
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
                'ok' => false,
                'managed_count' => 0,
                'unmanaged_count' => 0,
                'invalid_count' => 0,
                'errors' => [
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
            'ok' => true,
            'managed_count' => $managed,
            'unmanaged_count' => $unmanaged,
            'invalid_count' => $invalid,
            'errors' => $errors,
        ];
    }
}
