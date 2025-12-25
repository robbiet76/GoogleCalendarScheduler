<?php

class SchedulerSync
{
    private bool $dryRun;

    public function __construct(bool $dryRun = true)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * @param array<int,array<string,mixed>> $intents
     * @return array<string,mixed>
     */
    public function sync(array $intents): array
    {
        $adds = 0;

        foreach ($intents as $intent) {
            GcsLogger::instance()->info(
                $this->dryRun ? 'Scheduler intent (dry-run)' : 'Scheduler intent',
                $intent
            );
            $adds++;
        }

        /*
         * Phase 13 behavior:
         * - Report creates based on resolved intents
         * - Do NOT apply scheduler mutations yet
         */
        return [
            'adds'         => $adds,
            'updates'      => 0,
            'deletes'      => 0,
            'dryRun'       => $this->dryRun,
            'intents_seen' => $adds,
        ];
    }
}

class GcsSchedulerSync extends SchedulerSync {}
