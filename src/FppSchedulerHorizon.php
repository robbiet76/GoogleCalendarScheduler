<?php
declare(strict_types=1);

/**
 * GcsFppSchedulerHorizon
 *
 * LEGACY helper for determining calendar planning horizon.
 *
 * Historical context:
 * - Early versions attempted to mirror or respect FPP scheduler horizon behavior
 * - This abstraction allowed experimentation with dynamic horizon values
 *
 * Current status:
 * - The GCS planner no longer needs to follow FPP’s internal horizon model
 * - A fixed, conservative horizon (e.g. 1 year) is sufficient and safer
 * - This class is retained only for compatibility with existing code paths
 *
 * IMPORTANT:
 * - New code should NOT depend on this class
 * - Horizon decisions should be centralized in the planner configuration
 *
 * Planned future:
 * - Replace calls with a fixed constant (e.g. 365 days)
 * - Remove this class in a future breaking-cleanup phase
 */
final class GcsFppSchedulerHorizon
{
    /**
     * Return the number of days to expand calendar events into the future.
     *
     * @return int Number of days
     */
    public static function getDays(): int
    {
        // Legacy default horizon
        return 30;
    }
}
