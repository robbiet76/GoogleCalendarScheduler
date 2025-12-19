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
        if (($ri['type'] ?? '') !== 'playlist') {
            return null;
        }

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

        $entry = [
            'enabled'         => !empty($ri['enabled']) ? 1 : 0,
            'sequence'        => 0,
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

    private static function mapStopType(string $stopType): int
    {
        $s = strtolower(trim($stopType));

        // FPP stopType mapping:
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

    private static function buildTag(array $ri): string
    {
        $uid   = (string)($ri['uid'] ?? '');
        $range = (string)$ri['startDate'] . '..' . (string)$ri['endDate'];
        $days  = GcsIntentConsolidator::weekdayMaskToShortDays(
            intval($ri['weekdayMask'] ?? 0)
        );

        return '|GCS:v1|uid=' . $uid . '|range=' . $range . '|days=' . $days;
    }

    private static function encodeDayFields(int $weekdayMask): array
    {
        $weekdayMask &= 127;

        $weekdaysMask =
            GcsIntentConsolidator::WD_MON |
            GcsIntentConsolidator::WD_TUE |
            GcsIntentConsolidator::WD_WED |
            GcsIntentConsolidator::WD_THU |
            GcsIntentConsolidator::WD_FRI;

        $weekendsMask =
            GcsIntentConsolidator::WD_SUN |
            GcsIntentConsolidator::WD_SAT;

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
}
