<?php
declare(strict_types=1);

/**
 * Wrapper for an existing FPP scheduler entry.
 *
 * Phase 17+ behavior:
 * - Identity is derived from GCS tag stored in args[]
 */
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
     * Extract GCS UID from scheduler entry.
     */
    public function getGcsUid(): ?string
    {
        return GcsSchedulerIdentity::extractKey($this->raw);
    }

    /**
     * True if entry is managed by GCS.
     */
    public function isGcsManaged(): bool
    {
        return GcsSchedulerIdentity::isGcsManaged($this->raw);
    }

    /**
     * Raw scheduler entry.
     *
     * @return array<string,mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * UI-safe representation of this scheduler entry.
     * Ensures no objects leak into JSON preview output.
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
