<?php

final class ComparableScheduleEntry
{
    public string $uid;
    public string $start;
    public string $end;
    public string $target;
    public string $stopType;
    public ?int $repeat;
    public bool $enabled;

    public function __construct(
        string $uid,
        string $start,
        string $end,
        string $target,
        string $stopType,
        ?int $repeat,
        bool $enabled
    ) {
        $this->uid = $uid;
        $this->start = $start;
        $this->end = $end;
        $this->target = $target;
        $this->stopType = $stopType;
        $this->repeat = $repeat;
        $this->enabled = $enabled;
    }

    public function equals(self $other): bool
    {
        return
            $this->uid === $other->uid &&
            $this->start === $other->start &&
            $this->end === $other->end &&
            $this->target === $other->target &&
            $this->stopType === $other->stopType &&
            $this->repeat === $other->repeat &&
            $this->enabled === $other->enabled;
    }
}
