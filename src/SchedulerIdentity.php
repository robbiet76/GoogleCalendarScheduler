<?php
declare(strict_types=1);

/**
 * Scheduler identity helper.
 *
 * CANONICAL OWNERSHIP RULE (Phase 17+):
 * - An entry is GCS-managed if it contains a GCS v1 tag in args[]
 * - Tag format:
 *     |GCS:v1|uid=<uid>|range=<start..end>|days=<shortdays>
 *
 * IDENTITY (Apply boundary):
 * - FULL GCS TAG STRING
 *   (uid + range + days)
 *
 * RATIONALE:
 * - UID-only identity is insufficient once recurring events
 *   expand into multiple scheduler entries.
 * - Apply must reason about exact raw scheduler entries.
 */
final class GcsSchedulerIdentity
{
    public const TAG_MARKER = '|GCS:v1|';

    /**
     * Canonical identity extractor.
     *
     * IMPORTANT:
     * - Identity is the FULL GCS tag string.
     * - Returning UID-only breaks delete semantics.
     *
     * @param array<string,mixed> $entry
     */
    public static function extractKey(array $entry): ?string
    {
        return self::extractTag($entry);
    }

    /**
     * Compatibility alias (Phase 17.7)
     * @deprecated use extractKey()
     */
    public static function extractUid(array $entry): ?string
    {
        return self::extractKey($entry);
    }

    /**
     * Ownership check helper.
     */
    public static function isGcsManaged(array $entry): bool
    {
        return self::extractKey($entry) !== null;
    }

    /**
     * Extract raw GCS tag from args[].
     *
     * @param array<string,mixed> $entry
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

            // Tag must start with the marker to be canonical
            if (strpos($arg, self::TAG_MARKER) === 0) {
                return $arg;
            }
        }

        return null;
    }
}
