<?php
declare(strict_types=1);

/**
 * SchedulerComparator
 *
 * Determines whether an existing scheduler entry is semantically
 * equivalent to a desired entry produced by the planner.
 *
 * PURPOSE:
 * - Decide UPDATE vs NO-OP once identity has already been matched
 *
 * IMPORTANT ASSUMPTIONS (Phase 17+):
 * - Identity matching is already complete before comparison
 * - Identity is defined by the FULL GCS v1 tag (handled elsewhere)
 * - This class MUST NOT attempt to infer ownership or identity
 * - Semantic equivalence is defined strictly by an explicit canonical field list
 *
 * NON-GOALS:
 * - No scheduler writes
 * - No mutation of inputs
 * - No normalization beyond simple structural comparison
 */
final class SchedulerComparator
{
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
    ];

    /**
     * Determine whether an existing scheduler entry and a desired entry
     * are functionally equivalent.
     *
     * If this returns true, the planner will treat the entry as a NO-OP.
     * If false, the entry will be scheduled for UPDATE.
     *
     * @param ExistingScheduleEntry $existing Existing scheduler entry
     * @param array<string,mixed>      $desired  Desired scheduler entry
     * @return bool True if equivalent; false if update required
     */
    public static function isEquivalent(
        ExistingScheduleEntry $existing,
        array $desired
    ): bool {
        $a = $existing->raw();
        $b = $desired;

        foreach (self::CANONICAL_FIELDS as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
