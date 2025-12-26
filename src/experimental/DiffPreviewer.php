<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 *
 * UI adapter for scheduler planning and apply.
 *
 * RULES:
 * - Preview ALWAYS uses SchedulerPlanner (plan-only)
 * - Apply is the ONLY place allowed to execute writes
 */
final class DiffPreviewer
{
    /**
     * Normalize any scheduler result into UI-friendly arrays.
     *
     * @internal Used by planner + apply
     */
    public static function normalizeResultForUi(array $result): array
    {
        $creates = [];
        $updates = [];
        $deletes = [];

        if (isset($result['diff']) && is_array($result['diff'])) {
            $creates = $result['diff']['creates'] ?? $result['diff']['create'] ?? [];
            $updates = $result['diff']['updates'] ?? $result['diff']['update'] ?? [];
            $deletes = $result['diff']['deletes'] ?? $result['diff']['delete'] ?? [];
        }

        // Summary-only fallback
        if (empty($creates) && isset($result['adds'])) {
            $creates = array_fill(0, (int)$result['adds'], '(create)');
        }
        if (empty($updates) && isset($result['updates']) && is_numeric($result['updates'])) {
            $updates = array_fill(0, (int)$result['updates'], '(update)');
        }
        if (empty($deletes) && isset($result['deletes']) && is_numeric($result['deletes'])) {
            $deletes = array_fill(0, (int)$result['deletes'], '(delete)');
        }

        return [
            'creates' => is_array($creates) ? $creates : [],
            'updates' => is_array($updates) ? $updates : [],
            'deletes' => is_array($deletes) ? $deletes : [],
        ];
    }

    /**
     * Preview scheduler changes (PLAN ONLY).
     *
     * @param array $config
     */
    public static function preview(array $config): array
    {
        return SchedulerPlanner::plan($config);
    }

    /**
     * Apply scheduler changes (EXECUTION PATH).
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

        $runner = new GcsSchedulerRunner(
            $config,
            GcsFppSchedulerHorizon::getDays(),
            false
        );

        return $runner->run();
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
}
