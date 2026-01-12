<?php
declare(strict_types=1);

/**
 * SchedulerDiff
 *
 * Computes semantic differences between desired scheduler entries and
 * existing scheduler state.
 *
 * Identity model:
 * - Desired entries MAY include a planner-attached `_manifest` array with a non-empty `id` string.
 * - Existing entries are considered plugin-managed ONLY if they include `_manifest.id`.
 * - Existing entries without `_manifest.id` are eligible for adoption via semantic matching.
 * - Semantic matching is used only when `_manifest.id` is absent.
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
        $existingManagedById = [];
        $existingUnmanaged = [];
        foreach ($this->state->getEntries() as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = self::extractManifestIdFromExisting($entry);
            if ($id === null) {
                // Preserve original index so consumption tracking is stable.
                $existingUnmanaged[$idx] = $entry;
            } else {
                if (!isset($existingManagedById[$id])) {
                    $existingManagedById[$id] = $entry;
                }
            }
        }

        $toCreate = [];
        $toUpdate = [];
        $seenIds  = [];
        $consumedUnmanaged = [];

        // Process desired scheduler entries.
        foreach ($this->desired as $desiredEntry) {
            if (!is_array($desiredEntry)) {
                continue;
            }

            if (defined('GCS_DEBUG') && GCS_DEBUG) {
                error_log('[GCS DEBUG][DIFF][DESIRED] ' . json_encode([
                    'has_manifest' => isset($desiredEntry['_manifest']),
                ]));
            }

            // If a manifest bundle is present, it must contain a valid non-empty id.
            if (isset($desiredEntry['_manifest']) && is_array($desiredEntry['_manifest'])) {
                if (self::extractManifestIdFromDesired($desiredEntry) === null) {
                    // Planner emitted a malformed manifest; do not treat this as an adoption candidate.
                    continue;
                }
            }

            $id = self::extractManifestIdFromDesired($desiredEntry);

            if ($id !== null) {
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

                // Managed entries are compared strictly by canonical fields;
                // semantic matching MUST NOT be used once a manifest id exists.
                if (!SchedulerComparator::isEquivalent($existing, $desiredEntry)) {
                    $toUpdate[] = [
                        'existing' => $existing,
                        'desired'  => $desiredEntry,
                    ];
                }
            } else {
                // Desired entry with no manifest bundle at all → adoption candidate.
                if (isset($desiredEntry['_manifest'])) {
                    // If _manifest exists here, it is missing/empty id (guarded above) or malformed; skip.
                    continue;
                }

                $matched = false;

                foreach ($existingUnmanaged as $key => $existing) {
                    if (isset($consumedUnmanaged[$key])) {
                        continue;
                    }

                    if (defined('GCS_DEBUG') && GCS_DEBUG) {
                        error_log('[GCS DEBUG][DIFF][CHECK][ADOPT] ' . json_encode([
                            'existing_index' => $key,
                        ]));
                    }

                    // Semantic equivalence check (UID intentionally ignored)
                    if (ManifestIdentity::semanticMatch($existing, $desiredEntry)) {
                        $toUpdate[] = [
                            'existing' => $existing,
                            'desired'  => $desiredEntry,
                        ];
                        $consumedUnmanaged[$key] = true;
                        $matched = true;

                        if (defined('GCS_DEBUG') && GCS_DEBUG) {
                            error_log('[GCS DEBUG][DIFF][ADOPTED]');
                        }

                        break;
                    }
                }

                if (!$matched) {
                    // No semantic match → new schedule entry
                    $toCreate[] = $desiredEntry;

                    if (defined('GCS_DEBUG') && GCS_DEBUG) {
                        error_log('[GCS DEBUG][DIFF][CREATE]');
                    }
                }
            }
        }

        // Any existing managed entry not present in desired must be deleted.
        $toDelete = [];
        foreach ($existingManagedById as $id => $entry) {
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
