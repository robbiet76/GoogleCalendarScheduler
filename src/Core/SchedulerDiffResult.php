<?php
declare(strict_types=1);

/**
 * SchedulerDiffResult
 *
 * Immutable value object representing the result of a scheduler diff.
 *
 * Responsibilities:
 * - Hold CREATE, UPDATE, and DELETE sets produced by diff computation
 * - Provide structured access to each change category
 * - Offer simple summary counts for convenience
 *
 * Guarantees:
 * - Immutable after construction
 * - Contains no business logic
 * - Does not perform comparisons or scheduler mutations
 *
 * This object is used to safely transport diff results between
 * planning, preview, and apply layers.
 */
final class SchedulerDiffResult
{
    /** @var array<int,array<string,mixed>> */
    private array $toCreate;

    /** @var array<int,array<string,mixed>> */
    private array $toUpdate;

    /** @var array<int,ExistingScheduleEntry> */
    private array $toDelete;

    /**
     * @param array<int,array<string,mixed>>      $toCreate
     * @param array<int,array<string,mixed>>      $toUpdate
     * @param array<int,ExistingScheduleEntry> $toDelete
     */
    public function __construct(array $toCreate, array $toUpdate, array $toDelete)
    {
        $this->toCreate = $toCreate;
        $this->toUpdate = $toUpdate;
        $this->toDelete = $toDelete;
    }

    /**
     * Scheduler entries to be created.
     *
     * @return array<int,array<string,mixed>>
     */
    public function creates(): array
    {
        return $this->toCreate;
    }

    /**
     * Scheduler entries to be updated.
     *
     * @return array<int,array<string,mixed>>
     */
    public function updates(): array
    {
        return $this->toUpdate;
    }

    /**
     * Scheduler entries to be deleted.
     *
     * @return array<int,ExistingScheduleEntry>
     */
    public function deletes(): array
    {
        return $this->toDelete;
    }

    /**
     * Convenience summary counts for UI and logging.
     *
     * @return array{creates:int,updates:int,deletes:int}
     */
    public function counts(): array
    {
        return [
            'creates' => count($this->toCreate),
            'updates' => count($this->toUpdate),
            'deletes' => count($this->toDelete),
        ];
    }
}
