<?php
declare(strict_types=1);

/**
 * GcsTargetResolver
 *
 * Resolves a Google Calendar event title into a concrete FPP
 * scheduler target.
 *
 * Responsibilities:
 * - Interpret event summary text
 * - Determine whether it refers to a valid FPP playlist or sequence
 *
 * Resolution Order:
 * 1. Playlist
 * 2. Sequence
 *
 * Guarantees:
 * - Read-only filesystem checks only
 * - No scheduler mutation
 * - No inference beyond explicit file existence
 *
 * If no valid target is found, resolution fails cleanly.
 */
final class GcsTargetResolver
{
    /**
     * Attempt to resolve an FPP scheduler target from a calendar summary.
     *
     * @param string $summary Calendar event title
     * @return array{type:string,target:string}|null
     */
    public static function resolve(string $summary): ?array
    {
        $base = trim($summary);
        if ($base === '') {
            return null;
        }

        /* -------------------------------------------------------------
         * 1. Playlist resolution
         * ---------------------------------------------------------- */
        if (self::playlistExists($base)) {
            return [
                'type'   => 'playlist',
                'target' => $base,
            ];
        }

        /* -------------------------------------------------------------
         * 2. Sequence resolution (.fseq)
         * ---------------------------------------------------------- */
        $seq = (str_ends_with($base, '.fseq')) ? $base : $base . '.fseq';
        if (self::sequenceExists($seq)) {
            return [
                'type'   => 'sequence',
                'target' => $seq,
            ];
        }

        return null;
    }

    /**
     * Check whether a named FPP playlist exists.
     *
     * Supported layouts:
     * - /home/fpp/media/playlists/<name>/playlist.json
     * - /home/fpp/media/playlists/<name>.json
     */
    private static function playlistExists(string $name): bool
    {
        $dirBased  = "/home/fpp/media/playlists/{$name}/playlist.json";
        $fileBased = "/home/fpp/media/playlists/{$name}.json";

        return is_file($dirBased) || is_file($fileBased);
    }

    /**
     * Check whether a named FPP sequence exists.
     *
     * Expected layout:
     * - /home/fpp/media/sequences/<name>.fseq
     */
    private static function sequenceExists(string $name): bool
    {
        return is_file("/home/fpp/media/sequences/{$name}");
    }
}
