<?php
declare(strict_types=1);

/**
 * SchedulerIntent
 *
 * Legacy value object representing a single scheduling intent.
 *
 * Historical context:
 * - Earlier phases represented intents as mutable objects
 * - The current pipeline uses array-based intents for flexibility
 *
 * Current status:
 * - This class is retained for compatibility and historical reference
 * - It is NOT part of the primary intent pipeline
 * - No new code should depend on this class
 *
 * Guarantees:
 * - Simple data container only
 * - No validation, inference, or side effects
 */
final class SchedulerIntent
{
    /** @var string */
    public $uid;

    /** @var string */
    public $summary;

    /** @var string playlist | sequence | command */
    public $type;

    /** @var string Resolved target name */
    public $target;

    /** @var \DateTime */
    public $start;

    /** @var \DateTime */
    public $end;

    /** @var string */
    public $stopType;

    /** @var int|string */
    public $repeat;

    /**
     * Convert intent to a serializable array.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'uid'      => $this->uid,
            'summary'  => $this->summary,
            'type'     => $this->type,
            'target'   => $this->target,
            'start'    => $this->start->format('Y-m-d H:i'),
            'end'      => $this->end->format('Y-m-d H:i'),
            'stopType' => $this->stopType,
            'repeat'   => $this->repeat,
        ];
    }
}
