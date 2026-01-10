<?php
declare(strict_types=1);

/**
 * ManifestIdentity
 *
 * Responsible for stable identity and change detection
 * for scheduler entries managed by GoogleCalendarScheduler.
 *
 * Goals:
 * - Deterministic identity across imports
 * - Safe diffing without relying on FPP internals
 * - No dependence on args / description tagging
 */
final class ManifestIdentity
{
    /**
     * Build a stable manifest ID for an entry.
     *
     * This ID represents *what* the entry is, not when it runs.
     */
    public static function buildId(array $entry): string
    {
        if (!empty($entry['command'])) {
            $target = $entry['command'];
        } else {
            $target = $entry['playlist'] ?? '';
        }

        $type = self::entryType($entry);

        return hash('sha256', implode('|', [$type, $target]));
    }

    /**
     * Build a hash representing the full behavioral intent of the entry.
     *
     * This is used for change detection.
     */
    public static function buildHash(array $entry): string
    {
        $normalized = self::normalize($entry);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Normalize an entry so hashing is stable across versions.
     */
    public static function normalize(array $entry): array
    {
        $normalized = $entry;

        // Remove runtime / ownership artifacts
        unset(
            $normalized['args'],
            $normalized['multisyncCommand'],
            $normalized['multisyncHosts']
        );

        // Normalize dates
        foreach (['startDate', 'endDate'] as $key) {
            if (isset($normalized[$key])) {
                $normalized[$key] = self::normalizeDate($normalized[$key]);
            }
        }

        // Normalize numeric fields
        foreach ([
            'enabled',
            'day',
            'repeat',
            'startTimeOffset',
            'endTimeOffset',
            'stopType'
        ] as $key) {
            if (isset($normalized[$key])) {
                $normalized[$key] = (int)$normalized[$key];
            }
        }

        // Normalize time strings
        foreach (['startTime', 'endTime'] as $key) {
            if (isset($normalized[$key])) {
                $normalized[$key] = (string)$normalized[$key];
            }
        }

        // Remove empty strings for stability
        foreach ($normalized as $k => $v) {
            if ($v === '') {
                unset($normalized[$k]);
            }
        }

        ksort($normalized);
        return $normalized;
    }

    /**
     * Determine entry type explicitly.
     */
    private static function entryType(array $entry): string
    {
        if (!empty($entry['command'])) {
            return 'command';
        }

        if (!empty($entry['type'])) {
            return (string)$entry['type'];
        }

        return 'playlist';
    }

    /**
     * Normalize date formats.
     */
    private static function normalizeDate(string $date): string
    {
        // Already normalized
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // Leave holiday tokens untouched
        if (!preg_match('/^\d/', $date)) {
            return $date;
        }

        $ts = strtotime($date);
        return $ts ? date('Y-m-d', $ts) : $date;
    }
}