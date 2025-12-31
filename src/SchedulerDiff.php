<?php
declare(strict_types=1);

/**
 * GcsSchedulerDiff
 *
 * Computes semantic differences between desired scheduler entries and
 * existing scheduler state.
 *
 * Responsibilities:
 * - Match scheduler entries by GCS identity (UID)
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
 * - Perform persistence or apply operations
 */
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
        // Index existing scheduler entries by GCS UID
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

        // Process desired scheduler entries
        foreach ($this->desired as $desiredEntry) {
            if (!is_array($desiredEntry)) {
                continue;
            }

            $uid = GcsSchedulerIdentity::extractKey($desiredEntry);
            if ($uid === null) {
                // Desired entries without GCS identity are ignored
                continue;
            }

            $seenUids[$uid] = true;

            if (!isset($existingByUid[$uid])) {
                // Normalize CREATE entries to use series startDate
                // encoded in the GCS identity range
                $desiredEntry = $this->applySeriesStartDateIfPresent($desiredEntry);

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

        // Any existing entries not present in desired must be deleted
        $toDelete = [];

        foreach ($existingByUid as $uid => $entry) {
            if (!isset($seenUids[$uid])) {
                $toDelete[] = $entry;
            }
        }

        return new GcsSchedulerDiffResult($toCreate, $toUpdate, $toDelete);
    }

    /**
     * Normalize CREATE entries to use the calendar series startDate
     * encoded in the GCS identity range.
     *
     * This ensures newly created scheduler entries align with the
     * originating calendar series start.
     *
     * NOTE:
     * - Applied only on CREATE
     * - UPDATE entries preserve existing scheduler startDate
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function applySeriesStartDateIfPresent(array $entry): array
    {
        if (empty($entry['args']) || !is_array($entry['args'])) {
            return $entry;
        }

        foreach ($entry['args'] as $arg) {
            if (!is_string($arg)) {
                continue;
            }

            if (preg_match(
                '/\|GCS:v1\|.*range=([0-9]{4}-[0-9]{2}-[0-9]{2})\.\./',
                $arg,
                $m
            )) {
                $seriesStart = $m[1];

                if ($this->isValidYmd($seriesStart)) {
                    $entry['startDate'] = $seriesStart;
                }
                break;
            }
        }

        return $entry;
    }

    private function isValidYmd(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $s);
        return ($dt instanceof DateTime) && ($dt->format('Y-m-d') === $s);
    }
}
