<?php

/**
 * YAML metadata parser with schema validation and warning aggregation.
 *
 * PHASE 11 CONTRACT (re-affirmed / tightened in Phase 21 patch):
 * - YAML is OPTIONAL
 * - YAML must be explicitly opted into via ```yaml fenced block OR an explicit anchor line
 *   OR contain at least one recognized schema key at the start of a line (to avoid false positives)
 * - Schema is explicit and locked
 * - Unknown keys generate warnings
 * - Invalid value types generate warnings
 * - Warnings are aggregated and summarized per sync
 *
 * NOTE: This parser is intentionally conservative; it should NOT treat arbitrary descriptions as YAML.
 */
final class GcsYamlMetadata
{
    /**
     * Internal canonical keys (what we output).
     * We accept several aliases (snake_case / lowercase) for user convenience.
     */
    private const SCHEMA = [
        'enabled'          => 'bool',

        'type'             => 'string',
        'stopType'         => 'string',
        'repeat'           => 'string|int',

        // legacy / experimental (keep for backward compatibility if used elsewhere)
        'override'         => 'bool',

        'command'          => 'string',
        'args'             => 'array',
        'multisyncCommand' => 'bool',
    ];

    /**
     * Accepted YAML keys (user-facing) -> internal canonical key
     * Keep this list in sync with docs/examples.
     */
    private const KEY_ALIASES = [
        // canonical
        'enabled'           => 'enabled',
        'type'              => 'type',
        'stopType'          => 'stopType',
        'repeat'            => 'repeat',
        'override'          => 'override',
        'command'           => 'command',
        'args'              => 'args',
        'multisyncCommand'  => 'multisyncCommand',

        // user-friendly variants (docs / common)
        'stoptype'          => 'stopType',
        'stop_type'         => 'stopType',
        'multisynccommand'  => 'multisyncCommand',
        'multisync_command' => 'multisyncCommand',
    ];

    /** @var array<int,array<string,mixed>> */
    private static array $warnings = [];

    /**
     * Parse YAML metadata from an event description.
     *
     * @param string|null $text
     * @param array<string,string>|null $context
     * @return array<string,mixed>
     */
    public static function parse(?string $text, ?array $context = null): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        // Normalize Google Calendar escaped newlines
        $text = str_replace("\\n", "\n", $text);

        $yamlText = self::extractYaml($text);
        if ($yamlText === null || trim($yamlText) === '') {
            return [];
        }

        $parsed = self::parseSimpleYaml($yamlText);

        // Our simple parser always returns an array; "parse failed" means "nothing useful found".
        if (!is_array($parsed)) {
            self::warn('yaml_parse_failed', ['yaml' => $yamlText], $context);
            return [];
        }

        $out = [];
        $recognizedCount = 0;
        $hadKeyLikeLine = self::looksLikeYaml($yamlText);

        foreach ($parsed as $key => $value) {
            $canonicalKey = self::canonicalizeKey($key);
            if ($canonicalKey === null) {
                self::warn('unknown_key', ['key' => $key], $context);
                continue;
            }

            $expected = self::SCHEMA[$canonicalKey] ?? null;
            if ($expected === null) {
                // Should not happen if KEY_ALIASES is consistent with SCHEMA, but keep safe.
                self::warn('unknown_key', ['key' => $key], $context);
                continue;
            }

            if (!self::isValidType($value, $expected)) {
                self::warn('invalid_type', [
                    'key'      => $key,
                    'expected' => $expected,
                    'actual'   => gettype($value),
                ], $context);
                continue;
            }

            $out[$canonicalKey] = $value;
            $recognizedCount++;
        }

        // If we explicitly extracted YAML (fenced/anchored) or it looked like YAML, but we got nothing,
        // emit a warning so users understand why nothing applied.
        if ($recognizedCount === 0 && $hadKeyLikeLine) {
            self::warn('yaml_parse_failed', [
                'reason' => 'no_recognized_keys',
            ], $context);
        }

