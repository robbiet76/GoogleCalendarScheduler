<?php
declare(strict_types=1);

/**
 * SchedulerDiff
 *
 * Computes semantic differences between desired scheduler entries and
 * existing scheduler state.
 *
 * Phase 29 identity model:
 * - Identity = UID ONLY
 * - No planner semantics are inferred from tags
 *
 * Responsibilities:
 * - Match scheduler entries by UID
 * - Determine CREATE / UPDATE / DELETE actions
 * - Delegate semantic equality checks to SchedulerComparator
 *
 * Guarantees:
 * - No writes
 * - No side effects
 * - Deterministic output for a given desired set and state snapshot
 *
 * Does NOT:
 * - Resolve calendar intents
 * - Modify scheduler state
 * - Mutate desired entries
 */
final class SchedulerDiff
{
    /** @var array<int,array<string,mixed>> */
    private array $desired;

    private SchedulerState $state;

    /**
     * @param array<int,array<string,mixed>> $desired
     */
    public function __construct(array $desired, SchedulerState $state)
    {
        $this->desired = $desired;
        $this->state   = $state;
    }

    public function compute(): SchedulerDiffResult
    {
        /*
         * Index existing managed entries by UID
         */
        $existingByUid = [];

        foreach ($this->state->getEntries() as $entry) {
            $uid = $entry->getGcsUid();
            if ($uid !== null) {
                $existingByUid[$uid] = $entry;
            }
        }

        $toCreate = [];
        $toUpdate = [];
        $seenUids = [];

        /*
         * Process desired scheduler entries
         */
        foreach ($this->desired as $desiredEntry) {
            if (!is_array($desiredEntry)) {
                continue;
            }

            $uid = SchedulerIdentity::extractKey($desiredEntry);
            if ($uid === null) {
                // Desired entries without UID identity are ignored
                continue;
            }

            $seenUids[$uid] = true;

            if (!isset($existingByUid[$uid])) {
                $toCreate[] = $desiredEntry;
                continue;
            }

            $existing = $existingByUid[$uid];

            if (!SchedulerComparator::isEquivalent($existing, $desiredEntry)) {
                $toUpdate[] = [
                    'existing' => $existing,
                    'desired'  => $desiredEntry,
                ];
            }
        }

        /*
         * Any existing managed entry not present in desired must be deleted
         */
        $toDelete = [];

        foreach ($existingByUid as $uid => $entry) {
            if (!isset($seenUids[$uid])) {
                $toDelete[] = $entry;
            }
        }

        return new SchedulerDiffResult($toCreate, $toUpdate, $toDelete);
    }
}
