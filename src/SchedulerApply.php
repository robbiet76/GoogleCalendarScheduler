<?php

/**
 * Applies scheduler diffs to FPP scheduler.json
 */
final class SchedulerApply
{
    private bool $dryRun;

    private const SCHEDULE_PATH = '/home/fpp/media/config/schedule.json';

    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * Apply a scheduler diff result.
     */
    public function apply(SchedulerDiffResult $diff): void
    {
        $schedule = $this->loadSchedule();

        // ------------------------------------------------------------
        // ADDs
        // ------------------------------------------------------------
        foreach ($diff->adds as $entry) {
            if ($this->dryRun) {
                GcsLog::info('[DRY-RUN] ADD', $this->logShape($entry));
                continue;
            }

            $schedule[] = $entry;
        }

        // ------------------------------------------------------------
        // UPDATEs
        // ------------------------------------------------------------
        foreach ($diff->updates as $update) {
            if ($this->dryRun) {
                GcsLog::info('[DRY-RUN] UPDATE', $update);
                continue;
            }

            foreach ($schedule as $idx => $existing) {
                if (($existing['playlist'] ?? null) === ($update['from']['playlist'] ?? null)) {
                    $schedule[$idx] = $update['to'];
                    break;
                }
            }
        }

        // ------------------------------------------------------------
        // DELETEs  (Phase 8.6)
        // ------------------------------------------------------------
        foreach ($diff->deletes as $entry) {
            if ($this->dryRun) {
                GcsLog::info('[DRY-RUN] DELETE', [
                    'playlist' => $entry['playlist'] ?? '',
                ]);
                continue;
            }

            foreach ($schedule as $idx => $existing) {
                if (($existing['playlist'] ?? null) === ($entry['playlist'] ?? null)) {
                    unset($schedule[$idx]);
                    break;
                }
            }
        }

        // ------------------------------------------------------------
        // Persist
        // ------------------------------------------------------------
        if (!$this->dryRun) {
            // Re-index array to keep JSON clean
            $schedule = array_values($schedule);
            $this->saveSchedule($schedule);

            GcsLog::info('SchedulerApply live complete', [
                'added'   => count($diff->adds),
                'updated' => count($diff->updates),
                'deleted' => count($diff->deletes),
                'final'   => count($schedule),
            ]);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadSchedule(): array
    {
        if (!file_exists(self::SCHEDULE_PATH)) {
            return [];
        }

        $raw = file_get_contents(self::SCHEDULE_PATH);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,array<string,mixed>> $schedule
     */
    private function saveSchedule(array $schedule): void
    {
        file_put_contents(
            self::SCHEDULE_PATH,
            json_encode($schedule, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Normalize log output for schedule entries.
     */
    private function logShape(array $entry): array
    {
        return [
            'playlist' => $entry['playlist'] ?? '',
            'days'     => $entry['dayMask'] ?? 0,
            'start'    => $entry['startTime'] ?? '',
            'end'      => $entry['endTime'] ?? '',
            'from'     => $entry['startDate'] ?? '',
            'to'       => $entry['endDate'] ?? '',
        ];
    }
}
