<?php

final class SchedulerApply
{
    private bool $dryRun;

    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }

    public function apply(array $state, array $diff): array
    {
        $originalCount = count($state);

        // ADD
        foreach ($diff['adds'] as $a) {
            if ($this->dryRun) {
                GcsLog::info('[DRY-RUN] ADD', $this->summarize($a));
                continue;
            }
            $state[] = $a;
        }

        // UPDATE
        foreach ($diff['updates'] as $u) {
            if ($this->dryRun) {
                GcsLog::info('[DRY-RUN] UPDATE', [
                    'from' => $this->summarize($u['before']),
                    'to'   => $this->summarize($u['after']),
                ]);
                continue;
            }

            $state[$u['index']] = $u['after'];
        }

        if ($this->dryRun) {
            return [
                'originalCount' => $originalCount,
                'finalCount'    => $originalCount,
            ];
        }

        return [
            'originalCount' => $originalCount,
            'finalCount'    => count($state),
            'state'         => $state,
        ];
    }

    private function summarize(array $e): array
    {
        return [
            'playlist' => $e['playlist'] ?? '',
            'days'     => $e['dayMask'] ?? 0,
            'start'    => $e['startTime'] ?? '',
            'end'      => $e['endTime'] ?? '',
            'from'     => $e['startDate'] ?? '',
            'to'       => $e['endDate'] ?? '',
        ];
    }
}
