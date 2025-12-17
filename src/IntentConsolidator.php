<?php

/**
 * IntentConsolidator
 *
 * Groups per-occurrence scheduler intents into:
 * - Date ranges
 * - Weekday masks
 *
 * This dramatically reduces scheduler entry count.
 */
class IntentConsolidator
{
    private int $skipped = 0;
    private int $rangeCount = 0;

    /**
     * @param array<int,array<string,mixed>> $intents
     * @return array<int,array<string,mixed>>
     */
    public function consolidate(array $intents): array
    {
        if (empty($intents)) {
            return [];
        }

        // Group by stable identity
        $groups = [];

        foreach ($intents as $intent) {
            if (!isset($intent['target'], $intent['start'], $intent['end'])) {
                $this->skipped++;
                continue;
            }

            $key = implode('|', [
                $intent['type'] ?? '',
                $intent['target'],
                $intent['stopType'] ?? '',
                $intent['repeat'] ?? '',
            ]);

            $groups[$key][] = $intent;
        }

        $result = [];

        foreach ($groups as $items) {
            usort($items, fn($a, $b) =>
                strcmp($a['start'], $b['start'])
            );

            $range = null;

            foreach ($items as $intent) {
                $start = new DateTime($intent['start']);
                $end   = new DateTime($intent['end']);
                $dow   = (int)$start->format('w');

                if ($range === null) {
                    $range = [
                        'template' => $intent,
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
                        'template' => $intent,
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

    private function finalizeRange(array $range): array
    {
        $daysMap = ['Su','Mo','Tu','We','Th','Fr','Sa'];
        $days = '';

        foreach ($daysMap as $i => $label) {
            if (!empty($range['days'][$i])) {
                $days .= $label;
            }
        }

        return [
            'template' => $range['template'],
            'range' => [
                'start' => $range['startDate']->format('Y-m-d'),
                'end'   => $range['endDate']->format('Y-m-d'),
                'days'  => $days,
            ]
        ];
    }

    public function getSkippedCount(): int
    {
        return $this->skipped;
    }

    public function getRangeCount(): int
    {
        return $this->rangeCount;
    }
}

/**
 * Compatibility alias expected by api_main.php
 */
class GcsIntentConsolidator extends IntentConsolidator
{
}
