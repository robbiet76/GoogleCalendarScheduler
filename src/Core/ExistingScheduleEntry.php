<?php
declare(strict_types=1);

/**
 * ExistingScheduleEntry
 *
 * Lightweight wrapper around a raw FPP scheduler entry.
 *
 * Responsibilities:
 * - Expose scheduler entry identity via GCS tag
 * - Provide managed/unmanaged classification
 * - Offer UI-safe representations for preview output
 *
 * Guarantees:
 * - Read-only wrapper (does not mutate underlying data)
 * - Identity is derived solely from GCS identity tags
 * - No scheduler state inference beyond raw entry contents
 */
final class ExistingScheduleEntry
{
    /** @var array<string,mixed> */
    private array $raw;

    /**
     * @param array<string,mixed> $raw Raw scheduler.json entry
     */
    public function __construct(array $raw)
    {
        $this->raw = $raw;
    }

    /**
     * Extract the GCS identity key from this scheduler entry.
     *
     * @return string|null GCS UID or null if not present
     */
    public function getGcsUid(): ?string
    {
        return SchedulerIdentity::extractKey($this->raw);
    }

    /**
     * Determine whether this scheduler entry is managed by GCS.
     */
    public function isGcsManaged(): bool
    {
        return SchedulerIdentity::isGcsManaged($this->raw);
    }

    /**
     * Retrieve the raw scheduler entry.
     *
     * @return array<string,mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Produce a UI-safe representation of this scheduler entry.
     *
     * Ensures no objects or non-serializable values leak into
     * JSON preview or API responses.
     *
     * @return array<string,mixed>
     */
    public function toPreviewArray(): array
    {
        return [
            'uid'       => $this->getGcsUid(),
            'playlist'  => isset($this->raw['playlist']) ? (string)$this->raw['playlist'] : '',
            'command'   => isset($this->raw['command']) ? (string)$this->raw['command'] : '',
            'startDate' => $this->raw['startDate'] ?? null,
            'startTime' => $this->raw['startTime'] ?? null,
            'endDate'   => $this->raw['endDate'] ?? null,
            'endTime'   => $this->raw['endTime'] ?? null,
            'managed'   => $this->isGcsManaged(),
        ];
    }
}
