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
     * IMPORTANT: Preview is always forced to dry-run.
     *
     * Return shape is normalized for the UI:
     *   ['creates' => array, 'updates' => array, 'deletes' => array]
     *
     * @param array $config Loaded plugin configuration
     * @return array Normalized diff arrays
     */
    public static function preview(array $config): array
    {
        // Force dry-run for preview safety
        $dryRun = true;

        $horizonDays = GcsFppSchedulerHorizon::getDays();
        $runner = new GcsSchedulerRunner($config, $horizonDays, $dryRun);
        $result = $runner->run();

        $diff = $result['diff'] ?? [];

        // Runner diff keys are expected to be: create/update/delete
        $create = (isset($diff['create']) && is_array($diff['create'])) ? $diff['create'] : [];
        $update = (isset($diff['update']) && is_array($diff['update'])) ? $diff['update'] : [];
        $delete = (isset($diff['delete']) && is_array($diff['delete'])) ? $diff['delete'] : [];

        // Normalize to UI-friendly keys: creates/updates/deletes
        return [
            'creates' => $create,
            'updates' => $update,
            'deletes' => $delete,
        ];
    }

    /**
     * Apply scheduler changes using the real pipeline.
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
