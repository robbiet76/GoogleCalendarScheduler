<?php
declare(strict_types=1);

/**
 * Scheduler diff result.
 *
 * Immutable value object representing scheduler changes.
 */
final class GcsSchedulerDiffResult
{
    /** @var array<int,array<string,mixed>> */
    private array $toCreate;

    /** @var array<int,array<string,mixed>> */
    private array $toUpdate;

    /** @var array<int,GcsExistingScheduleEntry> */
    private array $toDelete;

    /**
     * @param array<int,array<string,mixed>>      $toCreate
     * @param array<int,array<string,mixed>>      $toUpdate
     * @param array<int,GcsExistingScheduleEntry> $toDelete
     */
    public function __construct(array $toCreate, array $toUpdate, array $toDelete)
    {
        $this->toCreate = $toCreate;
        $this->toUpdate = $toUpdate;
        $this->toDelete = $toDelete;
    }

    /** @return array<int,array<string,mixed>> */
    public function creates(): array
    {
        return $this->toCreate;
    }

    /** @return array<int,array<string,mixed>> */
    public function updates(): array
    {
        return $this->toUpdate;
    }

    /** @return array<int,GcsExistingScheduleEntry> */
    public function deletes(): array
    {
        return $this->toDelete;
    }

    /** Convenience counts (optional but useful) */
    public function counts(): array
    {
        return [
            'creates' => count($this->toCreate),
            'updates' => count($this->toUpdate),
            'deletes' => count($this->toDelete),
        ];
    }
}