        return $out;
    }

    /**
     * Extract YAML from an event description conservatively.
     *
     * Accepted forms:
     * 1) ```yaml ... ```
     * 2) Anchor line "fpp:" or "gcs:" (keeps compatibility and allows explicit opt-in without fences)
     * 3) If neither is present, only treat as YAML if it contains at least one recognized key at start-of-line.
     */
    private static function extractYaml(string $text): ?string
    {
        // 1) Fenced YAML block
        if (preg_match('/```yaml(.*?)```/s', $text, $m)) {
            return trim($m[1]);
        }

        // 2) Explicit anchors (opt-in without fences)
        foreach (['fpp:', 'gcs:'] as $anchor) {
            $pos = stripos($text, $anchor);
            if ($pos !== false) {
                // Keep from the anchor onward
                return trim(substr($text, $pos));
            }
        }

        // 3) Conservative implicit detection: only if at least one recognized key appears at line start.
        if (self::containsRecognizedKeyLine($text)) {
            return trim($text);
        }

        return null;
    }

    /**
     * Very small YAML subset:
     * - key: value
     * - key:
     *     - item
     *     - item
     *
     * Returns an associative array of parsed keys. Unknown lines are ignored.
     */
    private static function parseSimpleYaml(string $yaml): array
    {
        $lines = preg_split('/\r?\n/', $yaml);
        $data  = [];
        $currentKey = null;

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            // list item
            if ($currentKey !== null && preg_match('/^\s*-\s*(.+)$/', $line, $m)) {
                if (!isset($data[$currentKey]) || !is_array($data[$currentKey])) {
                    $data[$currentKey] = [];
                }
                $data[$currentKey][] = self::castValue($m[1]);
                continue;
            }

            // key: value (allow common key chars: letters, numbers, underscore)
            if (preg_match('/^([a-zA-Z0-9_]+)\s*:\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $val = $m[2];

                if ($val === '') {
                    // Only allow empty value → array mode for keys that support list values (args).
                    // Otherwise we still set it, and type validation will flag it.
                    $data[$key] = [];
                    $currentKey = $key;
                } else {
                    $data[$key] = self::castValue($val);
                    $currentKey = null;
                }
            }
        }

        return $data;
    }

    /**
     * Cast scalars conservatively.
     * NOTE: We intentionally only recognize lowercase true/false to avoid surprise.
     */
    private static function castValue(string $val)
    {
        $v = trim($val);

        if ($v === 'true') return true;
        if ($v === 'false') return false;

        // ints only (avoid turning "01" into 1 unexpectedly? keep as int anyway for args/repeat)
        if (preg_match('/^-?\d+$/', $v)) return (int)$v;

        return $v;
    }

    /**
     * Determine if a blob likely intends to be YAML.
     */
    private static function looksLikeYaml(string $text): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9_]+\s*:/m', $text);
    }

    /**
     * Checks if the text contains at least one recognized key at start-of-line (with :) so we can
     * safely treat the description as YAML without requiring fences.
     */
    private static function containsRecognizedKeyLine(string $text): bool
    {
        $keys = array_keys(self::KEY_ALIASES);
        // Build a conservative regex like: ^(enabled|type|stoptype|...)\s*:
        $pattern = '/^(' . implode('|', array_map('preg_quote', $keys)) . ')\s*:/mi';
        return (bool)preg_match($pattern, $text);
    }

    /**
     * Map a user-provided key to our internal canonical key.
     */
    private static function canonicalizeKey(string $key): ?string
    {
        // exact match first
        if (isset(self::KEY_ALIASES[$key])) {
            return self::KEY_ALIASES[$key];
        }

        // case-insensitive match (Calendar edits sometimes change case; be forgiving)
        $lower = strtolower($key);
        if (isset(self::KEY_ALIASES[$lower])) {
            return self::KEY_ALIASES[$lower];
        }

        return null;
    }

    private static function isValidType($value, string $expected): bool
    {
        // Support unions like "string|int"
        $parts = explode('|', $expected);

        foreach ($parts as $t) {
            $t = trim($t);
            $ok = match ($t) {
                'string' => is_string($value),
                'bool'   => is_bool($value),
                'array'  => is_array($value),
                'int'    => is_int($value),
                default  => false,
            };
            if ($ok) return true;
        }

        return false;
    }

    private static function warn(string $code, array $details, ?array $context): void
    {
        $entry = [
            'code'    => $code,
            'details' => $details,
        ];

        if ($context) {
            $entry['event'] = $context;
        }

        self::$warnings[] = $entry;
        GcsLog::warn('YAML metadata warning', $entry);
    }

    public static function flushWarnings(): void
    {
        if (empty(self::$warnings)) {
            return;
        }

        $byType = [];
        foreach (self::$warnings as $w) {
            $byType[$w['code']] = ($byType[$w['code']] ?? 0) + 1;
        }

        GcsLog::warn('YAML metadata warnings summary', [
            'total'  => count(self::$warnings),
            'byType' => $byType,
        ]);

        self::$warnings = [];
    }
}
