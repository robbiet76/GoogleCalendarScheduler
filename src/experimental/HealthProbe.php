<?php
declare(strict_types=1);

/**
 * HealthProbe
 *
 * Minimal proof-of-life action for experimental execution paths.
 *
 * This class introduces NO runtime behavior unless explicitly called.
 */
final class HealthProbe
{
    /**
     * Proof-of-life method.
     *
     * Intentionally empty for Milestone 11.2 Step B.
     */
    public static function ping(): void
    {
        // Intentionally empty.
        // No behavior permitted in Step B.
    }
}
