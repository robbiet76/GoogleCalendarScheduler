<?php
declare(strict_types=1);

/**
 * ExecutionController
 *
 * Central entry point for experimental execution paths.
 *
 * IMPORTANT:
 * - Experimental behavior is gated by config.
 * - Default is OFF.
 * - Nothing executes unless explicitly enabled AND wired.
 */
final class ExecutionController
{
    /**
     * Conditionally run experimental logic.
     *
     * For Milestone 11.6 Step B:
     * - Gate exists
     * - No experimental logic is executed
     *
     * @param array $config Loaded plugin configuration
     */
    public static function maybeRun(array $config): void
    {
        // Hard gate: experimental features must be explicitly enabled
        if (empty($config['experimental']['enabled'])) {
            return;
        }

        // Intentionally inert.
        // Experimental execution will be added in Step C.
    }

    /**
     * Legacy manual entry point (kept inert).
     *
     * @param array $config Loaded plugin configuration
     */
    public static function run(array $config): void
    {
        // Intentionally empty.
    }
}
