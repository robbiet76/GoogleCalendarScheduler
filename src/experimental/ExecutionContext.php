<?php
declare(strict_types=1);

/**
 * ExecutionContext
 *
 * Scaffold-only execution context.
 * Introduced during Phase 11 restart, but intentionally phase-agnostic.
 * No side effects, no config reads, no runtime wiring beyond explicit require.
 */
final class ExecutionContext
{
    public function __construct()
    {
        // scaffold only
    }
}
