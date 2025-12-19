<?php

/**
 * Simple, foolproof YAML-like metadata parser for Google Calendar descriptions.
 *
 * Design goals:
 * - Extremely tolerant of formatting
 * - No required parent key
 * - Flat keys preferred (e.g. stoptype)
 * - Safe defaults when fields are missing or invalid
 * - Explicit logging of ignored / unsupported keys
 *
 * Supported keys (case-insensitive):
 *   type       : playlist | sequence | command
 *   enabled    : true | false
 *   stoptype   : graceful | graceful_loop | hard
 *   repeat     : none | immediate | <number>
 *
 * Legacy support (accepted but discouraged):
 *   stop:
 *     type: graceful | graceful_loop | hard
 *
 * Unsupported keys are ignored and logged.
 */
final class YamlMetadata
{
    /**
     * Parse YAML-like metadata from an event description.
     *
     * @return array<string,mixed>|null
     */
    public static function parse(?string $description): ?array
    {
        if (!$description) {
            return null;
        }

        // Normalize Google Calendar NBSP → space
        $description = str_replace("\xC2\xA0", ' ', $description);

        $lines = preg_split('/\R/', $description);
        if (!$lines) {
            return null;
        }

        $out = [];

        $inFppBlock  = false; // optional legacy parent
        $inStopBlock = false;

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            // Optional legacy parent
            if (strcasecmp($line, 'fpp:') === 0) {
                $inFppBlock  = true;
                $inStopBlock = false;
                continue;
            }

            // Legacy nested stop block
            if (strcasecmp($line, 'stop:') === 0) {
                $inStopBlock = true;
                continue;
            }

            // --------------------------------------------------
            // Flat stoptype (preferred)
            // --------------------------------------------------
            if (preg_match('/^stoptype\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if (in_array($v, ['graceful', 'graceful_loop', 'hard'], true)) {
                    $out['stopType'] = $v;
                } else {
                    GcsLog::info('Ignored invalid stoptype', ['value' => $m[1]]);
                }
                continue;
            }

            // --------------------------------------------------
            // Legacy stop.type
            // --------------------------------------------------
            if ($inStopBlock && preg_match('/^type\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if (in_array($v, ['graceful', 'graceful_loop', 'hard'], true)) {
                    $out['stopType'] = $v;
                    GcsLog::info(
                        'Applied legacy stop.type (use stoptype instead)',
                        ['value' => $v]
                    );
                } else {
                    GcsLog::info(
                        'Ignored invalid legacy stop.type',
                        ['value' => $m[1]]
                    );
                }
                continue;
            }

            // Exit legacy stop block
            if ($inStopBlock && preg_match('/^\S+:/', $line)) {
                $inStopBlock = false;
            }

            // --------------------------------------------------
            // type (behavior-only)
            // --------------------------------------------------
            if (preg_match('/^type\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if (in_array($v, ['playlist', 'sequence', 'command'], true)) {
                    $out['type'] = $v;
                } else {
                    GcsLog::info('Ignored invalid type', ['value' => $m[1]]);
                }
                continue;
            }

            // --------------------------------------------------
            // enabled
            // --------------------------------------------------
            if (preg_match('/^enabled\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if (in_array($v, ['true', 'yes', '1'], true)) {
                    $out['enabled'] = true;
                } elseif (in_array($v, ['false', 'no', '0'], true)) {
                    $out['enabled'] = false;
                } else {
                    GcsLog::info('Ignored invalid enabled value', ['value' => $m[1]]);
                }
                continue;
            }

            // --------------------------------------------------
            // repeat
            // --------------------------------------------------
            if (preg_match('/^repeat\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if ($v === 'none' || $v === '') {
                    $out['repeat'] = 'none';
                } elseif ($v === 'immediate') {
                    $out['repeat'] = 'immediate';
                } elseif (ctype_digit($v)) {
                    $out['repeat'] = $v;
                } else {
                    GcsLog::info('Ignored invalid repeat value', ['value' => $m[1]]);
                }
                continue;
            }

            // --------------------------------------------------
            // Explicitly unsupported keys
            // --------------------------------------------------
            if (preg_match('/^(target|priority|day|date)\s*:/i', $line, $m)) {
                GcsLog::info(
                    'Ignored unsupported YAML key',
                    ['key' => strtolower($m[1])]
                );
                continue;
            }
        }

        if (!empty($out)) {
            GcsLog::info('Applied YAML metadata', $out);
            return $out;
        }

        return null;
    }
}
