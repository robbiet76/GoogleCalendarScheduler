<?php
declare(strict_types=1);

/**
 * SchedulerDiff
 *
 * Computes semantic differences between desired scheduler entries and
 * existing scheduler state.
 *
 * Identity model:
 * - Identity is derived from the planner-attached manifest on each desired entry.
 * - Existing managed scheduler entries are matched by the same manifest id.
 *
 * Notes:
 * - This file intentionally does NOT introduce new dependencies (no new methods
 *   on SchedulerState/SchedulerEntry, and no new SchedulerIdentity class).
 * - Manifest id extraction is implemented defensively to support the shapes we
 *   see across planner/sync/state layers.
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
            $id = self::extractManifestIdFromExisting($entry);
            if ($id === null) {
                continue;
            }
            $existingById[$id] = $entry;
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
     * Expected shapes (best effort):
     * - $entry['_manifest']['id']
     * - $entry['_manifest']['manifestId']
     * - $entry['_manifestId'] / $entry['manifestId']
     */
    private static function extractManifestIdFromDesired(array $entry): ?string
    {
        // Primary: planner-attached manifest bundle.
        if (isset($entry['_manifest']) && is_array($entry['_manifest'])) {
            $m = $entry['_manifest'];
            $id = self::extractIdFromManifestArray($m);
            if ($id !== null) {
                return $id;
            }
        }

        // Alternate common field names.
        foreach (['_manifestId', 'manifestId', 'manifest_id', 'manifestID'] as $k) {
            if (isset($entry[$k]) && is_string($entry[$k]) && $entry[$k] !== '') {
                return $entry[$k];
            }
        }

        return null;
    }

    /**
     * Extract manifest id from an existing scheduler entry (object or array).
     *
     * We avoid assuming a specific SchedulerEntry API; instead we try common patterns:
     * - array entry with '_manifest'
     * - object exposing toArray()/jsonSerialize()
     * - object exposing getMeta()/getData()/getExtra() returning arrays
     */
    private static function extractManifestIdFromExisting(mixed $entry): ?string
    {
        // If state entries are arrays.
        if (is_array($entry)) {
            return self::extractManifestIdFromDesired($entry);
        }

        // Try common conversion methods.
        if (is_object($entry)) {
            // toArray()
            if (method_exists($entry, 'toArray')) {
                $arr = $entry->toArray();
                if (is_array($arr)) {
                    $id = self::extractManifestIdFromDesired($arr);
                    if ($id !== null) {
                        return $id;
                    }
                }
            }

            // jsonSerialize()
            if ($entry instanceof JsonSerializable) {
                $arr = $entry->jsonSerialize();
                if (is_array($arr)) {
                    $id = self::extractManifestIdFromDesired($arr);
                    if ($id !== null) {
                        return $id;
                    }
                }
            }

            // getMeta()/getData()/getExtra() style accessors.
            foreach (['getMeta', 'getData', 'getExtra', 'getPayload'] as $m) {
                if (method_exists($entry, $m)) {
                    try {
                        $arr = $entry->{$m}();
                    } catch (Throwable $t) {
                        $arr = null;
                    }
                    if (is_array($arr)) {
                        $id = self::extractManifestIdFromDesired($arr);
                        if ($id !== null) {
                            return $id;
                        }
                        // Also allow manifest itself to be returned here.
                        $id = self::extractIdFromManifestArray($arr);
                        if ($id !== null) {
                            return $id;
                        }
                    }
                }
            }

            // Direct property (last resort).
            foreach (['_manifest', 'manifest', 'meta', 'data'] as $prop) {
                if (property_exists($entry, $prop)) {
                    $val = $entry->{$prop};
                    if (is_array($val)) {
                        $id = self::extractManifestIdFromDesired(['_manifest' => $val] + $val);
                        if ($id !== null) {
                            return $id;
                        }
                        $id = self::extractIdFromManifestArray($val);
                        if ($id !== null) {
                            return $id;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private static function extractIdFromManifestArray(array $manifest): ?string
    {
        foreach (['id', 'manifestId', 'manifest_id', 'manifestID', 'uid'] as $k) {
            if (isset($manifest[$k]) && is_string($manifest[$k]) && $manifest[$k] !== '') {
                return $manifest[$k];
            }
        }
        return null;
    }
}
