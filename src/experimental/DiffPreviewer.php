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
     * Normalize a scheduler result into UI-friendly diff arrays:
     *   ['creates' => array, 'updates' => array, 'deletes' => array]
     *
     * Supports multiple schemas (Phase 11–15 evolution):
     * - result['diff']['create|update|delete'] arrays
     * - result['diff']['creates|updates|deletes'] arrays
     * - result['adds|updates|deletes'] numeric counts (summary-only)
     * - PREVIEW FALLBACK: intents_seen => creates (dry-run only)
     */
    private static function normalizeDiffFromResult(array $result): array
    {
        $creates = [];
        $updates = [];
        $deletes = [];

        // 1) Preferred: result['diff'] with singular keys
        if (isset($result['diff']) && is_array($result['diff'])) {
            $diff = $result['diff'];

            if (isset($diff['create']) && is_array($diff['create'])) {
                $creates = $diff['create'];
            }
            if (isset($diff['update']) && is_array($diff['update'])) {
                $updates = $diff['update'];
            }
            if (isset($diff['delete']) && is_array($diff['delete'])) {
                $deletes = $diff['delete'];
            }

            // 2) Alternate: plural keys
            if (empty($creates) && isset($diff['creates']) && is_array($diff['creates'])) {
                $creates = $diff['creates'];
            }
            if (empty($updates) && isset($diff['updates']) && is_array($diff['updates'])) {
                $updates = $diff['updates'];
            }
            if (empty($deletes) && isset($diff['deletes']) && is_array($diff['deletes'])) {
                $deletes = $diff['deletes'];
            }
        }

        // 3) Summary-only schema: adds/updates/deletes numeric counts
        $addsCount = null;
        $updCount  = null;
        $delCount  = null;

        if (isset($result['adds']) && is_numeric($result['adds'])) {
            $addsCount = (int)$result['adds'];
        }
        if (isset($result['updates']) && is_numeric($result['updates'])) {
            $updCount = (int)$result['updates'];
        }
        if (isset($result['deletes']) && is_numeric($result['deletes'])) {
            $delCount = (int)$result['deletes'];
        }

        if ($addsCount !== null && empty($creates)) {
            $creates = array_fill(0, max(0, $addsCount), '(create)');
        }
        if ($updCount !== null && empty($updates)) {
            $updates = array_fill(0, max(0, $updCount), '(update)');
        }
        if ($delCount !== null && empty($deletes)) {
            $deletes = array_fill(0, max(0, $delCount), '(delete)');
        }

        /**
         * 4) PREVIEW ALIGNMENT (Phase 15.4)
         *
         * If this is a dry-run preview and the pipeline reports intents_seen,
         * but no creates were synthesized yet, treat each intent as a CREATE.
         *
         * This is preview-only observability logic:
         * - No scheduler reads
         * - No file writes
         * - No behavior changes
         */
        if (
            empty($creates)
            && !empty($result['dryRun'])
            && isset($result['intents_seen'])
            && is_numeric($result['intents_seen'])
            && (int)$result['intents_seen'] > 0
        ) {
            $creates = array_fill(0, (int)$result['intents_seen'], '(create)');
        }

        return [
            'creates' => $creates,
            'updates' => $updates,
            'deletes' => $deletes,
        ];
    }

    /**
     * Compute a diff preview using the scheduler pipeline.
     *
     * IMPORTANT: Preview is always forced to dry-run.
     *
     * @param array $config Loaded plugin configuration
     * @return array Normalized diff arrays
     */
    public static function preview(array $config): array
    {
        $dryRun = true;

        $horizonDays = GcsFppSchedulerHorizon::getDays();
        $runner = new GcsSchedulerRunner($config, $horizonDays, $dryRun);
        $result = $runner->run();

        return self::normalizeDiffFromResult(is_array($result) ? $result : []);
    }

    /**
     * Apply scheduler changes using the real pipeline.
     *
     * @throws RuntimeException if any guard condition fails
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

        $dryRun = false;
        $horizonDays = GcsFppSchedulerHorizon::getDays();

        $runner = new GcsSchedulerRunner($config, $horizonDays, $dryRun);
        return $runner->run();
    }

    /**
     * Helper for endpoints/UI: compute counts from any result schema.
     */
    public static function countsFromResult(array $result): array
    {
        $norm = self::normalizeDiffFromResult($result);

        return [
            'creates' => is_array($norm['creates']) ? count($norm['creates']) : 0,
            'updates' => is_array($norm['updates']) ? count($norm['updates']) : 0,
            'deletes' => is_array($norm['deletes']) ? count($norm['deletes']) : 0,
        ];
    }
}
