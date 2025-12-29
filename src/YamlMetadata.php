<?php
declare(strict_types=1);

/**
 * GcsYamlMetadata
 *
 * Phase 21 (clean):
 * - Read-only YAML extraction from calendar event description text
 * - NO scheduler knowledge
 * - NO recurrence logic
 * - NO defaults beyond basic normalization
 *
 * Contract:
 * - If no valid YAML block is present, return []
 * - Never throw
 * - Never mutate input
 */
final class GcsYamlMetadata
{
    /**
     * Parse YAML metadata from a calendar event description.
     *
     * @param string|null $description Raw description text from calendar event
     * @param array<string,mixed> $context Optional context for logging (uid, start, etc)
     * @return array<string,mixed> Parsed YAML metadata (normalized) or empty array
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

        // Extract YAML candidate text
        $yamlText = self::extractYamlBlock($description);
        if ($yamlText === null) {
            return [];
        }

        // Parse using lightweight safe parser (no ext-yaml dependency)
        try {
            $parsed = self::parseYamlBlock($yamlText);
            if (!is_array($parsed) || empty($parsed)) {
                return [];
            }

            return self::normalize($parsed);
        } catch (Throwable $e) {
            // Never allow YAML parsing to affect scheduler behavior
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
     * 2) Raw YAML at start of description:
     *    stopType: hard
     *    repeat: 10
     *
     * @return string|null
     */
    private static function extractYamlBlock(string $text): ?string
    {
        // --- Case 1: fenced ```yaml block ---
        if (preg_match('/```yaml\s*(.*?)\s*```/is', $text, $m)) {
            $candidate = trim($m[1]);
            return ($candidate !== '') ? $candidate : null;
        }

        // --- Case 2: raw YAML-like lines at top ---
        $lines = preg_split('/\r?\n/', $text);
        if (!$lines) {
            return null;
        }

        $yamlLines = [];
        foreach ($lines as $line) {
            $line = rtrim($line);

            // Stop if we hit a non-YAML-looking line
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

        if (empty($yamlLines)) {
            return null;
        }

        return implode("\n", $yamlLines);
    }

    /**
     * Lightweight YAML parser for Phase 21.
     *
     * Supported:
     * - flat key: value pairs
     * - scalar values only
     * - integers, booleans, strings
     *
     * Explicitly NOT supported:
     * - nesting
     * - arrays
     * - multiline blocks
     * - anchors, tags, etc.
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

            // Expect simple key: value
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
                } else {
                    $value = $value;
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
     * - Scalars normalized (bool/int/string)
     * - Arrays preserved as-is
     *
     * NO scheduler-specific interpretation here.
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
        if (is_bool($v)) {
            return $v;
        }

        if (is_int($v)) {
            return $v;
        }

        if (is_float($v)) {
            return $v;
        }

        if (is_string($v)) {
            return trim($v);
        }

        if (is_array($v)) {
            return $v;
        }

        // Unsupported type → ignore
        return null;
    }
}
