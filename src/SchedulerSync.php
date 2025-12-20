<?php

final class GcsSchedulerSync
{
    private array $cfg;
    private int $horizonDays;
    private bool $dryRun;

    public function __construct(array $cfg, int $horizonDays, bool $dryRun)
    {
        $this->cfg = $cfg;
        $this->horizonDays = $horizonDays;
        $this->dryRun = $dryRun;
    }

    /**
     * Backwards-compatible entrypoint.
     * (Legacy callers may still call run(); it will perform a no-op diff.)
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        return $this->sync([]);
    }

    /**
     * Execute full pipeline using desired schedule entries produced by the runner.
     *
     * @param array<int,array<string,mixed>> $desiredEntries
     * @return array<string,mixed>
     */
    public function sync(array $desiredEntries): array
    {
        // Load scheduler state
        $state = GcsSchedulerState::load($this->horizonDays);

        GcsLog::info('SchedulerState loaded (stub)', [
            'count' => count($state->getEntries()),
        ]);

        // Compute diff (desired vs existing state)
        $diff = new GcsSchedulerDiff($desiredEntries, $state);
        $diffResult = $diff->compute();

        GcsLog::info('SchedulerDiff summary' . ($this->dryRun ? ' (dry-run)' : ''), [
            'create' => count($diffResult->getToCreate()),
            'update' => count($diffResult->getToUpdate()),
            'delete' => count($diffResult->getToDelete()),
        ]);

        // Apply
        $apply = new GcsSchedulerApply($this->dryRun);
        $applySummary = $apply->apply($diffResult);

        return [
            'dryRun' => $this->dryRun,
            'diff'   => $diffResult->toArray(),
            'apply'  => $applySummary,
        ];
    }
}
