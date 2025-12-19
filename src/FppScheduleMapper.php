<?php

/**
 * Map a consolidated "range intent" into an FPP schedule.json entry.
 *
 * Supported types (Phase 9.2):
 *  - playlist
 *  - sequence
 *
 * FPP fields observed/used:
 *  - enabled (1/0)
 *  - sequence (0 for playlist, 1 for sequence)
 *  - playlist (string)  (NOTE: used as "Playlist/Command Args" by FPP UI/logs)
 *  - day (int) = common presets + day-mask mode
 *  - dayMask (int) when in day-mask mode
 *  - startTime, endTime
 *  - startTimeOffset, endTimeOffset
 *  - repeat (int)
 *  - startDate, endDate
 *  - stopType (int)
 */
class GcsFppScheduleMapper
{
    // These are conventional based on observed schedule.json "Everyday" => 7 and FPP UI.
    // If your system differs, adjust constants here.
    const DAY_SUN      = 0;
    const DAY_MON      = 1;
    const DAY_TUE      = 2;
    const DAY_WED      = 3;
    const DAY_THU      = 4;
    const DAY_FRI      = 5;
    const DAY_SAT      = 6;
    const DAY_EVERYDAY = 7;
    const DAY_WEEKDAYS = 8;
    const DAY_WEEKENDS = 9;
    const DAY_MASK     = 10;

    public static function mapRangeIntentToSchedule(array $ri): ?array
    {
        $type = (string)($ri['type'] ?? '');

        // Phase 9.2 supports playlist + sequence
        if ($type !== 'playlist' && $type !== 'sequence') {
            return null;
        }

        $start = $ri['start'] ?? null;
        $end   = $ri['end'] ?? null;
        if (!($start instanceof DateTime) || !($end instanceof DateTime)) {
            return null;
        }

        $weekdayMask = intval($ri['weekdayMask'] ?? 0);
        $dayFields = self::encodeDayFields($weekdayMask);

        $repeat   = self::mapRepeat($ri['repeat'] ?? 'none');
        $stopType = self::mapStopType($ri['stopType'] ?? 'graceful');

        $tag = self::buildTag($ri);

        // enabled: default true
        $enabledBool = $ri['enabled'] ?? true;
        $enabled = ($enabledBool === false) ? 0 : 1;

        $entry = [
            'enabled'         => $enabled,
            'sequence'        => ($type === 'sequence') ? 1 : 0,
            // NOTE: we keep using "playlist" as the payload field because that is what
            // your current Phase 8/9 pipeline keys off of for identity tagging and diffing.
            'playlist'        => (string)$ri['target'] . $tag,
            'day'             => $dayFields['day'],
            'startTime'       => $start->format('H:i:s'),
            'startTimeOffset' => 0,
            'endTime'         => $end->format('H:i:s'),
            'endTimeOffset'   => 0,
            'repeat'          => $repeat,
            'startDate'       => (string)$ri['startDate'],
            'endDate'         => (string)$ri['endDate'],
            'stopType'        => $stopType,
        ];

        if (isset($dayFields['dayMask'])) {
            $entry['dayMask'] = $dayFields['dayMask'];
        }

        return $entry;
    }

    public static function isPluginManaged(array $entry): bool
    {
        // We embed a stable tag into the playlist field
        $p = (string)($entry['playlist'] ?? '');
        return (strpos($p, '|GCS:v1|') !== false);
    }

    public static function pluginKey(array $entry): ?string
    {
        // Extract everything from |GCS:v1| onwards; that's our stable identity
        $p = (string)($entry['playlist'] ?? '');
        $pos = strpos($p, '|GCS:v1|');
        if ($pos === false) {
            return null;
        }
        return substr($p, $pos);
    }

    private static function buildTag(array $ri): string
    {
        $uid   = (string)($ri['uid'] ?? '');
        $range = (string)($ri['startDate'] ?? '') . '..' . (string)($ri['endDate'] ?? '');
        $days  = GcsIntentConsolidator::weekdayMaskToShortDays(intval($ri['weekdayMask'] ?? 0));

        // Keep tags short and deterministic; avoid characters that confuse FPP UI
        return '|GCS:v1|uid=' . $uid . '|range=' . $range . '|days=' . $days;
    }

    private static function encodeDayFields(int $weekdayMask): array
    {
        // Normalize to 7-bit
        $weekdayMask = $weekdayMask & 127;

        $weekdaysMask = (GcsIntentConsolidator::WD_MON
                       | GcsIntentConsolidator::WD_TUE
                       | GcsIntentConsolidator::WD_WED
                       | GcsIntentConsolidator::WD_THU
                       | GcsIntentConsolidator::WD_FRI);

        $weekendsMask = (GcsIntentConsolidator::WD_SUN | GcsIntentConsolidator::WD_SAT);

        if ($weekdayMask === GcsIntentConsolidator::WD_ALL) {
            return ['day' => self::DAY_EVERYDAY];
        }

        if ($weekdayMask === $weekdaysMask) {
            return ['day' => self::DAY_WEEKDAYS];
        }

        if ($weekdayMask === $weekendsMask) {
            return ['day' => self::DAY_WEEKENDS];
        }

        // Arbitrary combinations: use Day Mask mode
        return ['day' => self::DAY_MASK, 'dayMask' => $weekdayMask];
    }

    private static function mapStopType(string $stopType): int
    {
        // FPP stopType observed: 0 (graceful), 1 (hard) in your schedule.json
        $s = strtolower(trim($stopType));
        if ($s === 'hard') {
            return 1;
        }
        return 0;
    }

    private static function mapRepeat($repeat): int
    {
        // Backwards compatible behavior:
        // - 'none' => 0
        // - integer N => N
        // - 'immediate' => 1 (common FPP internal)
        if (is_int($repeat)) {
            return $repeat;
        }
        if (is_string($repeat)) {
            $r = strtolower(trim($repeat));
            if ($r === 'none' || $r === '') {
                return 0;
            }
            if ($r === 'immediate') {
                return 1;
            }
            if (ctype_digit($r)) {
                return intval($r);
            }
        }
        return 0;
    }
}
