<?php

final class SchedulerDiff
{
    public function diff(array $existing, array $desired): array
    {
        $adds = [];
        $updates = [];
        $deletes = [];

        $existingByKey = [];

        foreach ($existing as $idx => $e) {
            if (!empty($e['playlist']) && strpos($e['playlist'], '|uid=') !== false) {
                $existingByKey[$this->identityKey($e)] = [
                    'index' => $idx,
                    'entry' => $e,
                ];
            }
        }

        foreach ($desired as $d) {
            $key = $this->identityKey($d);

            if (!isset($existingByKey[$key])) {
                $adds[] = $d;
                continue;
            }

            $cur = $existingByKey[$key]['entry'];

            if ($this->isDifferent($cur, $d)) {
                $updates[] = [
                    'index' => $existingByKey[$key]['index'],
                    'before' => $cur,
                    'after'  => $d,
                ];
            }
        }

        return [
            'adds'    => $adds,
            'updates' => $updates,
            'deletes' => $deletes, // Phase 8.6
        ];
    }

    private function identityKey(array $e): string
    {
        return (string)($e['playlist'] ?? '');
    }

    private function isDifferent(array $a, array $b): bool
    {
        $fields = [
            'dayMask',
            'startTime',
            'endTime',
            'startDate',
            'endDate',
            'repeat',
            'stopType',
            'enabled',
            'sequence',
        ];

        foreach ($fields as $f) {
            if (($a[$f] ?? null) !== ($b[$f] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
