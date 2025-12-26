<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 *
 * Phase 16.4 responsibilities:
 * - Provide a stable, UI-safe preview schema
 * - Never mutate scheduler state during preview
 * - Apply only when explicitly requested and guards pass
 *
 * This class is the ONLY bridge between:
 *   UI (Preview / Apply buttons)
 *   and
 *   SchedulerRunner (real pipeline)
 */
final class DiffPreviewer
{
    /**
     * Normalize any SchedulerRunner result into
     * a UI-safe diff structure:
     *
     *   [
     *     'creates' => array,
     *     'updates' => array,
     *     'deletes' => array,
     *   ]
     *
     * Supported result shapes:
     * - Phase 14–16: ['adds'=>n,'updates'=>n,'deletes'=>n]
     * - Experimental: ['diff'=>['creates'=>[], ...]]
     */
    private static function normalize(array $result): array
    {
        $creates = [];
        $updates = [];
        $deletes = [];

        /* ---------------------------------------------
         * Preferred experimental schema
         * -------------------------------------------*/
        if (isset($result['diff']) && is_array($result['diff'])) {
            $d = $result['diff'];

            if (isset($d['creates']) && is_array($d['creates'])) {
                $creates = $d['creates'];
            }
            if (isset($d['updates']) && is_array($d['updates'])) {
                $updates = $d['updates'];
            }
            if (isset($d['deletes']) && is_array($d['deletes'])) {
                $deletes = $d['deletes'];
            }
        }

        /* ---------------------------------------------
         * Fallback: numeric-only summaries
         * -------------------------------------------*/
        if (empty($creates) && isset($result['adds']) && is_numeric($result['adds'])) {
            $creates = array_fill(0, (int)$result['adds'], '(create)');
        }

        if (empty($updates) && isset($result['updates']) && is_numeric($result['updates'])) {
            $updates = array_fill(0, (int)$result['updates'], '(update)');
        }

        if (empty($deletes) && isset($result['deletes']) && is_numeric($result['deletes'])) {
            $deletes = array_fill(0, (int)$result['deletes'], '(delete)');
        }

        return [
            'creates' => $creates,
            'updates' => $updates,
            'deletes' => $deletes,
        ];
    }

    /**
     * PREVIEW
     *
     * - Always forces dry-run
     * - Never mutates schedule.json
     * - Safe to run automatically or repeatedly
     *
     * @param array $config
     * @return array normalized diff
     */
    public static function preview(array $config): array
    {
        $horizonDays = GcsFppSchedulerHorizon::getDays();

        // HARD OVERRIDE: preview is ALWAYS dry-run
        $runner = new GcsSchedulerRunner(
            $config,
            $horizonDays,
            true
        );

        $result = $runner->run();

        return self::normalize(
            is_array($result) ? $result : []
        );
    }

    /**
     * APPLY
     *
     * - Executes the real pipeline
     * - Writes to schedule.json
     * - Protected by triple guard
     *
     * @param array $config
     * @return array raw SchedulerRunner result
     *
     * @throws RuntimeException
     */
    public static function apply(array $config): array
    {
        /* ---------------------------------------------
         * Triple guard enforcement
         * -------------------------------------------*/
        if (empty($config['experimental']['enabled'])) {
            throw new RuntimeException('Experimental mode is not enabled');
        }

        if (empty($config['experimental']['allow_apply'])) {
            throw new RuntimeException('Experimental apply is not allowed');
        }

        if (!empty($config['runtime']['dry_run'])) {
            throw new RuntimeException('Apply blocked while dry-run is enabled');
        }

        $horizonDays = GcsFppSchedulerHorizon::getDays();

        $runner = new GcsSchedulerRunner(
            $config,
            $horizonDays,
            false
        );

        return $runner->run();
    }

    /**
     * Helper for UI apply responses
     *
     * Converts ANY result schema into numeric counts
     *
     * @param array $result
     * @return array{creates:int,updates:int,deletes:int}
     */
    public static function countsFromResult(array $result): array
    {
        $norm = self::normalize($result);

        return [
            'creates' => count($norm['creates']),
            'updates' => count($norm['updates']),
            'deletes' => count($norm['deletes']),
        ];
    }
}
