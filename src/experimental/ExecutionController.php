<?php
declare(strict_types=1);

/**
 * ExecutionController
 *
 * Explicit entry point for experimental execution paths.
 *
 * TEMPORARY STATE (Milestone 11.5 Step C):
 * - Invokes DiffPreviewer
 * - Logs summary counts only
 */
final class ExecutionController
{
    /**
     * Manual execution entry point.
     *
     * @param array $config Loaded plugin configuration
     */
    public static function run(array $config): void
    {
        $summary = DiffPreviewer::preview($config);

        ScopedLogger::log(
            'Diff preview summary ' . json_encode($summary)
        );
    }
}
