<?php
declare(strict_types=1);

/**
 * SchedulerIdentity
 *
 * Canonical identity and ownership helper for scheduler entries.
 *
 * Ownership rules (Phase 29+):
 * - A scheduler entry is considered GCS-managed if it contains
 *   a valid GCS v1 tag in args[]
 *
 * Identity rules:
 * - Identity is UID ONLY
 * - Planner semantics (ranges, days, ordering) MUST NOT leak
 *   into scheduler identity or apply logic
 *
 * Tag format (two-part, Phase 29+):
 *   |M|GCS:v1|<uid>
 *
 * Where:
 * - |M|        = human-visible "Managed" marker (future FPP UI use)
 * - |GCS:v1|   = internal ownership + versioning
 * - <uid>      = canonical calendar series UID
 *
 * This class is the single source of truth for:
 * - scheduler ownership
 * - identity extraction
 * - tag construction
 */
final class SchedulerIdentity
{
    /**
     * Human-visible managed marker
     */
    public const DISPLAY_TAG = '|M|';

    /**
     * Internal ownership/version marker
     */
    public const INTERNAL_TAG = '|GCS:v1|';

    /**
     * Full canonical prefix for all managed scheduler entries
     */
    public const FULL_PREFIX = self::DISPLAY_TAG . self::INTERNAL_TAG;

    /**
     * Extract the canonical GCS identity key (UID) from a scheduler entry.
     *
     * @param array<string,mixed> $entry
     * @return string|null UID if managed, otherwise null
     */
    public static function extractKey(array $entry): ?string
    {
        if (!isset($entry['args']) || !is_array($entry['args'])) {
            return null;
        }

        foreach ($entry['args'] as $arg) {
            if (!is_string($arg)) {
                continue;
            }

            if (str_starts_with($arg, self::FULL_PREFIX)) {
                $uid = substr($arg, strlen(self::FULL_PREFIX));
                return $uid !== '' ? $uid : null;
            }
        }

        return null;
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
     * Build args[] tag for a managed scheduler entry.
     *
     * @param string $uid Canonical calendar UID
     * @return string Tag suitable for args[]
     */
    public static function buildArgsTag(string $uid): string
    {
        return self::FULL_PREFIX . $uid;
    }
}
