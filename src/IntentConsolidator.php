<?php
declare(strict_types=1);

/**
 * GcsIntentConsolidator
 *
 * Losslessly consolidates per-occurrence scheduling intents into
 * contiguous date ranges suitable for scheduler entry creation.
 *
 * Responsibilities:
 * - Group compatible occurrences by immutable intent characteristics
 * - Merge consecutive calendar dates into a single range
 * - Preserve weekday coverage exactly
 *
 * HARD GUARANTEES:
 * - Occurrences with differing start/end TIMES are NEVER merged
 * - Overrides are NEVER merged with non-overrides
 * - Consolidation is strictly lossless
 *
 * This class does NOT:
 * - Infer scheduling policy
 * - Modify intent semantics
 * - Perform scheduler I/O
 */
final class GcsIntentConsolidator
{
    /* ---------------------------------------------------------------------
     * Weekday bitmask constants (Sunday = 0, matches DateTime::format('w'))
     * ------------------------------------------------------------------ */

    public const WD_SUN = 1 << 0; // 1
    public const WD_MON = 1 << 1; // 2
    public const WD_TUE = 1 << 2; // 4
    public const WD_WED = 1 << 3; // 8
    public const WD_THU = 1 << 4; // 16
    public const WD_FRI = 1 << 5; // 32
    public const WD_SAT = 1 << 6; // 64

    public const WD_ALL =
        self::WD_SUN |
        self::WD_MON |
        self::WD_TUE |
        self::WD_WED |
        self::WD_THU |
        self::WD_FRI |
        self::WD_SAT;

    /* ---------------------------------------------------------------------
     * Internal metrics (diagnostic only)
     * ------------------------------------------------------------------ */

    private int $skipped = 0;
    private int $rangeCount = 0;

    /**
     * Consolidate a set of per-occurrence intents into ranged intents.
     *
     * @param array<int,array<string,mixed>> $intents
     * @return array<int,array<string,mixed>>
     */
    public function consolidate(array $intents): array
    {
        if (empty($intents)) {
            return [];
        }

        /* -------------------------------------------------------------
         * 1. Group intents by immutable identity
         * ---------------------------------------------------------- */
        $groups = [];

        foreach ($intents as $intent) {
            if (!isset($intent['target'], $intent['start'], $intent['end'])) {
                $this->skipped++;
                continue;
            }

            $start = new DateTime((string)$intent['start']);
            $end   = new DateTime((string)$intent['end']);

            $startTime = $start->format('H:i:s');
            $endTime   = $end->format('H:i:s');

            // Stable identity MUST include time and override flag
            $key = implode('|', [
                (string)($intent['type'] ?? ''),
                (string)$intent['target'],
                (string)($intent['stopType'] ?? ''),
                (string)($intent['repeat'] ?? ''),
                (!empty($intent['isAllDay']) ? '1' : '0'),
                $startTime,
                $endTime,
                (!empty($intent['isOverride']) ? '1' : '0'),
            ]);

            $groups[$key][] = $intent;
        }

        /* -------------------------------------------------------------
         * 2. Merge consecutive dates within each group
         * ---------------------------------------------------------- */
        $result = [];

        foreach ($groups as $items) {
            usort(
                $items,
                fn($a, $b) => strcmp((string)$a['start'], (string)$b['start'])
            );

            $range = null;

            foreach ($items as $intent) {
                $start = new DateTime((string)$intent['start']);
                $dow   = (int)$start->format('w'); // 0=Sun..6=Sat

                if ($range === null) {
                    $range = [
                        'template'  => $intent,
                        'startDate' => $start,
                        'endDate'   => $start,
                        'days'      => [$dow => true],
                    ];
                    continue;
                }

                $expected = (clone $range['endDate'])->modify('+1 day');

                if ($start->format('Y-m-d') === $expected->format('Y-m-d')) {
                    $range['endDate'] = $start;
                    $range['days'][$dow] = true;
                } else {
                    $result[] = $this->finalizeRange($range);
                    $this->rangeCount++;

                    $range = [
                        'template'  => $intent,
                        'startDate' => $start,
                        'endDate'   => $start,
                        'days'      => [$dow => true],
                    ];
                }
            }

            if ($range !== null) {
                $result[] = $this->finalizeRange($range);
                $this->rangeCount++;
            }
        }

        return $result;
    }

    /**
     * Finalize a range structure into a consolidated intent.
     */
    private function finalizeRange(array $range): array
    {
        return [
            'template' => $range['template'],
            'range' => [
                'start' => $range['startDate']->format('Y-m-d'),
                'end'   => $range['endDate']->format('Y-m-d'),
                'days'  => self::weekdayMaskToShortDays(
                    self::daysArrayToMask($range['days'])
                ),
            ],
        ];
    }

    /**
     * Convert an array of weekdays into a bitmask.
     */
    private static function daysArrayToMask(array $days): int
    {
        $mask = 0;
        foreach ($days as $dow => $_) {
            $mask |= (1 << (int)$dow);
        }
        return $mask;
    }

    /* ---------------------------------------------------------------------
     * Shared helpers (used by FppScheduleMapper and others)
     * ------------------------------------------------------------------ */

    /**
     * Convert short-day string (e.g. "SuMoTu") to weekday bitmask.
     */
    public static function shortDaysToWeekdayMask(string $days): int
    {
        $map = [
            'Su' => self::WD_SUN,
            'Mo' => self::WD_MON,
            'Tu' => self::WD_TUE,
            'We' => self::WD_WED,
            'Th' => self::WD_THU,
            'Fr' => self::WD_FRI,
            'Sa' => self::WD_SAT,
        ];

        $mask = 0;
        foreach ($map as $abbr => $bit) {
            if (strpos($days, $abbr) !== false) {
                $mask |= $bit;
            }
        }

        return $mask;
    }

    /**
     * Convert weekday bitmask to short-day string (e.g. "SuMoTu").
     */
    public static function weekdayMaskToShortDays(int $mask): string
    {
        $map = [
            self::WD_SUN => 'Su',
            self::WD_MON => 'Mo',
            self::WD_TUE => 'Tu',
            self::WD_WED => 'We',
            self::WD_THU => 'Th',
            self::WD_FRI => 'Fr',
            self::WD_SAT => 'Sa',
        ];

        $out = '';
        foreach ($map as $bit => $abbr) {
            if ($mask & $bit) {
                $out .= $abbr;
            }
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     * Diagnostics
     * ------------------------------------------------------------------ */

    public function getSkippedCount(): int
    {
        return $this->skipped;
    }

    public function getRangeCount(): int
    {
        return $this->rangeCount;
    }
}
