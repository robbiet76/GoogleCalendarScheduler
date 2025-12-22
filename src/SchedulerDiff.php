<?php

final class GcsSchedulerDiff
{
    /** @var array<int,array<string,mixed>> */
    private array $desired;

    private GcsSchedulerState $state;

    /**
     * @param array<int,array<string,mixed>> $desired
     */
    public function __construct(array $desired, GcsSchedulerState $state)
    {
        $this->desired = $desired;
        $this->state   = $state;
    }

    public function compute(): GcsSchedulerDiffResult
    {
        $existingByUid = [];
        foreach ($this->state->getEntries() as $entry) {
            $uid = $entry->getGcsUid();
            if ($uid !== null) {
                $existingByUid[$uid] = $entry;
            }
        }

        $toCreate = [];
        $toUpdate = [];
        $seen     = [];

        foreach ($this->desired as $desiredEntry) {
            $uid = GcsSchedulerIdentity::extractUid($desiredEntry);
            if ($uid === null) {
                // Desired entry without GCS identity is ignored
                continue;
            }

            $seen[$uid] = true;

            if (!isset($existingByUid[$uid])) {
                $toCreate[] = $desiredEntry;
                continue;
            }

            $existing = $existingByUid[$uid];

            if (!GcsSchedulerComparator::isEquivalent($existing, $desiredEntry)) {
                $toUpdate[] = [
                    'existing' => $existing,
                    'desired'  => $desiredEntry,
                ];
            }
        }

        $toDelete = [];
        foreach ($existingByUid as $uid => $entry) {
            if (!isset($seen[$uid])) {
                $toDelete[] = $entry;
            }
        }

        return new GcsSchedulerDiffResult($toCreate, $toUpdate, $toDelete);
    }
}
