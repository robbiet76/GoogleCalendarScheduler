<?php

final class SchedulerDiff
{
    /**
     * Compute diff between desired and existing schedules.
     *
     * @param array<int,array<string,mixed>> $desired
     * @param array<int,array<string,mixed>> $existing
     * @return array{adds:array,updates:array,deletes:array}
     */
    public static function diff(array $desired, array $existing): array
    {
        $adds = [];
        $updates = [];
        $deletes = [];

        // Index existing by stable comparison key
        $existingByKey = [];
        foreach ($existing as $e) {
            $key = self::keyFor($e);
            if ($key !== null) {
                $existingByKey[$key] = $e;
            }
        }

        // Track which existing entries are matched
        $matchedExistingKeys = [];

        // Walk desired entries
        foreach ($desired as $d) {
            $key = self::keyFor($d);
            if ($key === null) {
                continue;
            }

            if (!isset($existingByKey[$key])) {
                // Not present → add
                $adds[] = $d;
                continue;
            }

            $existingEntry = $existingByKey[$key];
            $matchedExistingKeys[$key] = true;

            if (!self::entriesEqual($d, $existingEntry)) {
                $updates[] = [
                    'from' => $existingEntry,
                    'to'   => $d,
                ];
            }
        }

        // Any existing entries not matched are deletes
        foreach ($existingByKey as $key => $e) {
            if (!isset($matchedExistingKeys[$key])) {
                $deletes[] = $e;
            }
        }

        GcsLog::info('SchedulerDiff summary (dry-run)', [
            'adds'    => count($adds),
            'updates' => count($updates),
            'deletes' => count($deletes),
        ]);

        return [
            'adds'    => $adds,
            'updates' => $updates,
            'deletes' => $deletes,
        ];
    }

    /**
     * Build a stable comparison key.
     * This intentionally ignores sequence numbers and raw indexes.
     */
    private static function keyFor(array $e): ?string
    {
        if (
            empty($e['playlist']) ||
            empty($e['startTime']) ||
            empty($e['endTime']) ||
            !isset($e['dayMask'])
        ) {
            return null;
        }

        return implode('|', [
            (string)$e['playlist'],
            (string)$e['startTime'],
            (string)$e['endTime'],
            (string)($e['startDate'] ?? ''),
            (string)($e['endDate'] ?? ''),
            (string)$e['dayMask'],
        ]);
    }

    /**
     * Deep comparison of two normalized scheduler entries.
     */
    private static function entriesEqual(array $a, array $b): bool
    {
        $fields = [
            'enabled',
            'playlist',
            'dayMask',
            'startTime',
            'endTime',
            'startDate',
            'endDate',
            'repeat',
            'stopType',
        ];

        foreach ($fields as $f) {
            if ((string)($a[$f] ?? '') !== (string)($b[$f] ?? '')) {
                return false;
            }
        }

        return true;
    }
}
