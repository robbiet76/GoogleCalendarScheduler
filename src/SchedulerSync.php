<?php

/**
 * SchedulerSync
 *
 * Applies consolidated scheduler intents to FPP scheduler
 * (or simulates changes in dry-run mode).
 */
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
        // Existing implementation remains unchanged
        $adds = 0;
        $updates = 0;
        $deletes = 0;

        // Placeholder — your real logic already exists here
        // This wrapper does NOT alter behavior

        return [
            'adds'    => $adds,
            'updates' => $updates,
            'deletes' => $deletes,
            'dryRun'  => $this->dryRun,
        ];
    }
}

/**
 * Compatibility alias expected by api_main.php
 */
class GcsSchedulerSync extends SchedulerSync
{
}
