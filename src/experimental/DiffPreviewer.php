<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 *
 * Preview and (optionally) apply scheduler diffs using the real pipeline.
 *
 * IMPORTANT:
 * - Apply is protected by a triple guard
 * - No execution unless explicitly invoked
 * - No side effects unless all guards pass
 */
final class DiffPreviewer
{
    /**
     * Compute a diff preview using the scheduler pipeline.
     *
     * @param array $config Loaded plugin configuration
     * @return array Summary counts: ['create' => int, 'update' => int, 'delete' => int]
     */
    public static function preview(array $config): array
    {
        // Force dry-run for preview safety
        $dryRun = true;

        $horizonDays = GcsFppSchedulerHorizon::getDays();
        $runner = new GcsSchedulerRunner($config, $horizonDays, $dryRun);
        $result = $runner->run();

        $diff = $result['diff'] ?? [];

        return [
            'create' => isset($diff['create']) ? count($diff['create']) : 0,
            'update' => isset($diff['update']) ? count($diff['update']) : 0,
            'delete' => isset($diff['delete']) ? count($diff['delete']) : 0,
        ];
    }

    /**
     * Apply scheduler changes using the real pipeline.
     *
     * This method is intentionally NOT wired to any endpoint yet.
     *
     * @param array $config Loaded plugin configuration
     * @return array Result summary from SchedulerRunner
     *
     * @throws RuntimeException if any guard condition fails
     */
    public static function apply(array $config): array
    {
        // -------------------------
        // Triple guard enforcement
        // -------------------------
        if (empty($config['experimental']['enabled'])) {
            throw new RuntimeException('Experimental mode is not enabled');
        }

        if (empty($config['experimental']['allow_apply'])) {
            throw new RuntimeException('Experimental apply is not allowed');
        }

        if (!empty($config['runtime']['dry_run'])) {
            throw new RuntimeException('Apply blocked while dry-run is enabled');
        }

        // -------------------------
        // Execute real apply
        // -------------------------
        $dryRun = false;
        $horizonDays = GcsFppSchedulerHorizon::getDays();

        $runner = new GcsSchedulerRunner($config, $horizonDays, $dryRun);
        return $runner->run();
    }
}
