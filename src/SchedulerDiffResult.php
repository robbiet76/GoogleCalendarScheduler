<?php

final class SchedulerDiffResult
{
    /** @var ComparableScheduleEntry[] */
    public array $create = [];

    /** @var array<string,array{existing:ExistingScheduleEntry,desired:ComparableScheduleEntry}> */
    public array $update = [];

    /** @var ExistingScheduleEntry[] */
    public array $delete = [];

    /** @var ComparableScheduleEntry[] */
    public array $noop = [];
}
