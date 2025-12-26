<?php
declare(strict_types=1);

/**
 * SchedulerPlanner
 *
 * Planning-only entry point for scheduler diffs.
 *
 * Responsibilities:
 * - Fetch Google Calendar
 * - Parse & resolve intents
 * - Run scheduler pipeline in FORCED dry-run
 * - Return normalized diff results
 *
 * GUARANTEES:
 * - NEVER writes to FPP scheduler
 * - NEVER instantiates SchedulerSync in apply mode
 * - Safe to call from UI load, Sync, Preview
 */
final class SchedulerPlanner
{
    /**
     * Compute a scheduler plan (diff) without any side effects.
     *
     * @param array $config Loaded plugin configuration
     * @return array{creates:array,updates:array,deletes:array}
     */
    public static function plan(array $config): array
    {
        // Force dry-run regardless of config
        $dryRun = true;

        $horizonDays = GcsFppSchedulerHorizon::getDays();

        $runner = new GcsSchedulerRunner(
            $config,
            $horizonDays,
            $dryRun
        );

        $result = $runner->run();

        // Normalize result to diff arrays
        return DiffPreviewer::normalizeResultForUi($result);
    }
}
