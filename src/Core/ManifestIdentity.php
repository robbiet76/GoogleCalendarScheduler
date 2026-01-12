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
    /** @var array<string,string> */
    private static array $identityCache = [];

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
        if (
            empty($entry['startDate']) ||
            empty($entry['endDate']) ||
            (!isset($entry['day']) && !isset($entry['days']))
        ) {
            // Removed error_log for INVALID INPUT to tolerate legacy FPP entries without uid
            // No exception thrown to maintain backward compatibility
        }

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
        // Skip hashing if entry lacks semantic identity (prevents noise during adoption)
        if (
            empty($entry['startDate']) &&
            empty($entry['endDate']) &&
            empty($entry['startTime']) &&
            empty($entry['endTime']) &&
            empty($entry['days']) &&
            empty($entry['day']) &&
            empty($entry['playlist']) &&
            empty($entry['command'])
        ) {
            return '';
        }

        $normalized = self::normalize($entry);

        $cacheKey = json_encode($normalized, JSON_UNESCAPED_SLASHES);

        if (isset(self::$identityCache[$cacheKey])) {
            return self::$identityCache[$cacheKey];
        }

        // DEBUG: log normalized identity input before hashing
        if (defined('GCS_DEBUG') && GCS_DEBUG) {
            error_log(
                '[GCS DEBUG][IDENTITY HASH INPUT] ' . $cacheKey
            );
        }

        $hash = hash('sha256', $cacheKey);
        self::$identityCache[$cacheKey] = $hash;

        return $hash;
    }

    /**
     * Normalize an entry so hashing is stable across versions.
     */
    public static function normalize(array $entry): array
    {
        $normalized = $entry;

        unset(
            $normalized['_manifest'],
            $normalized['uid'],
            $normalized['summary'],
            $normalized['description'],
            $normalized['range'],
            $normalized['template'],
            $normalized['resolved'],
            $normalized['yaml'],
            $normalized['gcs']
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

    /**
     * Used exclusively for adoption matching (pre-manifest).
     *
     * Compares two entries for semantic equivalence based ONLY on canonical identity fields:
     * type, target, normalized dates, days, times.
     *
     * Ignores uid, summary, description, resolved, yaml, gcs, and all non-identity payload fields.
     *
     * This method is STRICTLY for pre-adoption matching and does NOT consider full payload.
     *
     * @param array $a First entry to compare.
     * @param array $b Second entry to compare.
     * @return bool True if entries are semantically equivalent, false otherwise.
     */

    /**
     * This method is used only for pre-adoption matching.
     * It must not be used once _manifest.id exists.
     * It must remain minimal and stable.
     */
    public static function semanticMatch(array $a, array $b): bool
    {
        $normalizeSemantic = function(array $entry): array {
            $semantic = [];

            $semantic['type'] = self::entryType($entry);
            $semantic['target'] = !empty($entry['command'])
                ? (string)$entry['command']
                : (string)($entry['playlist'] ?? '');

            $semantic['startDate'] = isset($entry['startDate'])
                ? self::normalizeDate((string)$entry['startDate'])
                : null;

            $semantic['endDate'] = isset($entry['endDate'])
                ? self::normalizeDate((string)$entry['endDate'])
                : null;

            $semantic['days'] = isset($entry['days'])
                ? (string)$entry['days']
                : (isset($entry['day']) ? (string)$entry['day'] : null);

            $semantic['startTime'] = isset($entry['startTime'])
                ? (string)$entry['startTime']
                : '';

            $semantic['endTime'] = isset($entry['endTime'])
                ? (string)$entry['endTime']
                : '';

            // Remove keys with null or empty string values to prevent false mismatches
            foreach ($semantic as $key => $value) {
                if ($value === null || $value === '') {
                    unset($semantic[$key]);
                }
            }

            ksort($semantic);

            return $semantic;
        };

        $aSemantic = $normalizeSemantic($a);
        $bSemantic = $normalizeSemantic($b);

        return $aSemantic === $bSemantic;
    }
}