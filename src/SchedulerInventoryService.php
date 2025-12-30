<?php
declare(strict_types=1);

/**
 * SchedulerInventoryService
 *
 * Phase 23.3
 *
 * Read-only inventory of scheduler.json.
 *
 * Responsibilities:
 * - Count total scheduler entries
 * - Count managed vs unmanaged entries
 * - Count disabled unmanaged entries
 *
 * HARD RULES:
 * - Never mutates scheduler.json
 * - Never infers ownership beyond GCS identity tag
 * - No UI knowledge
 */
final class SchedulerInventoryService
{
    /**
     * Get scheduler inventory counts.
     *
     * @return array{
     *   total: int,
     *   managed: int,
     *   unmanaged: int,
     *   unmanaged_disabled: int
     * }
     */
    public static function getInventory(): array
    {
        $entries = SchedulerSync::readScheduleJsonStatic(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $total = 0;
        $managed = 0;
        $unmanaged = 0;
        $unmanagedDisabled = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $total++;

            if (GcsSchedulerIdentity::isGcsManaged($entry)) {
                $managed++;
                continue;
            }

            // Unmanaged
            $unmanaged++;

            if (isset($entry['enabled']) && (int)$entry['enabled'] === 0) {
                $unmanagedDisabled++;
            }
        }

        return [
            'total'              => $total,
            'managed'            => $managed,
            'unmanaged'          => $unmanaged,
            'unmanaged_disabled' => $unmanagedDisabled,
        ];
    }
}
