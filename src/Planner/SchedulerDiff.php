<?php
declare(strict_types=1);

/**
 * SchedulerDiff
 *
 * Computes semantic differences between desired scheduler entries and
 * existing scheduler state.
 *
 * Identity model:
 * - Desired entries MUST include a planner-attached `_manifest` array with a non-empty `id` string.
 * - Existing entries are considered plugin-managed ONLY if they include `_manifest.id`.
 * - Unmanaged existing entries (typical FPP schedules) are still read by SchedulerState but are ignored by the diff.
 *
 * Notes:
 * - This file intentionally does NOT introduce new dependencies (no new methods
 *   on SchedulerState/SchedulerEntry, and no new SchedulerIdentity class).
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
        // Index existing managed scheduler entries by manifest id.
        $existingById = [];
        foreach ($this->state->getEntries() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = self::extractManifestIdFromExisting($entry);
            if ($id === null) {
                continue;
            }
            if (!isset($existingById[$id])) {
                $existingById[$id] = $entry;
            }
        }

        $toCreate = [];
        $toUpdate = [];
        $seenIds  = [];

        // Process desired scheduler entries.
        foreach ($this->desired as $desiredEntry) {
            if (!is_array($desiredEntry)) {
                continue;
            }

            $id = self::extractManifestIdFromDesired($desiredEntry);
            if ($id === null) {
                // Desired entries without manifest identity are ignored.
                continue;
            }

            // Planner should not emit duplicates; if it does, keep first to avoid hard failure.
            if (isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;

            if (!isset($existingById[$id])) {
                $toCreate[] = $desiredEntry;
                continue;
            }

            $existing = $existingById[$id];

            if (!SchedulerComparator::isEquivalent($existing, $desiredEntry)) {
                $toUpdate[] = [
                    'existing' => $existing,
                    'desired'  => $desiredEntry,
                ];
            }
        }

        // Any existing managed entry not present in desired must be deleted.
        $toDelete = [];
        foreach ($existingById as $id => $entry) {
            if (!isset($seenIds[$id])) {
                $toDelete[] = $entry;
            }
        }

        return new SchedulerDiffResult($toCreate, $toUpdate, $toDelete);
    }

    /**
     * Extract manifest id from a desired entry array.
     *
     * Desired entries must have a planner-attached manifest bundle.
     */
    private static function extractManifestIdFromDesired(array $entry): ?string
    {
        // Planner-attached manifest bundle.
        if (isset($entry['_manifest']) && is_array($entry['_manifest'])) {
            $id = self::extractIdFromManifestArray($entry['_manifest']);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Extract manifest id from an existing scheduler entry array.
     *
     * Existing entries are managed only if they carry plugin-managed manifest metadata at the top level.
     *
     * @param array<string,mixed> $entry
     */
    private static function extractManifestIdFromExisting(array $entry): ?string
    {
        // Preferred: embedded manifest bundle.
        if (isset($entry['_manifest']) && is_array($entry['_manifest'])) {
            $id = self::extractIdFromManifestArray($entry['_manifest']);
            if ($id !== null) {
                return $id;
            }
        }

        // If there is no manifest bundle, this is an unmanaged (plain FPP) entry.
        return null;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private static function extractIdFromManifestArray(array $manifest): ?string
    {
        // UID is optional and must not be treated as identity.
        foreach (['id'] as $k) {
            if (isset($manifest[$k]) && is_string($manifest[$k])) {
                $v = trim($manifest[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return null;
    }
}
