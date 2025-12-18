<?php

class ExistingScheduleEntry
{
    public string $schedulerId; // FPP internal ID
    public string $uid;         // Google UID
    public string $start;
    public string $end;
    public string $target;
    public string $stopType;
    public ?int $repeat;
    public bool $enabled;

    public function __construct(
        string $schedulerId,
        string $uid,
        string $start,
        string $end,
        string $target,
        string $stopType,
        ?int $repeat,
        bool $enabled
    ) {
        $this->schedulerId = $schedulerId;
        $this->uid         = $uid;
        $this->start       = $start;
        $this->end         = $end;
        $this->target      = $target;
        $this->stopType    = $stopType;
        $this->repeat      = $repeat;
        $this->enabled     = $enabled;
    }

    public function toComparable(): ComparableScheduleEntry
    {
        return new ComparableScheduleEntry(
            $this->uid,
            $this->start,
            $this->end,
            $this->target,
            $this->stopType,
            $this->repeat,
            $this->enabled
        );
    }
}
