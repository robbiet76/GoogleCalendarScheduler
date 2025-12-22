<?php

final class GcsComparableScheduleEntry
{
    /** @var array<string,mixed> */
    private array $entry;

    /**
     * @param array<string,mixed> $entry
     */
    public function __construct(array $entry)
    {
        $this->entry = $entry;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->entry;
    }
}
