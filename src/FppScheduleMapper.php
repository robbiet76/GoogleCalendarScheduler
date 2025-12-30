<?php

/**
 * GcsFppScheduleMapper (LEGACY)
 *
 * ⚠️ LEGACY FILE — DO NOT EXTEND ⚠️
 *
 * Historical intent → scheduler mapping utility used in early phases
 * of GoogleCalendarScheduler development.
 *
 * STATUS:
 * - Retained for backward compatibility and forensic reference
 * - NOT the canonical mapping path as of Phase 17+
 *
 * REPLACED BY:
 * - SchedulerSync::intentToScheduleEntryStatic()
 *
 * WHY THIS STILL EXISTS:
 * - Earlier phases relied on weekday bitmasks + dayMask semantics
 * - Some transitional code paths may still reference this class
 * - Removal should happen only in a dedicated breaking-change phase
 *
 * IMPORTANT DIFFERENCES VS CURRENT MAPPER:
 * - Uses weekday bitmask logic (deprecated)
 * - Builds identity tag via playlist field (legacy behavior)
 * - Mixes mapping + identity concerns
 *
 * ⚠️ DO NOT MODIFY LOGIC
 * ⚠️ DO NOT ADD NEW CALLERS
 * ⚠️ DO NOT USE FOR NEW FEATURES
 */
class GcsFppScheduleMapper
{
    // Legacy FPP day enum values
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

    /**
     * Legacy mapping: range intent → FPP scheduler entry.
     *
     * @param array<string,mixed> $ri Range intent (legacy shape)
     * @return array<string,mixed>|null
     */
    public static function mapRangeIntentToSchedule(array $ri): ?array
    {
        $start = $ri['start'] ?? null;
        $end   = $ri['end'] ?? null;
        if (!($start instanceof DateTime) || !($end instanceof DateTime)) {
            return null;
        }

        $weekdayMask = intval($ri['weekdayMask'] ?? 0);
        $dayFields   = self::encodeDayFields($weekdayMask);

        // Legacy defaulting rules (pre-Phase 21)
        $repeat   = self::mapRepeat($ri['repeat'] ?? 'immediate');
        $stopType = self::mapStopType($ri['stopType'] ?? 'graceful');
        $tag      = self::buildTag($ri);

        $type = (string)($ri['type'] ?? '');

        // Base entry fields (legacy shape)
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

        // -----------------------------
        // Playlist
        // -----------------------------
        if ($type === 'playlist') {
            $entry['sequence'] = 0;
            $entry['playlist'] = (string)$ri['target'] . $tag;
            return $entry;
        }

        // -----------------------------
        // Sequence
        // -----------------------------
        if ($type === 'sequence') {
            // Legacy FPP behavior: sequence=1 but name stored in playlist field
            $entry['sequence'] = 1;
            $entry['playlist'] = (string)$ri['target'] . $tag;
            return $entry;
        }

        // -----------------------------
        // Command (edge-triggered)
        // -----------------------------
        if ($type === 'command') {
            $cmd = isset($ri['command']) ? trim((string)$ri['command']) : '';
            if ($cmd === '') {
                return null;
            }

            $args = (isset($ri['args']) && is_array($ri['args'])) ? $ri['args'] : [];

            // Edge semantics: start-only trigger
            $entry['endTime'] = $entry['startTime'];
            $entry['endTimeOffset'] = 0;

            // Legacy identity placement (playlist field ignored by FPP for commands)
            $entry['sequence'] = 0;
            $entry['playlist'] = $tag;

            $entry['command'] = $cmd;
            $entry['args'] = $args;
            $entry['multisyncCommand'] = !empty($ri['multisyncCommand']);

            return $entry;
        }

        return null;
    }

    /**
     * Legacy ownership detection (playlist field based).
     *
     * @deprecated Use GcsSchedulerIdentity::isGcsManaged()
     */
    public static function isPluginManaged(array $entry): bool
    {
        $p = (string)($entry['playlist'] ?? '');
        return (strpos($p, '|GCS:v1|') !== false);
    }

    /**
     * Legacy identity extractor.
     *
     * @deprecated Use GcsSchedulerIdentity::extractKey()
     */
    public static function pluginKey(array $entry): ?string
    {
        $p = (string)($entry['playlist'] ?? '');
        $pos = strpos($p, '|GCS:v1|');
        if ($pos === false) {
            return null;
        }
        return substr($p, $pos);
    }

    /* ------------------------------------------------------------
     * Legacy helpers
     * ------------------------------------------------------------ */

    private static function buildTag(array $ri): string
    {
        $uid   = (string)($ri['uid'] ?? '');
        $range = (string)($ri['startDate'] ?? '') . '..' . (string)($ri['endDate'] ?? '');
        $days  = GcsIntentConsolidator::weekdayMaskToShortDays(
            intval($ri['weekdayMask'] ?? 0)
        );

        return '|GCS:v1|uid=' . $uid . '|range=' . $range . '|days=' . $days;
    }

    /**
     * Legacy weekday mask → FPP enum encoding.
     */
    private static function encodeDayFields(int $weekdayMask): array
    {
        $weekdayMask = $weekdayMask & 127;

        $weekdaysMask = (
            GcsIntentConsolidator::WD_MON |
            GcsIntentConsolidator::WD_TUE |
            GcsIntentConsolidator::WD_WED |
            GcsIntentConsolidator::WD_THU |
            GcsIntentConsolidator::WD_FRI
        );

        $weekendsMask = (
            GcsIntentConsolidator::WD_SUN |
            GcsIntentConsolidator::WD_SAT
        );

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

    /**
     * Legacy stopType mapping.
     */
    private static function mapStopType(string $stopType): int
    {
        return match (strtolower(trim($stopType))) {
            'hard'           => 1,
            'graceful_loop'  => 2,
            default          => 0,
        };
    }

    /**
     * Legacy repeat mapping.
     *
     * Default behavior: Immediate (match historical FPP UI behavior).
     */
    private static function mapRepeat($repeat): int
    {
        if (is_int($repeat)) {
            return $repeat;
        }

        if (is_string($repeat)) {
            $r = strtolower(trim($repeat));
            if ($r === 'none') return 0;
            if ($r === 'immediate' || $r === '') return 1;
            if (ctype_digit($r)) return (int)$r;
        }

        return 1;
    }
}
