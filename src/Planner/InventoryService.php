<?php
declare(strict_types=1);

/**
 * 
 *
 * Provides a read-only inventory summary of FPP scheduler entries.
 *
 * Responsibilities:
 * - Count total scheduler entries
 * - Distinguish GCS-managed vs unmanaged entries
 * - Track disabled unmanaged entries for visibility
 *
 * Guarantees:
 * - Never mutates scheduler.json
 * - Never infers ownership beyond the GCS identity tag
 * - Contains no planner, diff, or apply logic
 *
 * This service is intended for informational and UI status purposes only.
 */
final class InventoryService
{
    /**
     * Get a summary inventory of scheduler entries.
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

            if (SchedulerIdentity::isGcsManaged($entry)) {
                $managed++;
                continue;
            }

            // Unmanaged scheduler entry (non-GCS)
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
