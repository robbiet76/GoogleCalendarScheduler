<?php

final class SchedulerState
{
    /**
     * Load existing FPP scheduler entries owned by GCS.
     *
     * @return ExistingScheduleEntry[]
     */
    public static function loadExisting(): array
    {
        // Phase 8.1 will implement this against FPP scheduler JSON.
        // For now, return empty so diff produces CREATE only.
        GcsLog::info('SchedulerState loaded (stub)', [
            'count' => 0,
        ]);

        return [];
    }
}
