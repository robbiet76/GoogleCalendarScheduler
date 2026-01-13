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
 * - Identity fields (dates/days/times/target/type) MUST NOT be compared here
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
     * Canonical non-identity behavioral fields.
     *
     * Identity fields are NEVER compared here.
     */
    private const BASE_FIELDS = ['enabled', 'repeat', 'stopType'];

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
        // Compare base behavioral fields (always applicable)
        foreach (self::BASE_FIELDS as $field) {
            if (($existing[$field] ?? null) !== ($desired[$field] ?? null)) {
                self::debugMismatch($field, $existing[$field] ?? null, $desired[$field] ?? null);
                return false;
            }
        }

        // Decide command vs non-command without relying on FPP state.
        // Commands have a command-specific payload block; playlists/sequences do not.
        $isCommand = (($desired['type'] ?? null) === 'command') || array_key_exists('payload', $desired);

        // Non-command (playlist/sequence): BASE_FIELDS are sufficient.
        if (!$isCommand) {
            return true;
        }

        // Command: compare payload structurally without interpretation.
        $existingPayload = $existing['payload'] ?? null;
        $desiredPayload  = $desired['payload'] ?? null;

        if (!self::payloadsEqual($existingPayload, $desiredPayload)) {
            self::debugMismatch('payload', $existingPayload, $desiredPayload);
            return false;
        }

        return true;
    }

    /**
     * Compare payloads structurally without interpretation.
     *
     * - null and null are equal
     * - arrays are compared with recursively-sorted keys
     * - scalars are compared with strict equality
     */
    private static function payloadsEqual($a, $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }

        self::ksortRecursive($a);
        self::ksortRecursive($b);

        return $a === $b;
    }

    /**
     * Recursively sort array keys for stable comparison.
     */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }

    /**
     * Debug helper for mismatch logging.
     */
    private static function debugMismatch(string $field, $existing, $desired): void
    {
        if (defined('GCS_DEBUG') && GCS_DEBUG) {
            error_log('[GCS DEBUG][COMPARATOR MISMATCH] ' . json_encode([
                'field' => $field,
                'existing' => $existing,
                'desired' => $desired,
            ], JSON_UNESCAPED_SLASHES));
        }
    }
}
