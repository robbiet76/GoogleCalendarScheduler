<?php

/**
 * SchedulerDiff
 *
 * Computes add / update / delete sets between
 * existing and desired scheduler entries.
 */
class SchedulerDiff
{
    /**
     * @param array<int,array<string,mixed>> $existing
     * @param array<int,array<string,mixed>> $desired
     * @return array<string,array>
     */
    public static function diff(array $existing, array $desired): array
    {
        $adds = [];
        $updates = [];
        $deletes = [];

        $existingByKey = [];
        foreach ($existing as $item) {
            if (isset($item['playlist'])) {
                $existingByKey[$item['playlist']] = $item;
            }
        }

        $desiredByKey = [];
        foreach ($desired as $item) {
            if (isset($item['playlist'])) {
                $desiredByKey[$item['playlist']] = $item;
            }
        }

        // Adds & updates
        foreach ($desiredByKey as $key => $item) {
            if (!isset($existingByKey[$key])) {
                $adds[] = $item;
            } elseif ($existingByKey[$key] != $item) {
                $updates[] = $item;
            }
        }

        // Deletes
        foreach ($existingByKey as $key => $item) {
            if (!isset($desiredByKey[$key])) {
                $deletes[] = $item;
            }
        }

        return [
            'add'    => $adds,
            'update' => $updates,
            'delete' => $deletes,
        ];
    }
}
