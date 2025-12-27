<?php
declare(strict_types=1);

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
        // Index existing entries by GCS UID
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

        // Process desired entries
        foreach ($this->desired as $desiredEntry) {
            if (!is_array($desiredEntry)) {
                continue;
            }

            $uid = GcsSchedulerIdentity::extractKey($desiredEntry);
            if ($uid === null) {
                // Desired entry without GCS identity is ignored
                continue;
            }

            $seenUids[$uid] = true;

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

        // Anything existing but not seen in desired must be deleted
        $toDelete = [];

        foreach ($existingByUid as $uid => $entry) {
            if (!isset($seenUids[$uid])) {
                $toDelete[] = $entry;
            }
        }

        return new GcsSchedulerDiffResult($toCreate, $toUpdate, $toDelete);
    }
}
