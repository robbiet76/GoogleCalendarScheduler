<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 * 
 * UI-SCOPED FILE
 *
 * This file exists ONLY to support web UI preview/apply flows.
 * Core scheduler logic must not depend on this class.
 *
 * ROLE:
 * - Translates planner/apply output into UI-safe preview rows
 * - Enforces preview/apply parity
 *
 * HARD RULES:
 * - Preview ALWAYS uses SchedulerPlanner (plan-only)
 * - Apply is the ONLY place allowed to execute writes (via SchedulerApply)
 * - This class MUST NOT modify scheduler state directly
 */

final class DiffPreviewer
{
    /**
     * Normalize planner or apply results into UI-friendly rows.
     *
     * NOTES:
     * - Never returns objects
     * - Never mutates input
     * - Safe for JSON encoding
     *
     * @internal Used exclusively by UI preview/apply flows
     */
    public static function normalizeResultForUi(array $result): array
    {
        $creates = [];
        $updates = [];
        $deletes = [];

        if (isset($result['diff']) && is_array($result['diff'])) {
            $creates = self::normalizeCreateRows($result['diff']['creates'] ?? []);
            $updates = self::normalizeUpdateRows($result['diff']['updates'] ?? []);
            $deletes = self::normalizeDeleteRows($result['diff']['deletes'] ?? []);
        }

        // Summary-only fallback (legacy safety)
        if (empty($creates) && isset($result['adds']) && is_numeric($result['adds'])) {
            $creates = array_fill(0, (int)$result['adds'], ['type' => 'create']);
        }
        if (empty($updates) && isset($result['updates']) && is_numeric($result['updates'])) {
            $updates = array_fill(0, (int)$result['updates'], ['type' => 'update']);
        }
        if (empty($deletes) && isset($result['deletes']) && is_numeric($result['deletes'])) {
            $deletes = array_fill(0, (int)$result['deletes'], ['type' => 'delete']);
        }

        return [
            'creates' => $creates,
            'updates' => $updates,
            'deletes' => $deletes,
        ];
    }

    /**
     * Preview scheduler changes (PLAN ONLY).
     */
    public static function preview(array $config): array
    {
        return SchedulerPlanner::plan($config);
    }

    /**
     * Apply scheduler changes (EXECUTION PATH).
     *
     * IMPORTANT:
     * - Preview and Apply must operate on the same plan representation
     * - Writes MUST happen only via SchedulerApply
     * - MUST NOT be called from non-UI contexts
     *
     * @throws RuntimeException if blocked
     */
    public static function apply(array $config): array
    {
        if (empty($config['experimental']['enabled'])) {
            throw new RuntimeException('Experimental mode is not enabled');
        }

        if (empty($config['experimental']['allow_apply'])) {
            throw new RuntimeException('Experimental apply is not allowed');
        }

        if (!empty($config['runtime']['dry_run'])) {
            throw new RuntimeException('Apply blocked while dry-run is enabled');
        }

        // Use the same planner output shape that preview returns
        $plan = SchedulerPlanner::plan($config);

        // If planner reports no changes, do not write
        $creates = (isset($plan['creates']) && is_array($plan['creates'])) ? count($plan['creates']) : 0;
        $updates = (isset($plan['updates']) && is_array($plan['updates'])) ? count($plan['updates']) : 0;
        $deletes = (isset($plan['deletes']) && is_array($plan['deletes'])) ? count($plan['deletes']) : 0;

        if (($creates + $updates + $deletes) === 0) {
            return [
                'ok'   => true,
                'diff' => $plan,
                'noop' => true,
            ];
        }

        // Execute the only permitted write boundary
        $applyResult = SchedulerApply::applyFromConfig($config);

        return [
            'ok'    => true,
            'diff'  => $plan,
            'apply' => $applyResult,
        ];
    }

    /**
     * Extract counts for UI.
     */
    public static function countsFromResult(array $result): array
    {
        $norm = self::normalizeResultForUi($result);

        return [
            'creates' => count($norm['creates']),
            'updates' => count($norm['updates']),
            'deletes' => count($norm['deletes']),
        ];
    }

    /* -----------------------------------------------------------------
     * Normalization helpers
     * ----------------------------------------------------------------- */

    private static function normalizeCreateRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::normalizeEntryRow($row, 'create');
            }
        }
        return $out;
    }

    private static function normalizeUpdateRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row['desired']) && is_array($row['desired'])) {
                $out[] = self::normalizeEntryRow($row['desired'], 'update');
            }
        }
        return $out;
    }

    private static function normalizeDeleteRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof ExistingScheduleEntry) {
                $out[] = self::normalizeEntryRow($row->raw(), 'delete');
            } elseif (is_array($row)) {
                $out[] = self::normalizeEntryRow($row, 'delete');
            }
        }
        return $out;
    }

    /**
     * Normalize a scheduler entry into a single preview row.
     */
    private static function normalizeEntryRow(array $entry, string $type): array
    {
        $uid = SchedulerIdentity::extractKey($entry);

        return [
            'type'      => $type,
            'mode'      => !empty($entry['command']) ? 'command' : 'playlist',
            'target'    => !empty($entry['command']) ? $entry['command'] : $entry['playlist'],
            'startDate' => $entry['startDate'] ?? null,
            'endDate'   => $entry['endDate'] ?? null,
            'startTime' => $entry['startTime'] ?? null,
            'endTime'   => $entry['endTime'] ?? null,
            'uid'       => $uid,
        ];
    }
}
