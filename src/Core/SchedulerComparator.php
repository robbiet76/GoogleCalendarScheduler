<?php
declare(strict_types=1);

/**
 * SchedulerComparator
 *
 * Determines whether an existing scheduler entry and a desired
 * scheduler entry are semantically equivalent.
 *
 * ARCHITECTURE (Manifest-based):
 * - Identity matching is completed BEFORE this comparator is invoked
 * - Both inputs are raw scheduler-entry arrays
 * - This class decides UPDATE vs NO-OP only
 *
 * NON-GOALS:
 * - No ownership inference
 * - No identity checks
 * - No normalization
 * - No mutation
 * - No scheduler I/O
 */
final class SchedulerComparator
{
    /**
     * Canonical semantic fields.
     *
     * These fields fully define the functional behavior of a scheduler entry.
     * If ANY of these differ, the entry must be updated.
     *
     * IMPORTANT:
     * - Payload is compared as a whole (opaque)
     * - No derived fields
     * - Order does not matter
     */
    private const CANONICAL_FIELDS = [
        'type',
        'target',
        'startDate',
        'endDate',
        'day',
        'startTime',
        'endTime',
        'playlist',
        'sequence',
        'repeat',
        'stopType',
        'command',
        'payload',
    ];

    /**
     * Determine whether two scheduler entries are functionally equivalent.
     *
     * @param array<string,mixed> $existing Existing scheduler entry (from FPP)
     * @param array<string,mixed> $desired  Desired scheduler entry (from planner)
     *
     * @return bool True if equivalent; false if update required
     */
    public static function isEquivalent(array $existing, array $desired): bool
    {
        foreach (self::CANONICAL_FIELDS as $field) {
            if (($existing[$field] ?? null) !== ($desired[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }
}