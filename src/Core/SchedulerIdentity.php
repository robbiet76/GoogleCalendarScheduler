<?php
declare(strict_types=1);

/**
 * SchedulerIdentity
 *
 * Canonical identity and ownership helper for scheduler entries.
 *
 * Ownership rules:
 * - A scheduler entry is considered GCS-managed if it contains a
 *   valid GCS identity tag in args[]
 *
 * Identity rules (Phase 29+):
 * - Identity is the UID ONLY
 * - Planner semantics must not leak into scheduler state
 *
 * Tag format (two-part):
 *   |M|GCS:v1|<uid>
 *
 * Where:
 * - |M|        = human-visible "Managed" marker (future FPP UI use)
 * - |GCS:v1|   = internal ownership + versioning
 * - <uid>      = canonical calendar series UID
 *
 * Backward compatibility:
 * - Legacy tags beginning with |GCS:v1|uid=... are still recognized
 * - UID is extracted safely for update/delete semantics
 */
final class SchedulerIdentity
{
    /**
     * Human-visible managed marker (Phase 29+)
     */
    public const DISPLAY_TAG = '|M|';

    /**
     * Internal ownership/version marker
     */
    public const INTERNAL_TAG = '|GCS:v1|';

    /**
     * Full canonical prefix for new tags
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

            // ---------------------------------------------------------
            // Phase 29+ canonical format: |M|GCS:v1|<uid>
            // ---------------------------------------------------------
            if (str_starts_with($arg, self::FULL_PREFIX)) {
                $uid = substr($arg, strlen(self::FULL_PREFIX));
                return $uid !== '' ? $uid : null;
            }

            // ---------------------------------------------------------
            // Backward compatibility: legacy |GCS:v1|uid=... tags
            // ---------------------------------------------------------
            if (str_starts_with($arg, self::INTERNAL_TAG)) {
                if (preg_match('/uid=([^|]+)/', $arg, $m)) {
                    return $m[1];
                }
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
