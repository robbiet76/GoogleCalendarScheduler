<?php

final class SchedulerDiff
{
    /**
     * @param ComparableScheduleEntry[] $desired
     * @param ExistingScheduleEntry[]   $existing
     */
    public static function compute(array $desired, array $existing): SchedulerDiffResult
    {
        $result = new SchedulerDiffResult();

        $existingByUid = [];
        foreach ($existing as $e) {
            $existingByUid[$e->uid] = $e;
        }

        foreach ($desired as $d) {
            if (!isset($existingByUid[$d->uid])) {
                $result->create[] = $d;
                continue;
            }

            $ex = $existingByUid[$d->uid];
            if ($ex->toComparable()->equals($d)) {
                $result->noop[] = $d;
            } else {
                $result->update[$d->uid] = [
                    'existing' => $ex,
                    'desired' => $d,
                ];
            }

            unset($existingByUid[$d->uid]);
        }

        foreach ($existingByUid as $leftover) {
            $result->delete[] = $leftover;
        }

        GcsLog::info('Scheduler diff computed', [
            'create' => count($result->create),
            'update' => count($result->update),
            'delete' => count($result->delete),
            'noop'   => count($result->noop),
        ]);

        return $result;
    }
}
