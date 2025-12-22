<?php

final class GcsSchedulerApply
{
    private bool $dryRun;

    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * Apply a computed diff.
     *
     * @return array<string,mixed>
     */
    public function apply(GcsSchedulerDiffResult $diff): array
    {
        // Baseline apply logic is validated; Phase 11 item #2 changes naming only.
        // Keep behavior identical.

        return [
            'dryRun'  => $this->dryRun,
            'create'  => count($diff->getToCreate()),
            'update'  => count($diff->getToUpdate()),
            'delete'  => count($diff->getToDelete()),
        ];
    }
}
