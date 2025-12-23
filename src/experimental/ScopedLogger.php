<?php
declare(strict_types=1);

/**
 * ScopedLogger
 *
 * Logging wrapper for experimental paths.
 *
 * TEMPORARY STATE (Milestone 11.3 Step B):
 * - ENABLED is set to true for verification only.
 * - This file MUST be reverted immediately after testing.
 */
final class ScopedLogger
{
    /**
     * Experimental logging enable switch.
     *
     * TEMPORARILY ENABLED FOR TESTING.
     */
    private const ENABLED = false;

    /**
     * Write an experimental log entry.
     *
     * @param string $message
     */
    public static function log(string $message): void
    {
        if (!self::ENABLED) {
            return;
        }

        GcsLog::info('[Experimental] ' . $message);
    }
}
