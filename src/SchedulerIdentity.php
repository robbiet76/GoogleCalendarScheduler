<?php
declare(strict_types=1);

/**
 * Scheduler identity helper.
 *
 * CANONICAL OWNERSHIP RULE (Phase 17.x):
 * - An entry is GCS-managed if it contains a GCS v1 tag in args[]
 * - Tag format:
 *     |GCS:v1|uid=<uid>|range=<start..end>|days=<shortdays>
 *
 * IDENTITY:
 * - UID ONLY
 * - range/days are informational
 */
final class GcsSchedulerIdentity
{
    public const TAG_MARKER = '|GCS:v1|';

    /**
     * Canonical identity extractor.
     *
     * @param array<string,mixed> $entry
     */
    public static function extractKey(array $entry): ?string
    {
        $tag = self::extractTag($entry);
        if ($tag === null) {
            return null;
        }

        if (!preg_match('/\|GCS:v1\|uid=([^|]+)/', $tag, $m)) {
            return null;
        }

        $uid = $m[1] ?? '';
        return $uid !== '' ? $uid : null;
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
            if (strpos($arg, self::TAG_MARKER) !== false) {
                return $arg;
            }
        }

        return null;
    }
}
