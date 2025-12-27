<?php
declare(strict_types=1);

final class GcsExistingScheduleEntry
{
    /** @var array<string,mixed> */
    private array $raw;

    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(array $raw)
    {
        $this->raw = $raw;
    }

    /**
     * Extract GCS UID from scheduler entry using canonical identity helper.
     */
    public function getGcsUid(): ?string
    {
        return GcsSchedulerIdentity::extractUid($this->raw);
    }

    /**
     * @return array<string,mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
