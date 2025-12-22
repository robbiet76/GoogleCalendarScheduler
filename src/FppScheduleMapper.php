<?php

class GcsFppScheduleMapper
{
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
        $start = $ri['start'] ?? null;
        $end   = $ri['end'] ?? null;
        if (!($start instanceof DateTime) || !($end instanceof DateTime)) {
            return null;
        }

        $weekdayMask = intval($ri['weekdayMask'] ?? 0);
        $dayFields   = self::encodeDayFields($weekdayMask);

        $repeat   = self::mapRepeat($ri['repeat'] ?? 'none');
        $stopType = self::mapStopType($ri['stopType'] ?? 'graceful');
        $tag      = self::buildTag($ri);

        $type = (string)($ri['type'] ?? '');

        // Base entry common fields
        $entry = [
            'enabled'         => !empty($ri['enabled']) ? 1 : 0,
            'sequence'        => 0,
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

        // ------------------------------------------------------------
        // Playlist
        // ------------------------------------------------------------
        if ($type === 'playlist') {
            $entry['sequence'] = 0;
            $entry['playlist'] = (string)$ri['target'] . $tag;
            return $entry;
        }

        // ------------------------------------------------------------
        // Sequence
        // ------------------------------------------------------------
        if ($type === 'sequence') {
            // FPP uses sequence=1 but still stores the sequence name in "playlist"
            $entry['sequence'] = 1;
            $entry['playlist'] = (string)$ri['target'] . $tag;
            return $entry;
        }

        // ------------------------------------------------------------
        // Command (Phase 10/12) — EDGE-TRIGGERED
        // ------------------------------------------------------------
        if ($type === 'command') {
            $cmd = isset($ri['command']) ? trim((string)$ri['command']) : '';
            if ($cmd === '') {
                return null;
            }

            $args = (isset($ri['args']) && is_array($ri['args'])) ? $ri['args'] : [];

            // EDGE semantics: command triggers at start only (zero-length window)
            $entry['endTime'] = $entry['startTime'];
            $entry['endTimeOffset'] = 0;

            // Keep stable identity tag in playlist field (ignored by FPP when command is set)
            $entry['sequence'] = 0;
            $entry['playlist'] = $tag;

            $entry['command'] = $cmd;
            $entry['args'] = $args;
            $entry['multisyncCommand'] = !empty($ri['multisyncCommand']);

            return $entry;
        }

        // Unknown type
        return null;
    }

    public static function isPluginManaged(array $entry): bool
    {
        $p = (string)($entry['playlist'] ?? '');
        return (strpos($p, '|GCS:v1|') !== false);
    }

    public static function pluginKey(array $entry): ?string
    {
        $p = (string)($entry['playlist'] ?? '');
        $pos = strpos($p, '|GCS:v1|');
        if ($pos === false) {
            return null;
        }
        return substr($p, $pos);
    }

    private static function buildTag(array $ri): string
    {
        $uid = (string)($ri['uid'] ?? '');
        $range = (string)($ri['startDate'] ?? '') . '..' . (string)($ri['endDate'] ?? '');
        $days = GcsIntentConsolidator::weekdayMaskToShortDays(intval($ri['weekdayMask'] ?? 0));

        return '|GCS:v1|uid=' . $uid . '|range=' . $range . '|days=' . $days;
    }

    private static function encodeDayFields(int $weekdayMask): array
    {
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

        return ['day' => self::DAY_MASK, 'dayMask' => $weekdayMask];
    }

    private static function mapStopType(string $stopType): int
    {
        $s = strtolower(trim($stopType));

        // 0 = graceful
        // 1 = hard
        // 2 = graceful_loop
        switch ($s) {
            case 'hard':
                return 1;
            case 'graceful_loop':
                return 2;
            case 'graceful':
            default:
                return 0;
        }
    }

    private static function mapRepeat($repeat): int
    {
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
