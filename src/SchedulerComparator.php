<?php

/**
 * Compares scheduler entries for update necessity.
 *
 * IMPORTANT:
 * - Identity is already matched by GCS UID
 * - This comparison determines UPDATE vs NO-OP
 */
final class GcsSchedulerComparator
{
    /**
     * @param GcsExistingScheduleEntry $existing
     * @param array<string,mixed>      $desired
     */
    public static function isEquivalent(
        GcsExistingScheduleEntry $existing,
        array $desired
    ): bool {
        $a = $existing->raw();
        $b = $desired;

        // Remove non-semantic fields
        unset(
            $a['id'],
            $a['lastRun'],
            $b['id']
        );

        // Compare normalized structures
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function normalize(array $entry): array
    {
        ksort($entry);
        return $entry;
    }
}
