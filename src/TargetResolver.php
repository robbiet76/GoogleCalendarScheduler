<?php

/**
 * Resolve Google Calendar event titles into FPP scheduler targets.
 *
 * Resolution order (per v1.5):
 *   1. Playlist
 *   2. Sequence
 *
 * If no valid target exists, return null.
 */
final class GcsTargetResolver {

    /**
     * Attempt to resolve a target from the event summary.
     *
     * @param string $summary Calendar event title
     * @return array|null ['type' => ..., 'target' => ...] or null if unresolved
     */
    public static function resolve(string $summary): ?array {
        $base = trim($summary);

        if ($base === '') {
            return null;
        }

        // 1. Playlist (directory-based OR file-based)
        if (self::playlistExists($base)) {
            return [
                'type'   => 'playlist',
                'target' => $base
            ];
        }

        // 2. Sequence (.fseq file)
        $seq = (substr($base, -5) === '.fseq') ? $base : $base . '.fseq';
        if (self::sequenceExists($seq)) {
            return [
                'type'   => 'sequence',
                'target' => $seq
            ];
        }

        // Unresolved
        return null;
    }

    /**
     * Check for an FPP playlist.
     *
     * Supported formats:
     *   - /home/fpp/media/playlists/<name>/playlist.json
     *   - /home/fpp/media/playlists/<name>.json
     */
    private static function playlistExists(string $name): bool {
        $dirBased  = "/home/fpp/media/playlists/$name/playlist.json";
        $fileBased = "/home/fpp/media/playlists/$name.json";

        return is_file($dirBased) || is_file($fileBased);
    }

    /**
     * Check for an FPP sequence file.
     *
     *   /home/fpp/media/sequences/<name>.fseq
     */
    private static function sequenceExists(string $name): bool {
        return is_file("/home/fpp/media/sequences/$name");
    }
}
