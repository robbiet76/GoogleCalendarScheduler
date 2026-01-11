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
    private array $ids = [];
    private array $hashes = [];

    /**
     * Build a ManifestIdentity from a single intent.
     *
     * This is a thin adapter used by SchedulerSync so it does not
     * need to know about bulk/series identity construction.
     */
    public static function fromIntent(array $intent): self
    {
        return self::fromSeries([$intent]);
    }

    /**
     * Build a ManifestIdentity from a series of intents.
     */
    public static function fromSeries(array $series): self
    {
        $ids = [];
        $hashes = [];

        foreach ($series as $entry) {
            $ids[] = self::buildId($entry);
            $hashes[] = self::buildHash($entry);
        }

        $instance = new self();
        $instance->ids = $ids;
        $instance->hashes = $hashes;

        return $instance;
    }

    public static function fromArray(array $data): self
    {
        $instance = new self();
        $instance->ids = $data['ids'] ?? [];
        $instance->hashes = $data['hashes'] ?? [];
        return $instance;
    }

    public function id(): string
    {
        return $this->ids[0] ?? '';
    }

    public function hash(): string
    {
        return $this->hashes[0] ?? '';
    }

    public function sameHashAs(self $other): bool
    {
        return $this->hash() === $other->hash();
    }

    /**
     * Debug-only helper.
     *
     * Returns a minimal representation useful for diff diagnostics.
     * This must not affect identity behavior.
     */
    public function toDebugArray(): array
    {
        return [
            'id'   => $this->id(),
            'hash'=> $this->hash(),
        ];
    }

    /**
     * Build a stable manifest ID for an entry.
     *
     * This ID represents the schedule identity:
     * type + target + time window + date range + days.
     */
    public static function buildId(array $entry): string
    {
        $identity = [
            'type'       => self::entryType($entry),
            'target'     => !empty($entry['command'])
                ? (string)$entry['command']
                : (string)($entry['playlist'] ?? ''),
            'startTime'  => (string)($entry['startTime'] ?? ''),
            'endTime'    => (string)($entry['endTime'] ?? ''),
            'days'       => isset($entry['days'])
                ? (string)$entry['days']
                : (isset($entry['day']) ? (string)$entry['day'] : null),
            'startDate'  => isset($entry['startDate'])
                ? self::normalizeDate((string)$entry['startDate'])
                : null,
            'endDate'    => isset($entry['endDate'])
                ? self::normalizeDate((string)$entry['endDate'])
                : null,
        ];

        ksort($identity);

        return hash(
            'sha256',
            json_encode($identity, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Build a hash representing the full behavioral intent of the entry.
     *
     * This is used for change detection.
     */
    public static function buildHash(array $entry): string
    {
        $normalized = self::normalize($entry);

        // DEBUG: log normalized identity input before hashing
        error_log(
            '[GCS DEBUG][IDENTITY HASH INPUT] ' .
            json_encode($normalized, JSON_UNESCAPED_SLASHES)
        );

        return hash(
            'sha256',
            json_encode($normalized, JSON_UNESCAPED_SLASHES)
        );
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