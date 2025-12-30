<?php
declare(strict_types=1);

/**
 * GcsSchedulerIdentity
 *
 * Canonical identity and ownership helper for scheduler entries.
 *
 * Ownership rules:
 * - A scheduler entry is considered GCS-managed if it contains a
 *   valid GCS v1 identity tag in args[]
 *
 * Identity rules:
 * - Identity is the FULL GCS tag string
 *   (uid + range + days)
 *
 * Rationale:
 * - UID-only identity is insufficient once recurring calendar
 *   events expand into multiple scheduler entries
 * - Apply and delete operations must reason about exact raw
 *   scheduler entries, not logical event groupings
 *
 * This class defines the single source of truth for determining
 * scheduler ownership and identity.
 */
final class GcsSchedulerIdentity
{
    public const TAG_MARKER = '|GCS:v1|';

    /**
     * Extract the canonical GCS identity key from a scheduler entry.
     *
     * IMPORTANT:
     * - The returned value is the FULL GCS tag string
     * - Returning UID-only values will break delete and update semantics
     *
     * @param array<string,mixed> $entry
     * @return string|null Canonical identity tag or null if not present
     */
    public static function extractKey(array $entry): ?string
    {
        return self::extractTag($entry);
    }

    /**
     * Compatibility alias for extractKey().
     *
     * @deprecated Use extractKey() instead.
     */
    public static function extractUid(array $entry): ?string
    {
        return self::extractKey($entry);
    }

    /**
     * Determine whether a scheduler entry is managed by GCS.
     */
    public static function isGcsManaged(array $entry): bool
    {
        return self::extractKey($entry) !== null;
    }

    /**
     * Extract the raw GCS identity tag from args[].
     *
     * @param array<string,mixed> $entry
     * @return string|null Raw GCS tag or null if not found
     */
    private static function extractTag(array $entry): ?string
    {
        if (!isset($entry['args']) || !is_array($entry['args'])) {
            return null;
        }

        foreach ($entry['args'] as $arg) {
            if (!is_string($arg)) {
                continue;
            }

            // Canonical tags must start with the marker
            if (strpos($arg, self::TAG_MARKER) === 0) {
                return $arg;
            }
        }

        return null;
    }
}
