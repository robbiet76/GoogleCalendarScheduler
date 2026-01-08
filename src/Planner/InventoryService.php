<?php
declare(strict_types=1);

/**
 * InventoryService
 *
 * Provides a read-only inventory summary of FPP scheduler entries.
 *
 * Responsibilities:
 * - Count total scheduler entries
 * - Distinguish GCS-managed vs unmanaged entries
 * - Track disabled unmanaged entries for visibility
 * - Provide raw unmanaged entries for export
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

        // 🔍 TEMP DEBUG — REMOVE AFTER VALIDATION
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $managed = SchedulerIdentity::isGcsManaged($entry);

            error_log(sprintf(
                '[GCS DEBUG][Inventory] #%d playlist=%s managed=%s args=%s',
                $i,
                $entry['playlist'] ?? '(none)',
                $managed ? 'YES' : 'NO',
                json_encode($entry['args'] ?? null)
            ));
        }

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

    /**
     * Return raw unmanaged scheduler entries.
     *
     * These are entries present in scheduler.json that are NOT
     * managed by GoogleCalendarScheduler.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getUnmanagedEntries(): array
    {
        $entries = SchedulerSync::readScheduleJsonStatic(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $unmanaged = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (SchedulerIdentity::isGcsManaged($entry)) {
                continue;
            }

            $unmanaged[] = $entry;
        }

        return $unmanaged;
    }
}