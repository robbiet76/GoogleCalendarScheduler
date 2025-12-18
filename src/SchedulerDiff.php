<?php

class SchedulerDiff
{
    /**
     * @param ComparableScheduleEntry[] $desired
     * @param ExistingScheduleEntry[]   $existing
     */
    public static function compute(array $desired, array $existing): SchedulerDiffResult
    {
        $result = new SchedulerDiffResult();

        $existingByUid = [];
        foreach ($existing as $entry) {
            $existingByUid[$entry->uid] = $entry;
        }

        foreach ($desired as $desiredEntry) {
            if (!isset($existingByUid[$desiredEntry->uid])) {
                $result->create[] = $desiredEntry;
                continue;
            }

            $existingEntry = $existingByUid[$desiredEntry->uid];
            $existingComparable = $existingEntry->toComparable();

            if ($existingComparable->equals($desiredEntry)) {
                $result->noop[] = $desiredEntry;
            } else {
                $result->update[$desiredEntry->uid] = [
                    'existing' => $existingEntry,
                    'desired'  => $desiredEntry
                ];
            }

            unset($existingByUid[$desiredEntry->uid]);
        }

        // Remaining existing entries are deletes
        foreach ($existingByUid as $entry) {
            $result->delete[] = $entry;
        }

        Logger::info('Scheduler diff computed', [
            'create' => count($result->create),
            'update' => count($result->update),
            'delete' => count($result->delete),
            'noop'   => count($result->noop),
        ]);

        return $result;
    }
}
