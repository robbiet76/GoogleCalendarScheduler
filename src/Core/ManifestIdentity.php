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

    /** True if token looks like a hard date YYYY-MM-DD. */
    private static function isHardDateToken(string $token): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $token);
    }

    /**
     * Build a dual-date token representation from an entry token.
     *
     * Contract rules:
     * - Symbolic holiday input -> tokens={Holiday} (no hard back-fill)
     * - Hard date input -> tokens include hard date; if it maps to a holiday, also include that holiday token
     * - Keep both hard + symbolic when available (for hashing + persistence)
     *
     * Return shape:
     *   [
     *     'tokens'   => string[],      // unique, stable order
     *     'hard'     => string|null,   // YYYY-MM-DD if present
     *     'symbolic' => string|null,   // Holiday token if present/derivable
     *   ]
     */
    private static function buildDualDate(string $raw, array &$warnings, string $context): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['tokens' => [], 'hard' => null, 'symbolic' => null];
        }

        // FPPSemantics::interpretDateToken is authoritative for deriving holiday tokens from hard dates.
        // It MUST NOT create hard dates from symbolic-only inputs.
        $parsed = FPPSemantics::interpretDateToken($raw, null, $warnings, $context);

        $hard = null;
        $symbolic = null;

        if (is_array($parsed)) {
            if (!empty($parsed['hard'])) {
                $hard = (string)$parsed['hard'];
            }
            if (!empty($parsed['symbolic'])) {
                $symbolic = (string)$parsed['symbolic'];
            }
        }

        // Fallback if interpretDateToken returned a plain string (defensive, but no "legacy" behavior introduced)
        if ($hard === null && $symbolic === null) {
            if (self::isHardDateToken($raw)) {
                $hard = $raw;
            } else {
                $symbolic = $raw;
            }
        }

        $tokens = [];
        if ($hard !== null && $hard !== '') {
            $tokens[] = $hard;
        }
        if ($symbolic !== null && $symbolic !== '') {
            $tokens[] = $symbolic;
        }

        // Unique + stable sort for deterministic JSON/hashing.
        $tokens = array_values(array_unique($tokens));
        sort($tokens, SORT_STRING);

        return [
            'tokens' => $tokens,
            'hard' => $hard,
            'symbolic' => $symbolic,
        ];
    }

    /**
     * For identity KEY construction only:
     * prefer symbolic holiday token when available, else use hard date.
     *
     * This makes:
     *   Christmas  -> key=Christmas
     *   2025-12-25 -> key=Christmas (if mapped), else key=2025-12-25
     */
    private static function identityDateKey(array $dualDate): string
    {
        if (!empty($dualDate['symbolic'])) {
            return (string)$dualDate['symbolic'];
        }
        if (!empty($dualDate['hard'])) {
            return (string)$dualDate['hard'];
        }
        return '';
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

        // Dates: dual-date token sets (symbolic + hard when derivable from hard date)
        $startDateRaw = $entry['startDate'] ?? ($entry['range']['start'] ?? null);
        $endDateRaw   = $entry['endDate']   ?? ($entry['range']['end']   ?? null);

        if ($startDateRaw !== null && $startDateRaw !== '') {
            $identity['startDate'] = self::buildDualDate(
                (string)$startDateRaw,
                $warnings,
                'manifest.startDate'
            );
        }

        if ($endDateRaw !== null && $endDateRaw !== '') {
            $identity['endDate'] = self::buildDualDate(
                (string)$endDateRaw,
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

        // Times (identity-safe: store as array [token, offset])
        $startTimeTokenRaw = isset($entry['startTime']) ? (string)$entry['startTime'] : '';
        $startTimeToken = FPPSemantics::canonicalTimeToken($startTimeTokenRaw);
        $startTimeOffset = isset($entry['startTimeOffset']) && is_numeric($entry['startTimeOffset'])
            ? (int)$entry['startTimeOffset']
            : 0;
        $identity['startTime'] = [
            'token' => $startTimeToken,
            'offset' => $startTimeOffset,
        ];

        $endTimeTokenRaw = isset($entry['endTime']) ? (string)$entry['endTime'] : '';
        $endTimeToken = FPPSemantics::canonicalTimeToken($endTimeTokenRaw);
        $endTimeOffset = isset($entry['endTimeOffset']) && is_numeric($entry['endTimeOffset'])
            ? (int)$entry['endTimeOffset']
            : 0;
        $identity['endTime'] = [
            'token' => $endTimeToken,
            'offset' => $endTimeOffset,
        ];

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
                empty($identity[$dateKey]['tokens']) ||
                !is_array($identity[$dateKey]['tokens'])
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
            $strict = false;
            if (defined('GCS_STRICT_IDENTITY')) {
                $strict = (bool)constant('GCS_STRICT_IDENTITY');
            }

            // Always log diagnostic info for missing fields, regardless of strictness
            error_log('[GCS][IDENTITY DIAGNOSTIC][MISSING_FIELDS] ' . json_encode([
                'missing' => $v['missing'],
                'identity_snapshot' => array_intersect_key($identity, array_flip($v['missing'])), // show missing fields values
                'identity_compact' => $identity,
                'entry_hint' => [
                    'playlist' => $entry['playlist'] ?? null,
                    'command' => $entry['command'] ?? null,
                    'startDate' => $entry['startDate'] ?? ($entry['range']['start'] ?? null),
                    'endDate' => $entry['endDate'] ?? ($entry['range']['end'] ?? null),
                    'days' => $entry['days'] ?? ($entry['range']['days'] ?? ($entry['day'] ?? null)),
                    'startTime' => $entry['startTime'] ?? null,
                    'endTime' => $entry['endTime'] ?? null,
                ],
            ], JSON_UNESCAPED_SLASHES));

            if ($strict) {
                error_log('[GCS][IDENTITY INVALID] ' . json_encode([
                    'summary' => $entry['summary'] ?? null,
                    'uid' => $entry['uid'] ?? null,
                    'missing' => $v['missing'],
                    'entry_keys' => array_keys($entry),
                    // Include a few top-level fields that commonly drive identity.
                    'entry_hint' => [
                        'type' => $entry['type'] ?? null,
                        'playlist' => $entry['playlist'] ?? null,
                        'command' => $entry['command'] ?? null,
                        'startDate' => $entry['startDate'] ?? ($entry['range']['start'] ?? null),
                        'endDate' => $entry['endDate'] ?? ($entry['range']['end'] ?? null),
                        'days' => $entry['days'] ?? ($entry['range']['days'] ?? ($entry['day'] ?? null)),
                        'startTime' => $entry['startTime'] ?? null,
                        'endTime' => $entry['endTime'] ?? null,
                    ],
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
     * Canonical identity key fields (used for stable ID).
     *
     * Contract: identity matching is symbolic-first for dates.
     * We encode dates using identityDateKey() so that:
     * - Symbolic-only matches hard+symbolic representations
     * - Cross-year holiday hard dates map to the same identity
     */
    private static function canonicalIdentityKey(array $identity): array
    {
        return [
            'type' => $identity['type'] ?? '',
            'target' => $identity['target'] ?? '',
            'days' => $identity['days'] ?? '',
            'startTime' => isset($identity['startTime']) ? self::stableTimeString($identity['startTime']) : '',
            'endTime' => isset($identity['endTime']) ? self::stableTimeString($identity['endTime']) : '',
            'startDate' => isset($identity['startDate']) ? self::identityDateKey($identity['startDate']) : '',
            'endDate' => isset($identity['endDate']) ? self::identityDateKey($identity['endDate']) : '',
        ];
    }

    /**
     * Serialize a time identity array as "token@offset" (offset omitted or 0 => "@0").
     */
    private static function stableTimeString($time): string
    {
        if (!is_array($time)) {
            $token = (string)$time;
            $offset = 0;
        } else {
            $token = isset($time['token']) ? (string)$time['token'] : '';
            $offset = isset($time['offset']) ? (int)$time['offset'] : 0;
        }
        // Always include the offset for stability
        return $token . '@' . $offset;
    }

    /**
     * Build a stable manifest ID for an entry.
     *
     * This ID represents the schedule identity:
     * type + target + time window + date range + days.
     *
     * NOTE: This ID must remain stable even when non-identity behavior changes.
     */
    public static function buildId(array $entry): string
    {
        $res = self::buildIdentity($entry);
        if (!$res['ok']) {
            return '';
        }

        $key = self::canonicalIdentityKey($res['identity']);
        $json = json_encode($key, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }

        if (defined('GCS_DEBUG') && GCS_DEBUG) {
            error_log('[GCS DEBUG][IDENTITY_ID_INPUT] ' . $json);
        }

        return hash('sha256', $json);
    }

    /**
     * Build a hash representing the full behavioral intent of the entry.
     *
     * This is used for change detection. Unlike buildId(), this includes
     * non-identity fields like enabled/repeat/offsets/stopType, etc.
     */
    public static function buildHash(array $entry): string
    {
        // Identity must be complete; otherwise the entry is not managed/diffable.
        $res = self::buildIdentity($entry);
        if (!$res['ok']) {
            return '';
        }

        // Contract: hash input includes canonical identity fields PLUS behavioral parameters,
        // and MUST include BOTH hard+symbolic components when present on the entry.
        $identity = $res['identity'];

        $hashIdentity = [
            'type' => $identity['type'] ?? '',
            'target' => $identity['target'] ?? '',
            'days' => $identity['days'] ?? '',
            'startTime' => [
                'token' => $identity['startTime']['token'] ?? '',
                'offset' => $identity['startTime']['offset'] ?? 0,
            ],
            'endTime' => [
                'token' => $identity['endTime']['token'] ?? '',
                'offset' => $identity['endTime']['offset'] ?? 0,
            ],
            'startDate' => $identity['startDate']['tokens'] ?? [],
            'endDate' => $identity['endDate']['tokens'] ?? [],
        ];

        $behavior = self::normalize($entry);

        // Strip resolved/display-only artifacts so hash reflects intent, not presentation
        unset(
            $behavior['resolved'],
            $behavior['resolvedStartTime'],
            $behavior['resolvedEndTime'],
            $behavior['displayStart'],
            $behavior['displayEnd']
        );

        // Ensure date fields in behavior do not accidentally override the contract representation.
        unset($behavior['startDate'], $behavior['endDate']);

        $payload = [
            'identity' => $hashIdentity,
            'behavior' => $behavior,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }

        if (defined('GCS_DEBUG') && GCS_DEBUG) {
            error_log('[GCS DEBUG][PAYLOAD_HASH_INPUT] ' . $json);
        }

        return hash('sha256', $json);
    }

    /**
     * Build manifest identity fields from a single schedule entry.
     *
     * Returns array-form fields used throughout the codebase:
     * - ids[0]    => stable identity id (symbolic-first date keys)
     * - hashes[0] => behavioral payload hash (includes dual-date tokens when present)
     */
    public static function fromScheduleEntry(array $entry): array
    {
        $id = self::buildId($entry);
        $hash = self::buildHash($entry);

        if ($id === '' || $hash === '') {
            // Diagnostics for empty id or hash
            $buildIdentityResult = self::buildIdentity($entry);
            error_log('[GCS][IDENTITY][SCHEDULE_ENTRY_EMPTY_ID_OR_HASH] ' . json_encode([
                'reason' => 'empty id or hash',
                'entry_keys' => array_keys($entry),
                'buildIdentity_ok' => $buildIdentityResult['ok'] ?? null,
                'buildIdentity_missing' => $buildIdentityResult['missing'] ?? null,
            ], JSON_UNESCAPED_SLASHES));
            return [
                'ids' => [],
                'hashes' => [],
            ];
        }

        return [
            'ids' => [$id],
            'hashes' => [$hash],
        ];
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
                    error_log('[GCS DEBUG][IDENTITY][DROP_ENTRY_MISSING_FIELDS] ' . json_encode([
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
            $normalized['identity'],
            $normalized['summary'],
            $normalized['description'],
            $normalized['range'],
            $normalized['template'],
            $normalized['resolved'],
            $normalized['yaml'],
            $normalized['gcs'],
            $normalized['order'],
            $normalized['appliedAt']
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
