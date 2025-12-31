<?php
declare(strict_types=1);

/**
 * SchedulerState
 *
 * Immutable snapshot of the current FPP scheduler state.
 *
 * Responsibilities:
 * - Hold existing scheduler entries as domain objects
 * - Provide a stable view of scheduler state for diff operations
 *
 * Guarantees:
 * - Read-only after construction
 * - No scheduler mutation
 * - No inference or transformation logic
 *
 * This class exists to clearly separate the concept of
 * "current scheduler state" from planning, diffing, and apply logic.
 */
final class SchedulerState
{
    /** @var array<int,ExistingScheduleEntry> */
    private array $entries;

    /**
     * @param array<int,ExistingScheduleEntry> $entries
     */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /**
     * Retrieve all existing scheduler entries.
     *
     * @return array<int,ExistingScheduleEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
