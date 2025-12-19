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
 *   type              : playlist | sequence | command
 *   enabled           : true | false
 *   stoptype          : graceful | graceful_loop | hard
 *   repeat            : none | immediate | <number>
 *
 * Command-only keys (Phase 10):
 *   command           : <string>            (required when type: command)
 *   args              : list or csv string  (optional; default [])
 *   multisynccommand  : true | false        (optional; default false)
 *
 * Legacy support (accepted but discouraged):
 *   stop:
 *     type: graceful | graceful_loop | hard
 *
 * Notes:
 * - Unknown keys are ignored.
 * - We intentionally do NOT try to validate command semantics or arg counts.
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

        $inStopBlock = false;
        $inArgsBlock = false;

        $args = null; // null until we see args, then array

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            // Optional legacy parent (ignored, but allows people to group)
            if (strcasecmp($line, 'fpp:') === 0) {
                continue;
            }

            // Legacy nested stop block
            if (strcasecmp($line, 'stop:') === 0) {
                $inStopBlock = true;
                $inArgsBlock = false;
                continue;
            }

            // Start args block (preferred list form)
            if (preg_match('/^args\s*:\s*$/i', $line)) {
                $inArgsBlock = true;
                $inStopBlock = false;
                if (!is_array($args)) {
                    $args = [];
                }
                continue;
            }

            // If we were in args block, accept "- value" lines.
            if ($inArgsBlock) {
                if (preg_match('/^\-\s*(.*)$/', $line, $m)) {
                    $v = self::normalizeScalar(trim($m[1]));
                    // Allow empty string ("-") or ("- ")
                    $args[] = $v;
                    continue;
                }

                // Stop args block when we hit a normal key
                if (preg_match('/^\S+\s*:/', $line)) {
                    $inArgsBlock = false;
                    // fall through to normal key parsing
                } else {
                    // junk line inside args block; ignore
                    continue;
                }
            }

            // Flat stoptype (preferred)
            if (preg_match('/^stoptype\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if (in_array($v, ['graceful', 'graceful_loop', 'hard'], true)) {
                    $out['stopType'] = $v;
                } else {
                    GcsLog::info('Ignored invalid stoptype', ['value' => $m[1]]);
                }
                $inStopBlock = false;
                continue;
            }

            // Legacy stop.type
            if ($inStopBlock && preg_match('/^type\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if (in_array($v, ['graceful', 'graceful_loop', 'hard'], true)) {
                    $out['stopType'] = $v;
                    GcsLog::info('Applied legacy stop.type (use stoptype instead)', [
                        'value' => $v,
                    ]);
                } else {
                    GcsLog::info('Ignored invalid legacy stop.type', ['value' => $m[1]]);
                }
                continue;
            }

            // type
            if (preg_match('/^type\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if (in_array($v, ['playlist', 'sequence', 'command'], true)) {
                    $out['type'] = $v;
                } else {
                    GcsLog::info('Ignored invalid type', ['value' => $m[1]]);
                }
                $inStopBlock = false;
                continue;
            }

            // enabled
            if (preg_match('/^enabled\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if (in_array($v, ['true', 'yes', '1'], true)) {
                    $out['enabled'] = true;
                } elseif (in_array($v, ['false', 'no', '0'], true)) {
                    $out['enabled'] = false;
                } else {
                    GcsLog::info('Ignored invalid enabled value', ['value' => $m[1]]);
                }
                $inStopBlock = false;
                continue;
            }

            // repeat
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
                $inStopBlock = false;
                continue;
            }

            // command (Phase 10)
            if (preg_match('/^command\s*:\s*(.+)$/i', $line, $m)) {
                $cmd = trim((string)$m[1]);
                if ($cmd !== '') {
                    $out['command'] = $cmd;
                } else {
                    GcsLog::info('Ignored empty command value');
                }
                $inStopBlock = false;
                continue;
            }

            // args (single-line csv fallback)
            if (preg_match('/^args\s*:\s*(.+)$/i', $line, $m)) {
                $raw = trim((string)$m[1]);
                if ($raw === '') {
                    $args = [];
                } else {
                    $parts = array_map('trim', explode(',', $raw));
                    $args = [];
                    foreach ($parts as $p) {
                        // preserve empty fields as empty string
                        $args[] = self::normalizeScalar($p);
                    }
                }
                $inStopBlock = false;
                continue;
            }

            // multisynccommand (Phase 10)
            if (preg_match('/^multisynccommand\s*:\s*(\S+)/i', $line, $m)) {
                $v = strtolower(trim($m[1]));
                if (in_array($v, ['true', 'yes', '1'], true)) {
                    $out['multisyncCommand'] = true;
                } elseif (in_array($v, ['false', 'no', '0'], true)) {
                    $out['multisyncCommand'] = false;
                } else {
                    GcsLog::info('Ignored invalid multisynccommand value', ['value' => $m[1]]);
                }
                $inStopBlock = false;
                continue;
            }

            // Explicitly unsupported keys (we log so users learn the boundary)
            if (preg_match('/^(target|priority|day|daymask|startdate|enddate|starttimeoffset|endtimeoffset)\s*:/i', $line, $m)) {
                GcsLog::info('Ignored unsupported YAML key', ['key' => strtolower($m[1])]);
                $inStopBlock = false;
                continue;
            }
        }

        if (is_array($args)) {
            $out['args'] = $args;
        }

        if (!empty($out)) {
            GcsLog::info('Applied YAML metadata', $out);
            return $out;
        }

        return null;
    }

    /**
     * Normalize scalars coming from YAML-like values:
     * - "null" => null
     * - strip surrounding quotes (single or double)
     * - otherwise return string as-is (including empty string)
     *
     * @param string $v
     * @return mixed
     */
    private static function normalizeScalar(string $v)
    {
        $t = trim($v);

        if (strcasecmp($t, 'null') === 0) {
            return null;
        }

        // strip surrounding quotes
        if (strlen($t) >= 2) {
            $first = $t[0];
            $last  = $t[strlen($t) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($t, 1, -1);
            }
        }

        return $t;
    }
}
