<?php
declare(strict_types=1);

/**
 * GcsComparableScheduleEntry
 *
 * Lightweight value wrapper for a scheduler entry intended
 * for comparison or normalization purposes.
 *
 * Responsibilities:
 * - Encapsulate a scheduler entry as a comparable value object
 * - Provide a stable array representation for downstream logic
 *
 * Guarantees:
 * - Read-only wrapper
 * - No mutation or inference of scheduler data
 * - No ownership or identity logic
 *
 * This class exists to make intent explicit when a scheduler
 * entry is being treated purely as a comparable value rather
 * than as a domain object with behavior.
 */
final class GcsComparableScheduleEntry
{
    /** @var array<string,mixed> */
    private array $entry;

    /**
     * @param array<string,mixed> $entry Scheduler entry data
     */
    public function __construct(array $entry)
    {
        $this->entry = $entry;
    }

    /**
     * Return the underlying scheduler entry as an array.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->entry;
    }
}
