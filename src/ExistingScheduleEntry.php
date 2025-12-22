<?php

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
     * Extract GCS UID from scheduler tag.
     */
    public function getGcsUid(): ?string
    {
        $tag = $this->raw['tag'] ?? null;
        if (!is_string($tag)) {
            return null;
        }

        if (strpos($tag, GcsSchedulerIdentity::TAG_PREFIX) !== 0) {
            return null;
        }

        $uid = substr($tag, strlen(GcsSchedulerIdentity::TAG_PREFIX));
        return $uid !== '' ? $uid : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
