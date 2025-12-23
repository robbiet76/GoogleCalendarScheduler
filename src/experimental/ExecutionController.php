<?php
declare(strict_types=1);

/**
 * ExecutionController
 *
 * Explicit entry point for experimental execution paths.
 *
 * IMPORTANT:
 * - Nothing in this class runs automatically.
 * - Methods are only executed when explicitly invoked.
 */
final class ExecutionController
{
    /**
     * Manual execution entry point.
     *
     * Intentionally inert for Milestone 11.5 Step B.
     * DiffPreviewer is referenced but NOT invoked.
     *
     * @param array $config Loaded plugin configuration
     */
    public static function run(array $config): void
    {
        // DiffPreviewer is intentionally NOT invoked yet.
        // This wiring exists only to validate structure.
        //
        // Example (DO NOT ENABLE YET):
        // $summary = DiffPreviewer::preview($config);
    }
}
