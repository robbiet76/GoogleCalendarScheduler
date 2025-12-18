<?php

final class SchedulerSync
{
    private bool $dryRun;

    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }

    public function sync(array $desired): array
    {
        // Load existing scheduler state
        $state = SchedulerState::load();
        GcsLog::info('SchedulerState loaded (stub)', [
            'count' => count($state),
        ]);

        // DIFF (FIXED: instance call)
        $diffEngine = new SchedulerDiff();
        $diff = $diffEngine->diff($state, $desired);

        GcsLog::info(
            $this->dryRun
                ? 'SchedulerDiff summary (dry-run)'
                : 'SchedulerDiff summary',
            [
                'adds'    => count($diff['adds']),
                'updates' => count($diff['updates']),
                'deletes' => count($diff['deletes']),
            ]
        );

        // APPLY
        $apply = new SchedulerApply($this->dryRun);
        $result = $apply->apply($state, $diff);

        if (!$this->dryRun && isset($result['state'])) {
            $this->writeSchedule($result['state']);
        }

        return [
            'adds'         => count($diff['adds']),
            'updates'      => count($diff['updates']),
            'deletes'      => count($diff['deletes']),
            'dryRun'       => $this->dryRun,
            'intents_seen' => count($desired),
        ];
    }

    private function writeSchedule(array $state): void
    {
        $path = '/home/fpp/media/config/schedule.json';

        if (file_exists($path)) {
            $bak = $path . '.bak-' . date('Ymd-His');
            copy($path, $bak);
            GcsLog::info('SchedulerApply backup created', [
                'path' => $bak,
            ]);
        }

        file_put_contents(
            $path,
            json_encode(array_values($state), JSON_PRETTY_PRINT)
        );
    }
}
