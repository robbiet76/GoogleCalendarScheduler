<?php
declare(strict_types=1);

/**
 * GcsYamlMetadata
 *
 * Lightweight, read-only YAML metadata extractor for calendar events.
 *
 * Responsibilities:
 * - Extract a small YAML block from event description text
 * - Parse flat key/value metadata safely
 * - Normalize scalar values for downstream consumers
 *
 * HARD GUARANTEES:
 * - Never throws
 * - Never mutates input
 * - No scheduler knowledge
 * - No recurrence or intent logic
 *
 * If no valid YAML metadata is present, an empty array is returned.
 */
final class GcsYamlMetadata
{
    /**
     * Parse YAML metadata from a calendar event description.
     *
     * @param string|null $description Raw description text from calendar event
     * @param array<string,mixed> $context Optional context (reserved for logging/debug)
     * @return array<string,mixed> Normalized YAML metadata or empty array
     */
    public static function parse(?string $description, array $context = []): array
    {
        if ($description === null) {
            return [];
        }

        $description = trim($description);
        if ($description === '') {
            return [];
        }

        // Extract candidate YAML text
        $yamlText = self::extractYamlBlock($description);
        if ($yamlText === null) {
            return [];
        }

        // Parse using a safe, minimal parser
        try {
            $parsed = self::parseYamlBlock($yamlText);
            if (!is_array($parsed) || empty($parsed)) {
                return [];
            }

            return self::normalize($parsed);
        } catch (Throwable) {
            // YAML parsing must never affect scheduler behavior
            return [];
        }
    }

    /**
     * Attempt to extract a YAML block from description text.
     *
     * Supported formats:
     *
     * 1) Explicit fenced block:
     *    ```yaml
     *    stopType: hard
     *    repeat: 10
     *    ```
     *
     * 2) Raw YAML at the start of the description:
     *    stopType: hard
     *    repeat: 10
     *
     * @return string|null Extracted YAML text or null if not found
     */
    private static function extractYamlBlock(string $text): ?string
    {
        // Case 1: fenced ```yaml block
        if (preg_match('/```yaml\s*(.*?)\s*```/is', $text, $m)) {
            $candidate = trim($m[1]);
            return ($candidate !== '') ? $candidate : null;
        }

        // Case 2: raw YAML-like lines at top of description
        $lines = preg_split('/\r?\n/', $text);
        if (!$lines) {
            return null;
        }

        $yamlLines = [];
        foreach ($lines as $line) {
            $line = rtrim($line);

            if ($line === '') {
                if (!empty($yamlLines)) {
                    break;
                }
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_]+\s*:/', $line)) {
                break;
            }

            $yamlLines[] = $line;
        }

        return empty($yamlLines) ? null : implode("\n", $yamlLines);
    }

    /**
     * Minimal YAML parser.
     *
     * Supported:
     * - Flat key: value pairs
     * - Scalar values only (int, bool, string)
     *
     * Explicitly NOT supported:
     * - Nesting
     * - Arrays
     * - Multiline blocks
     * - Anchors, tags, or advanced YAML features
     *
     * @return array<string,mixed>
     */
    private static function parseYamlBlock(string $raw): array
    {
        $out = [];

        $lines = preg_split('/\r?\n/', $raw);
        if (!$lines) {
            return $out;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip blanks and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);

            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // Normalize scalar value
            if (ctype_digit($value)) {
                $value = (int)$value;
            } else {
                $lv = strtolower($value);
                if ($lv === 'true') {
                    $value = true;
                } elseif ($lv === 'false') {
                    $value = false;
                }
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Normalize parsed YAML values.
     *
     * Rules:
     * - Keys preserved verbatim
     * - Scalars normalized
     * - Unsupported types ignored
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function normalize(array $raw): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $out[$key] = self::normalizeValue($value);
        }

        return $out;
    }

    /**
     * Normalize a single YAML value.
     */
    private static function normalizeValue($v)
    {
        if (is_bool($v) || is_int($v) || is_float($v)) {
            return $v;
        }

        if (is_string($v)) {
            return trim($v);
        }

        if (is_array($v)) {
            return $v;
        }

        return null;
    }
}
