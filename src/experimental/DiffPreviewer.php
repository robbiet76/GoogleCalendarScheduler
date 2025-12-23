<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 *
 * Read-only diff preview helper.
 *
 * TEMPORARY IMPLEMENTATION (Milestone 11.5 Step C):
 * - Computes diff summary only
 * - NO apply
 * - NO mutation
 */
final class DiffPreviewer
{
    /**
     * Preview a diff between desired schedule and current scheduler state.
     *
     * @param array $config Loaded plugin configuration
     * @return array Summary counts: ['create' => int, 'update' => int, 'delete' => int]
     */
    public static function preview(array $config): array
    {
        // Load current scheduler state (read-only)
        $state = GcsSchedulerState::load();

        // Build desired intent from calendar (read-only)
        $dryRun = true;
        $horizonDays = GcsFppSchedulerHorizon::getDays();

        $runner = new GcsSchedulerRunner($config, $horizonDays, $dryRun);
        $result = $runner->run();

        // Extract diff summary only
        $diff = $result['diff'] ?? [];

        return [
            'create' => count($diff['create'] ?? []),
            'update' => count($diff['update'] ?? []),
            'delete' => count($diff['delete'] ?? []),
        ];
    }
}
