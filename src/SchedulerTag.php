<?php
/**
 * SchedulerTag
 * Because FPP 9.3 schedule.json entries have no "notes/description" fields,
 * we tag plugin-managed entries inside the target name field.
 *
 * Playlist value example stored in schedule.json:
 *   MyPlaylist|GCS:v1|uid=ABC123|occ=2025-12-16T08:00
 */
class SchedulerTag
{
    const PREFIX = '|GCS:v1|';

    public static function buildTaggedName($baseName, $uid, $occLocalIsoNoSeconds)
    {
        // Keep this delimiter stable and parseable.
        return $baseName . self::PREFIX . "uid={$uid}|occ={$occLocalIsoNoSeconds}";
    }

    public static function isTaggedPlaylist($playlist)
    {
        return is_string($playlist) && (strpos($playlist, self::PREFIX) !== false);
    }

    /**
     * @return array{base:string,uid:string,occ:string}|null
     */
    public static function parseFromPlaylist($playlist)
    {
        if (!is_string($playlist) || strpos($playlist, self::PREFIX) === false) {
            return null;
        }

        $parts = explode(self::PREFIX, $playlist, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $base = $parts[0];
        $meta = $parts[1];

        $uid = null;
        $occ = null;

        foreach (explode('|', $meta) as $token) {
            if (strpos($token, 'uid=') === 0) {
                $uid = substr($token, 4);
            } elseif (strpos($token, 'occ=') === 0) {
                $occ = substr($token, 4);
            }
        }

        if ($base !== '' && $uid && $occ) {
            return ['base' => $base, 'uid' => $uid, 'occ' => $occ];
        }

        return null;
    }

    /**
     * Strip tag from playlist name, returning the base name.
     */
    public static function stripTag($playlist)
    {
        $parsed = self::parseFromPlaylist($playlist);
        return $parsed ? $parsed['base'] : $playlist;
    }
}
