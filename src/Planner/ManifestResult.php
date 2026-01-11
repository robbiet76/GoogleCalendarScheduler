<?php
declare(strict_types=1);

/**
 * ManifestResult
 *
 * Canonical reconciliation output between:
 *  - Google Calendar intent
 *  - Existing FPP scheduler state
 *
 * This object is:
 *  - Immutable by convention (built once, consumed many times)
 *  - Read-only
 *  - Used by Preview and Apply layers
 *
 * It contains NO business logic.
 */
final class ManifestResult
{
    /**
     * Scheduler entries that should be created.
     *
     * @var array<int, ScheduleIntent>
     */
    public array $create = [];

    /**
     * Scheduler entries that should be updated.
     *
     * Each element contains:
     *  - before: ExistingSchedulerEntry
     *  - after:  ScheduleIntent
     *
     * @var array<int, array{before: mixed, after: mixed}>
     */
    public array $update = [];

    /**
     * Scheduler entries that should be deleted.
     *
     * @var array<int, mixed>
     */
    public array $delete = [];

    /**
     * Scheduler entries that are already correct and unchanged.
     *
     * @var array<int, mixed>
     */
    public array $unchanged = [];

    /**
     * Conflicts that could not be resolved safely.
     *
     * Examples:
     *  - Multiple matches
     *  - Ambiguous identity
     *  - Partial overlaps
     *
     * @var array<int, array<string,mixed>>
     */
    public array $conflicts = [];

    /**
     * Optional informational or warning messages.
     *
     * @var array<int,string>
     */
    public array $messages = [];

    public static function fromPlannerResult(array $plan): self
    {
        $instance = new self();
        $instance->create = $plan['creates'] ?? [];
        $instance->update = $plan['updates'] ?? [];
        $instance->delete = $plan['deletes'] ?? [];
        $instance->unchanged = $plan['unchanged'] ?? [];
        $instance->conflicts = $plan['conflicts'] ?? [];
        $instance->messages = $plan['messages'] ?? [];
        return $instance;
    }

    /**
     * Summary counts for UI and logging.
     */
    public function summary(): array
    {
        return [
            'create'    => count($this->create),
            'update'    => count($this->update),
            'delete'    => count($this->delete),
            'unchanged' => count($this->unchanged),
            'conflicts' => count($this->conflicts),
        ];
    }

    /**
     * True if this manifest would result in no scheduler changes.
     */
    public function isNoop(): bool
    {
        return
            empty($this->create) &&
            empty($this->update) &&
            empty($this->delete) &&
            empty($this->conflicts);
    }

    /**
     * @return array<int, ScheduleIntent>
     */
    public function creates(): array
    {
        return $this->create;
    }

    /**
     * @return array<int, array{before: mixed, after: mixed}>
     */
    public function updates(): array
    {
        return $this->update;
    }

    /**
     * @return array<int, mixed>
     */
    public function deletes(): array
    {
        return $this->delete;
    }

    /**
     * @return array<int, mixed>
     */
    public function unchanged(): array
    {
        return $this->unchanged;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * @return array<int,string>
     */
    public function messages(): array
    {
        return $this->messages;
    }
}