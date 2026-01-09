<?php
declare(strict_types=1);

/**
 * TargetResolver
 *
 * Resolves a Google Calendar event title into a concrete FPP
 * scheduler target.
 *
 * Responsibilities:
 * - Interpret event summary text
 * - Determine whether it refers to a valid FPP playlist or sequence
 * - Determine whether it refers to a valid FPP command
 *
 * Resolution Order:
 * 0. Explicit command reference
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
final class TargetResolver
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
         * 0. Explicit command resolution
         * ---------------------------------------------------------- */
        if (str_starts_with($base, 'cmd:') || str_starts_with($base, 'command:')) {
            $cmd = trim(substr($base, strpos($base, ':') + 1));
            if ($cmd !== '') {
                return [
                    'type'   => 'command',
                    'target' => $cmd,
                ];
            }
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
        $seqFile = (str_ends_with($base, '.fseq')) ? $base : $base . '.fseq';
        if (self::sequenceExists($seqFile)) {
            return [
                'type'   => 'sequence',
                'target' => pathinfo($seqFile, PATHINFO_FILENAME),
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
