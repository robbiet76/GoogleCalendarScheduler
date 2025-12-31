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
 *
 * NON-GOALS:
 * - No scheduler writes
 * - No mutation of inputs
 * - No normalization beyond simple structural comparison
 */
final class SchedulerComparator
{
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

        /*
         * Remove non-semantic / runtime-only fields.
         *
         * These fields do not affect scheduler behavior and must not
         * cause spurious updates.
         */
        unset(
            $a['id'],
            $a['lastRun'],
            $b['id']
        );

        // Compare normalized structures
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * Normalize an entry for comparison.
     *
     * Current strategy:
     * - Sort keys recursively at the top level
     *
     * NOTE:
     * - Values are assumed to already be normalized by upstream mapping
     * - Deeper normalization should be added ONLY if proven necessary
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function normalize(array $entry): array
    {
        ksort($entry);
        return $entry;
    }
}
