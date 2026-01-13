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

    /** Normalize a days token into a stable comma-separated string. */
    private static function normalizeDaysToken(string $days): string
    {
        $days = trim($days);
        if ($days === '') {
            return '';
        }
        // Accept either a single numeric/string day, or a comma-separated list.
        $parts = preg_split('/\s*,\s*/', $days) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '') {
                continue;
            }
            // Normalize numeric strings like "07" -> "7"
            if (ctype_digit($p)) {
                $p = (string)((int)$p);
            }
            $out[] = $p;
        }
        return implode(',', $out);
    }

    /**
     * Extract canonical identity fields from an entry.
     *
     * This method MUST be the only place identity fields are derived.
     * It is intentionally strict and does not tolerate incomplete identity.
     */
    private static function extractIdentity(array $entry): array
    {
        $identity = [];
        $warnings = [];

        $identity['type'] = self::entryType($entry);

        $identity['target'] = !empty($entry['command'])
            ? (string)$entry['command']
            : (string)($entry['playlist'] ?? '');

        // Dates: derive identity-safe dual dates via FPPSemantics
        $startDateRaw = $entry['startDate'] ?? ($entry['range']['start'] ?? null);
        $endDateRaw   = $entry['endDate']   ?? ($entry['range']['end']   ?? null);

        if ($startDateRaw !== null && $startDateRaw !== '') {
            $identity['startDate'] = FPPSemantics::interpretDateToken(
                (string)$startDateRaw,
                null,
                $warnings,
                'manifest.startDate'
            );
        }

        if ($endDateRaw !== null && $endDateRaw !== '') {
            $identity['endDate'] = FPPSemantics::interpretDateToken(
                (string)$endDateRaw,
                null,
                $warnings,
                'manifest.endDate'
            );
        }

        // Days: accept explicit days/day, otherwise accept range.days
        if (array_key_exists('days', $entry) && $entry['days'] !== null && $entry['days'] !== '') {
            $identity['days'] = self::normalizeDaysToken((string)$entry['days']);
        } elseif (array_key_exists('day', $entry) && $entry['day'] !== null && $entry['day'] !== '') {
            $identity['days'] = self::normalizeDaysToken((string)$entry['day']);
        } elseif (isset($entry['range']['days']) && $entry['range']['days'] !== '') {
            $identity['days'] = self::normalizeDaysToken((string)$entry['range']['days']);
        }

        // Times
        $identity['startTime'] = trim((string)($entry['startTime'] ?? ''));
        $identity['endTime']   = trim((string)($entry['endTime'] ?? ''));

        // Commands have no duration; force endTime = startTime for identity
        if ($identity['type'] === 'command' && isset($identity['startTime'])) {
            $identity['endTime'] = $identity['startTime'];
        }

        // Remove empty fields
        foreach ($identity as $k => $v) {
            if ($v === '' || $v === null) {
                unset($identity[$k]);
            }
        }

        ksort($identity);
        return $identity;
    }

    /**
     * Validate that the identity contains all required fields.
     *
     * Required:
     * - type
     * - target
     * - startDate
     * - endDate
     * - days
     * - startTime
     * - endTime
     */
    private static function validateIdentity(array $identity): array
    {
        $required = ['type', 'target', 'startDate', 'endDate', 'days', 'startTime', 'endTime'];
        $missing = [];

        foreach ($required as $k) {
            if (!array_key_exists($k, $identity) || $identity[$k] === '' || $identity[$k] === null) {
                $missing[] = $k;
            }
        }

        foreach (['startDate', 'endDate'] as $dateKey) {
            if (
                !isset($identity[$dateKey]) ||
                !is_array($identity[$dateKey]) ||
                (
                    empty($identity[$dateKey]['symbolic']) &&
                    empty($identity[$dateKey]['hard'])
                )
            ) {
                $missing[] = $dateKey;
            }
        }

        return [
            'ok' => empty($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Build and validate canonical identity for an entry.
     *
     * This is the ONLY public way to obtain canonical identity fields.
     * Callers must treat ok=false as a hard stop and must not schedule/diff/adopt.
     *
     * @return array{ok: bool, identity: array<string,mixed>, missing: string[]}
     */
    public static function buildIdentity(array $entry): array
    {
        $identity = self::extractIdentity($entry);
        $v = self::validateIdentity($identity);

        if (!$v['ok']) {
            // Identity validation is authoritative only during diff/apply.
            // Planner, preview, and inventory paths legitimately operate on
            // partial structures and must not emit errors.
            if (defined('GCS_STRICT_IDENTITY') && GCS_STRICT_IDENTITY) {
                error_log('[GCS][IDENTITY INVALID] ' . json_encode([
                    'summary' => $entry['summary'] ?? null,
                    'uid' => $entry['uid'] ?? null,
                    'missing' => $v['missing'],
                    'entry_keys' => array_keys($entry),
                    'identity' => $identity,
                ], JSON_UNESCAPED_SLASHES));
            }
        }

        return [
            'ok' => (bool)$v['ok'],
            'identity' => $identity,
            'missing' => $v['missing'],
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
        $res = self::buildIdentity($entry);
        if (!$res['ok']) {
            return '';
        }

        return hash(
            'sha256',
            json_encode($res['identity'], JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Build a hash representing the full behavioral intent of the entry.
     *
     * This is used for change detection.
     */
    public static function buildHash(array $entry): string
    {
        $res = self::buildIdentity($entry);
        if (!$res['ok']) {
            return '';
        }

        $cacheKey = json_encode($res['identity'], JSON_UNESCAPED_SLASHES);

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
     * Build a ManifestIdentity from a single intent.
     *
     * This is a thin adapter used by SchedulerSync so it does not
     * need to know about bulk/series identity construction.
     *
     * @param array $intent
     * @return array{ids: string[], hashes: string[]}
     */
    public static function fromIntent(array $intent): array
    {
        return self::fromSeries([$intent]);
    }

    /**
     * Build a ManifestIdentity from a series of intents.
     *
     * @param array[] $series
     * @return array{ids: string[], hashes: string[]}
     */
    public static function fromSeries(array $series): array
    {
        $ids = [];
        $hashes = [];

        foreach ($series as $entry) {
            $id = self::buildId($entry);
            $hash = self::buildHash($entry);

            if ($id === '' || $hash === '') {
                if (defined('GCS_DEBUG') && GCS_DEBUG) {
                    error_log('[GCS DEBUG][IDENTITY][DROP SERIES ENTRY] ' . json_encode([
                        'entry_keys' => is_array($entry) ? array_keys($entry) : null,
                    ], JSON_UNESCAPED_SLASHES));
                }
                continue;
            }

            $ids[] = $id;
            $hashes[] = $hash;
        }

        return [
            'ids' => $ids,
            'hashes' => $hashes,
        ];
    }

    /**
     * Build a ManifestIdentity from array data.
     *
     * @param array $data
     * @return array{ids: string[], hashes: string[]}
     */
    public static function fromArray(array $data): array
    {
        return [
            'ids' => $data['ids'] ?? [],
            'hashes' => $data['hashes'] ?? [],
        ];
    }

    /**
     * Return the primary ID from a manifest array.
     *
     * @param array{ids: string[], hashes: string[]} $manifest
     * @return string
     */
    public static function primaryId(array $manifest): string
    {
        return $manifest['ids'][0] ?? '';
    }

    /**
     * Return the primary hash from a manifest array.
     *
     * @param array{ids: string[], hashes: string[]} $manifest
     * @return string
     */
    public static function primaryHash(array $manifest): string
    {
        return $manifest['hashes'][0] ?? '';
    }

    /**
     * Compare primary hashes of two manifests for equality.
     *
     * @param array{ids: string[], hashes: string[]} $a
     * @param array{ids: string[], hashes: string[]} $b
     * @return bool
     */
    public static function sameHashAs(array $a, array $b): bool
    {
        return self::primaryHash($a) === self::primaryHash($b);
    }

    /**
     * Debug-only helper.
     *
     * Returns a minimal representation useful for diff diagnostics.
     * This must not affect identity behavior.
     *
     * @param array{ids: string[], hashes: string[]} $manifest
     * @return array{id: string, hash: string}
     */
    public static function toDebugArray(array $manifest): array
    {
        return [
            'id' => self::primaryId($manifest),
            'hash' => self::primaryHash($manifest),
        ];
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
}
