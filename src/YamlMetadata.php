<?php

/**
 * Minimal YAML metadata parser for Google Calendar events.
 *
 * Handles Google Calendar quirks:
 * - Non-breaking spaces (NBSP)
 * - Folded lines already unfolded upstream
 *
 * Supported schema:
 *
 * fpp:
 *   type: playlist|sequence|command
 *   stop:
 *     type: graceful|graceful_loop|hard
 *   repeat: none|immediate|5|10|15|20|30|60
 */
final class GcsYamlMetadata {

    public static function parse(?string $description): ?array {
        if (!$description) {
            return null;
        }

        // Normalize Google Calendar NBSP → space
        $description = str_replace("\xC2\xA0", ' ', $description);

        $lines = preg_split('/\R/', $description);

        $inFpp  = false;
        $inStop = false;
        $out    = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            if ($line === 'fpp:') {
                $inFpp  = true;
                $inStop = false;
                continue;
            }

            if (!$inFpp) {
                continue;
            }

            if ($line === 'stop:') {
                $inStop = true;
                continue;
            }

            // stop.type
            if ($inStop && preg_match('/^type:\s*(\S+)/', $line, $m)) {
                $valid = ['graceful', 'graceful_loop', 'hard'];
                if (in_array($m[1], $valid, true)) {
                    $out['stopType'] = $m[1];
                }
                continue;
            }

            // fpp.type
            if (!$inStop && preg_match('/^type:\s*(\S+)/', $line, $m)) {
                $valid = ['playlist', 'sequence', 'command'];
                if (in_array($m[1], $valid, true)) {
                    $out['type'] = $m[1];
                }
                continue;
            }

            // repeat
            if (preg_match('/^repeat:\s*(\S+)/', $line, $m)) {
                $valid = ['none', 'immediate', '5', '10', '15', '20', '30', '60'];
                if (in_array($m[1], $valid, true)) {
                    $out['repeat'] = $m[1];
                }
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
