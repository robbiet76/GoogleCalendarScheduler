<?php
declare(strict_types=1);
// SchedulerDiff.php

/**
 * SchedulerDiff
 *
 * Computes differences between desired scheduler entries and existing scheduler state.
 *
 * Identity model:
 * - Desired entries MAY include a planner-attached `_manifest` array with a non-empty `id` string.
 * - Existing entries are considered plugin-managed ONLY if they include `_manifest.id`.
 * - Existing entries without `_manifest.id` are eligible for adoption via strict identity matching.
 * - Adoption uses strict ManifestIdentity-derived IDs for both desired and existing entries.
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

        $isNormalizedScheduleEntry = static function (array $e): bool {
            // Must look like a schedule entry, not a manifest/preview wrapper.
            // Required: date range info and time info.
            if (isset($e['range']) && is_array($e['range'])) {
                $range = $e['range'];
                $hasDates = (isset($range['start']) && isset($range['end']) && (isset($range['days']) || isset($range['day'])));
            } else {
                $hasDates = (isset($e['startDate']) && isset($e['endDate']) && (isset($e['days']) || isset($e['day'])));
            }
            $hasTimes = (isset($e['startTime']) && isset($e['endTime']));
            // Must have some target/type indicator typical of entries.
            $hasTarget = (isset($e['playlist']) || isset($e['sequence']) || isset($e['command']) || isset($e['target']) || isset($e['type']));
            return $hasDates && $hasTimes && $hasTarget;
        };

        $hasPlannerManifestUid = static function (array $e): bool {
            if (!isset($e['_manifest']) || !is_array($e['_manifest'])) {
                return false;
            }
            $uid = $e['_manifest']['uid'] ?? null;
            return is_string($uid) && trim($uid) != '';
        };

        // Index existing managed scheduler entries by manifest id.
        $existingManagedById = [];
        $existingUnmanaged = [];
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
                error_log('[GCS DEBUG][DIFF][DESIRED SHAPE] ' . json_encode(array_keys($desiredEntry)));
            }

            if (defined('GCS_DEBUG') && GCS_DEBUG) {
                error_log('[GCS DEBUG][DIFF][DESIRED] ' . json_encode([
                    'has_manifest' => isset($desiredEntry['_manifest']),
                ]));
            }

            // Guardrail: diff only operates on normalized schedule-entry-shaped arrays.
            // If the planner payload includes wrapper rows (inventory/templates/preview envelopes), skip them.
            if (!$isNormalizedScheduleEntry($desiredEntry)) {
                if (defined('GCS_DEBUG') && GCS_DEBUG) {
                    error_log('[GCS DEBUG][DIFF][DESIRED SKIP NON_NORMALIZED] ' . json_encode([
                        'keys' => array_keys($desiredEntry),
                        'has_manifest' => isset($desiredEntry['_manifest']),
                    ]));
                }
                continue;
            }

            // If a manifest bundle is present, it may contain either an adopted `id` (managed) OR a planner `uid` (adoption candidate).
            if (isset($desiredEntry['_manifest']) && is_array($desiredEntry['_manifest'])) {
                $manifestId  = self::extractManifestIdFromDesired($desiredEntry);
                $manifestUid = $desiredEntry['_manifest']['uid'] ?? null;

                // Drop only if BOTH id and uid are missing/invalid.
                if ($manifestId === null && (!is_string($manifestUid) || trim($manifestUid) === '')) {
                    if (defined('GCS_DEBUG') && GCS_DEBUG) {
                        error_log('[GCS DEBUG][DIFF][DROP DESIRED][NO_ID_NO_UID] ' . json_encode([
                            'manifest' => $desiredEntry['_manifest'],
                        ]));
                    }
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

                if (defined('GCS_DEBUG') && GCS_DEBUG) {
                    error_log('[GCS DEBUG][DIFF][COMPARE MANAGED] ' . json_encode([
                        'id' => $id,
                        'existing_keys' => array_keys($existing),
                        'desired_keys' => array_keys($desiredEntry),
                    ]));
                }

                // Managed entries are compared strictly by canonical fields;
                // semantic matching MUST NOT be used once a manifest id exists.
                if (!SchedulerComparator::isEquivalent($existing, $desiredEntry)) {
                    $toUpdate[] = [
                        'existing' => $existing,
                        'desired'  => $desiredEntry,
                    ];
                }
            } else {
                // Adoption candidates are planner-emitted desired schedule entries that have a stable planner UID.
                // We never attempt adoption for inventory/template rows.
                if (!$hasPlannerManifestUid($desiredEntry)) {
                    if (defined('GCS_DEBUG') && GCS_DEBUG) {
                        error_log('[GCS DEBUG][DIFF][ADOPT SKIP NO_PLANNER_UID] ' . json_encode([
                            'keys' => array_keys($desiredEntry),
                            'has_manifest' => isset($desiredEntry['_manifest']),
                        ]));
                    }
                    // Without a planner UID, we cannot safely adopt; treat as create.
                    $toCreate[] = $desiredEntry;
                    if (defined('GCS_DEBUG') && GCS_DEBUG) {
                        error_log('[GCS DEBUG][DIFF][CREATE][NO_PLANNER_UID]');
                    }
                    continue;
                }

                $matched = false;

                foreach ($existingUnmanaged as $key => $existing) {
                    if (isset($consumedUnmanaged[$key])) {
                        continue;
                    }

                    $existingOk = $isNormalizedScheduleEntry($existing);
                    $desiredOk  = $isNormalizedScheduleEntry($desiredEntry);

                    if (!$existingOk || !$desiredOk) {
                        if (defined('GCS_DEBUG') && GCS_DEBUG) {
                            error_log('[GCS DEBUG][DIFF][ADOPT SKIP NON_NORMALIZED] ' . json_encode([
                                'existing_index' => $key,
                                'existing_ok' => $existingOk,
                                'desired_ok' => $desiredOk,
                                'existing_keys' => array_keys($existing),
                                'desired_keys' => array_keys($desiredEntry),
                            ]));
                        }
                        continue;
                    }

                    $existingNorm = self::normalizeIdentityInput($existing);
                    $desiredNorm = self::normalizeIdentityInput($desiredEntry);

                    $existingId = ManifestIdentity::buildId($existingNorm);
                    $desiredId  = ManifestIdentity::buildId($desiredNorm);

                    if ($existingId === '' || $desiredId === '') {
                        if (defined('GCS_DEBUG') && GCS_DEBUG) {
                            error_log('[GCS DEBUG][DIFF][ADOPT SKIP INVALID ID] ' . json_encode([
                                'existing_norm' => $existingNorm,
                                'desired_norm'  => $desiredNorm,
                            ]));
                        }
                        continue;
                    }

                    if (defined('GCS_DEBUG') && GCS_DEBUG) {
                        error_log('[GCS DEBUG][ADOPT][IDENTITY INPUT RAW] ' . json_encode([
                            'existing_raw' => $existing,
                            'desired_raw'  => $desiredEntry,
                        ], JSON_PRETTY_PRINT));

                        error_log('[GCS DEBUG][ADOPT][IDENTITY INPUT NORMALIZED] ' . json_encode([
                            'existing_norm' => $existingNorm,
                            'desired_norm'  => $desiredNorm,
                        ], JSON_PRETTY_PRINT));
                    }

                    if (defined('GCS_DEBUG') && GCS_DEBUG) {
                        error_log('[GCS DEBUG][DIFF][ADOPT ID COMPARE] ' . json_encode([
                            'existingId' => $existingId,
                            'desiredId'  => $desiredId,
                        ]));
                    }

                    $matchResult = ($existingId === $desiredId);

                    if (defined('GCS_DEBUG') && GCS_DEBUG) {
                        error_log('[GCS DEBUG][ADOPT][IDENTITY RESULT] ' . json_encode([
                            'result' => $matchResult,
                        ]));
                    }

                    if ($matchResult) {
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
                    // No identity match → new schedule entry
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
     * Normalize an entry into the canonical identity shape expected by ManifestIdentity.
     *
     * Canonical keys:
     * - startDate, endDate
     * - days (or day)
     *
     * Planner often carries these inside range:{start,end,days}.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function normalizeIdentityInput(array $entry): array
    {
        // If already canonical, leave it alone.
        $hasDates = isset($entry['startDate']) || isset($entry['endDate']);
        $hasDays = isset($entry['days']) || isset($entry['day']);

        if ((!$hasDates || !$hasDays) && isset($entry['range']) && is_array($entry['range'])) {
            $range = $entry['range'];

            if (!isset($entry['startDate']) && isset($range['start']) && is_string($range['start'])) {
                $entry['startDate'] = $range['start'];
            }
            if (!isset($entry['endDate']) && isset($range['end']) && is_string($range['end'])) {
                $entry['endDate'] = $range['end'];
            }
            if (!isset($entry['days']) && !isset($entry['day'])) {
                if (isset($range['days']) && is_string($range['days'])) {
                    $entry['days'] = $range['days'];
                } elseif (isset($range['day']) && is_string($range['day'])) {
                    $entry['day'] = $range['day'];
                }
            }
        }

        return $entry;
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
