<?php
declare(strict_types=1);

final class GcsSchedulerState
{
    /** @var array<int,GcsExistingScheduleEntry> */
    private array $entries;

    /**
     * @param array<int,GcsExistingScheduleEntry> $entries
     */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /**
     * @return array<int,GcsExistingScheduleEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
