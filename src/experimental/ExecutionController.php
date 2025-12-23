<?php
declare(strict_types=1);

/**
 * ExecutionController
 *
 * Explicit entry point for experimental execution paths.
 *
 * TEMPORARY STATE (Milestone 11.3 Step B):
 * - Emits a single experimental log entry when run().
 */
final class ExecutionController
{
    /**
     * Manual execution entry point.
     *
     * This method is only executed when explicitly invoked.
     */
    public static function run(): void
    {
        ScopedLogger::log('ExecutionController run invoked');
    }
}
