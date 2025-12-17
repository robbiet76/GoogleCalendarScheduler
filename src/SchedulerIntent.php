<?php

final class GcsSchedulerIntent {
    public $uid;
    public $summary;
    public $type;      // playlist | sequence | command
    public $target;    // resolved name
    public $start;
    public $end;
    public $stopType;
    public $repeat;

    public function toArray(): array {
        return [
            'uid'       => $this->uid,
            'summary'   => $this->summary,
            'type'      => $this->type,
            'target'    => $this->target,
            'start'     => $this->start->format('Y-m-d H:i'),
            'end'       => $this->end->format('Y-m-d H:i'),
            'stopType'  => $this->stopType,
            'repeat'    => $this->repeat,
        ];
    }
}
