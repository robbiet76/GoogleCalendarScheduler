<?php
declare(strict_types=1);
// SchedulerDiff.php

/**
 * SchedulerDiff
 *
 * Computes differences between desired scheduler entries and existing scheduler state.
 *
 * Identity model:
 * - Desired entries MUST include a planner-attached `_manifest` array with a non-empty `id` string.
 * - Existing entries are considered plugin-managed ONLY if they include `_manifest.id`.
 * - SchedulerDiff ONLY compares desired entries against manifest-managed entries supplied by SchedulerState.
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
        if (defined('GCS_DEBUG') && GCS_DEBUG) {
            error_log('[GCS DEBUG][DIFF] compute() start: desired=' . count($this->desired));
        }


        // Index existing managed scheduler entries by manifest id.
        $existingManagedById = [];
        foreach ($this->state->getEntries() as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (defined('GCS_DEBUG') && GCS_DEBUG) {
                error_log('[GCS DEBUG][DIFF][EXISTING SHAPE] ' . json_encode([
                    'index' => $idx,
                    'keys'  => array_keys($entry),
                    'has_manifest' => isset($entry['_manifest']),
                    'has_range' => isset($entry['range']),
                    'has_startDate' => isset($entry['startDate']),
                    'has_endDate' => isset($entry['endDate']),
                    'has_days' => (isset($entry['days']) || isset($entry['day'])),
                ]));
            }
            $id = self::extractManifestIdFromExisting($entry);
            if ($id === null) {
                // Ignore unmanaged entries entirely.
                continue;
            } else {
                if (!isset($existingManagedById[$id])) {
                    $existingManagedById[$id] = $entry;
                }
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

            if (defined('GCS_DEBUG') && GCS_DEBUG) {
                error_log('[GCS DEBUG][DIFF][DESIRED SHAPE] ' . json_encode(array_keys($desiredEntry)));
            }

            if (defined('GCS_DEBUG') && GCS_DEBUG) {
                error_log('[GCS DEBUG][DIFF][DESIRED] ' . json_encode([
                    'has_manifest' => isset($desiredEntry['_manifest']),
                ]));
            }

            $id = self::extractManifestIdFromDesired($desiredEntry);

            if ($id === null) {
                error_log('[GCS ERROR][DIFF][MISSING_MANIFEST_ID] Missing manifest id in desired entry: ' . json_encode($desiredEntry));
                continue;
            }

            // Planner should not emit duplicates; if it does, keep first to avoid hard failure.
            if (isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;

            if (!isset($existingManagedById[$id])) {
                $toCreate[] = $desiredEntry;
                continue;
            }

            if (defined('GCS_DEBUG') && GCS_DEBUG) {
                error_log('[GCS DEBUG][DIFF][MATCH][MANAGED] ' . $id);
            }

            $existing = $existingManagedById[$id];

            if (defined('GCS_DEBUG') && GCS_DEBUG) {
                error_log('[GCS DEBUG][DIFF][COMPARE MANAGED] ' . json_encode([
                    'id' => $id,
                    'existing_keys' => array_keys($existing),
                    'desired_keys' => array_keys($desiredEntry),
                ]));
            }

            // Managed entries are compared strictly by canonical fields;
            // semantic matching, symbolic resolution, or FPPSemantics-style
            // normalization MUST NOT be used once a manifest id exists.
            //
            // Identity ownership is defined solely by _manifest.id.
            if (!SchedulerComparator::isEquivalent($existing, $desiredEntry)) {
                $toUpdate[] = [
                    'existing' => $existing,
                    'desired'  => $desiredEntry,
                ];
            }
        }

        // Any existing managed entry not present in desired must be deleted.
        $toDelete = [];
        foreach ($existingManagedById as $id => $entry) {
            if (!isset($seenIds[$id])) {
                $toDelete[] = $entry;
            }
        }
        if (defined('GCS_DEBUG') && GCS_DEBUG) {
            error_log('[GCS DEBUG][DIFF][SUMMARY] ' . json_encode([
                'desired' => count($this->desired),
                'creates' => count($toCreate),
                'updates' => count($toUpdate),
                'deletes' => count($toDelete),
            ]));
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
