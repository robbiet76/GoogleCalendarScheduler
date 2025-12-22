<?php

/**
 * Scheduler identity helper.
 *
 * IDENTITY RULE:
 * - GCS tag ONLY
 * - Format: gcs:v1:<uid>
 */
final class GcsSchedulerIdentity
{
    public const TAG_PREFIX = 'gcs:v1:';

    /**
     * Extract GCS UID from a desired scheduler entry.
     *
     * @param array<string,mixed> $entry
     */
    public static function extractUid(array $entry): ?string
    {
        $tag = $entry['tag'] ?? null;
        if (!is_string($tag)) {
            return null;
        }

        if (strpos($tag, self::TAG_PREFIX) !== 0) {
            return null;
        }

        $uid = substr($tag, strlen(self::TAG_PREFIX));
        return $uid !== '' ? $uid : null;
    }
}
