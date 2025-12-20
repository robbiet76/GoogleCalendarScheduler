<?php

/**
 * YAML metadata parser with schema validation and warning aggregation.
 *
 * PHASE 11 CONTRACT:
 * - Schema is explicit and locked
 * - Unknown keys generate warnings
 * - Invalid value types generate warnings
 * - Warnings are aggregated and summarized per sync
 */
final class GcsYamlMetadata
{
    private const SCHEMA = [
        'type'       => 'string',
        'stopType'   => 'string',
        'repeat'     => 'string',
        'override'   => 'bool',

        'command'          => 'string',
        'args'             => 'array',
        'multisyncCommand' => 'bool',
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
        if ($yamlText === null) {
            return [];
        }

        $parsed = self::parseSimpleYaml($yamlText);
        if (!is_array($parsed)) {
            self::warn('yaml_parse_failed', [
                'yaml' => $yamlText,
            ], $context);
            return [];
        }

        $out = [];

        foreach ($parsed as $key => $value) {
            if (!array_key_exists($key, self::SCHEMA)) {
                self::warn('unknown_key', [
                    'key' => $key,
                ], $context);
                continue;
            }

            $expected = self::SCHEMA[$key];
            if (!self::isValidType($value, $expected)) {
                self::warn('invalid_type', [
                    'key'      => $key,
                    'expected' => $expected,
                    'actual'   => gettype($value),
                ], $context);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private static function extractYaml(string $text): ?string
    {
        if (preg_match('/```yaml(.*?)```/s', $text, $m)) {
            return trim($m[1]);
        }

        $pos = strpos($text, 'fpp:');
        if ($pos !== false) {
            return substr($text, $pos);
        }

        // ---------------------------------------------------------
        // FIX (Phase 12.1):
        // Top-level YAML without wrapper → entire description
        // ---------------------------------------------------------
        return trim($text);
    }

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

            if ($currentKey !== null && preg_match('/^\s*-\s*(.+)$/', $line, $m)) {
                if (!isset($data[$currentKey]) || !is_array($data[$currentKey])) {
                    $data[$currentKey] = [];
                }
                $data[$currentKey][] = self::castValue($m[1]);
                continue;
            }

            if (preg_match('/^([a-zA-Z0-9_]+)\s*:\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $val = $m[2];

                if ($val === '') {
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

    private static function castValue(string $val)
    {
        $v = trim($val);

        if ($v === 'true') return true;
        if ($v === 'false') return false;
        if (is_numeric($v)) return (int)$v;

        return $v;
    }

    private static function isValidType($value, string $expected): bool
    {
        return match ($expected) {
            'string' => is_string($value),
            'bool'   => is_bool($value),
            'array'  => is_array($value),
            default  => false,
        };
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
