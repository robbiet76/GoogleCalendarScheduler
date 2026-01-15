<?php
declare(strict_types=1);

/**
 * SchedulerApply
 *
 * APPLY BOUNDARY
 *
 * This class is the ONLY component permitted to mutate FPP's schedule.json.
 *
 * CORE RESPONSIBILITIES:
 * - Re-run the planner to obtain a canonical diff
 * - Enforce dry-run and safety policies
 * - Merge desired managed entries with existing unmanaged entries
 * - Write schedule.json atomically
 * - Verify post-write integrity
 *
 * HARD GUARANTEES (Manifest architecture):
 * - Unmanaged entries are never modified
 * - Managed entries are owned and matched by canonical UID (VEVENT UID) and tracked in the manifest by id/hash/identity.
 * - schedule.json is never partially written
 * - Apply is idempotent for the same planner output
 *
 * Ordering:
 * - Apply preserves Planner ordering exactly
 * - No implicit re-sorting
 */
final class SchedulerApply
{
    public static function applyFromConfig(array $cfg): array
    {
        GcsLogger::instance()->info('GCS APPLY ENTERED', [
            'dryRun' => !empty($cfg['runtime']['dry_run']),
        ]);

        $plan   = SchedulerPlanner::plan($cfg);
        $dryRun = !empty($cfg['runtime']['dry_run']);

        // Planner output normalization (canonical)
        $existing = (isset($plan['existingRaw']) && is_array($plan['existingRaw']))
            ? $plan['existingRaw']
            : [];

        // Desired entries may arrive either as `desiredEntries` (schedule-entry arrays)
        // or as `desiredBundles` (domain bundles containing `base.payload`).
        $desired = [];
        if (isset($plan['desiredEntries']) && is_array($plan['desiredEntries'])) {
            $desired = $plan['desiredEntries'];
        } elseif (isset($plan['desiredBundles']) && is_array($plan['desiredBundles'])) {
            foreach ($plan['desiredBundles'] as $b) {
                if (!is_array($b)) {
                    continue;
                }
                $base = $b['base'] ?? null;
                if (!is_array($base)) {
                    continue;
                }
                $payload = $base['payload'] ?? null;
                if (is_array($payload) && !empty($payload)) {
                    $desired[] = $payload;
                }
            }
        }

        $applyPlan = $plan['applyPlan'] ?? null;
        if (!is_array($applyPlan)) {
            // Apply relies on the Planner to provide manifestEntries/manifestOrder.
            // If Planner did not provide an applyPlan, we can still build a schedule merge plan,
            // but we cannot fabricate manifest identity here.
            $applyPlan = self::planApply($existing, $desired);
        }

        // If Planner did not supply manifest data, derive it ONLY from Planner preview rows
        // (which include _manifest) rather than from schedule payload.
        if ((!isset($applyPlan['manifestEntries']) || !is_array($applyPlan['manifestEntries']))
            && isset($plan['preview']) && is_array($plan['preview'])
            && isset($plan['preview']['rows']) && is_array($plan['preview']['rows'])) {

            $manifestEntries = [];
            $manifestOrder   = [];

            foreach ($plan['preview']['rows'] as $row) {
                if (!is_array($row)) continue;
                $m = $row['_manifest'] ?? null;
                if (!is_array($m)) continue;

                $uid = $m['uid'] ?? null;
                $id  = $m['id'] ?? null;
                $hash = $m['hash'] ?? null;
                $identity = $m['identity'] ?? null;
                $payload  = $m['payload'] ?? null;

                if (!is_string($uid) || $uid == '') continue;
                if (!is_string($id) || $id == '') continue;
                if (!is_string($hash) || $hash == '') continue;
                if (!is_array($identity)) continue;
                if (!is_array($payload)) continue;

                // Ensure payload uid matches
                $payload['uid'] = $uid;

                $manifestEntries[] = [
                    'uid'      => $uid,
                    'id'       => $id,
                    'hash'     => $hash,
                    'identity' => $identity,
                    'payload'  => $payload,
                ];
                $manifestOrder[] = $id;
            }

            $applyPlan['manifestEntries'] = $manifestEntries;
            $applyPlan['manifestOrder']   = $manifestOrder;
        }

        $previewCounts = [
            'creates' => isset($applyPlan['creates']) && is_array($applyPlan['creates']) ? count($applyPlan['creates']) : 0,
            'updates' => isset($applyPlan['updates']) && is_array($applyPlan['updates']) ? count($applyPlan['updates']) : 0,
            'deletes' => isset($applyPlan['deletes']) && is_array($applyPlan['deletes']) ? count($applyPlan['deletes']) : 0,
        ];

        if ($dryRun) {
            $result = new ManifestResult(
                $applyPlan['creates'] ?? [],
                $applyPlan['updates'] ?? [],
                $applyPlan['deletes'] ?? [],
                $plan['messages'] ?? []
            );

            return PreviewFormatter::format($result);
        }

        $createsCount = isset($applyPlan['creates']) && is_array($applyPlan['creates']) ? count($applyPlan['creates']) : 0;
        $updatesCount = isset($applyPlan['updates']) && is_array($applyPlan['updates']) ? count($applyPlan['updates']) : 0;
        $deletesCount = isset($applyPlan['deletes']) && is_array($applyPlan['deletes']) ? count($applyPlan['deletes']) : 0;

        if ($createsCount === 0 && $updatesCount === 0 && $deletesCount === 0) {
            return [
                'ok'     => true,
                'dryRun' => false,
                'counts' => ['creates' => 0, 'updates' => 0, 'deletes' => 0],
                'noop'   => true,
            ];
        }

        if (!isset($applyPlan['newSchedule']) || !is_array($applyPlan['newSchedule'])) {
            return [
                'ok'    => false,
                'error' => 'Apply plan missing newSchedule.',
            ];
        }

        $backupPath = SchedulerSync::backupScheduleFileOrThrow(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $finalSchedule = FPPSemantics::sanitizeScheduleForDisk($applyPlan['newSchedule']);

        // HARD RULE: schedule.json on disk must be FPP-native.
        // Never write uid/_manifest (or any plugin keys) into schedule.json.
        foreach ($finalSchedule as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists('uid', $row) || array_key_exists('_manifest', $row)) {
                return ['ok' => false, 'error' => 'Refusing to write non-FPP keys to schedule.json (uid/_manifest detected)'];
            }
        }

        SchedulerSync::writeScheduleJsonAtomicallyOrThrow(
            SchedulerSync::SCHEDULE_JSON_PATH,
            $finalSchedule
        );

        $manifestEntriesForVerify = (isset($applyPlan['manifestEntries']) && is_array($applyPlan['manifestEntries']))
            ? $applyPlan['manifestEntries']
            : [];

        $writtenSchedule = SchedulerSync::readScheduleJsonOrThrow(
            SchedulerSync::SCHEDULE_JSON_PATH
        );
        $writtenSchedule = FPPSemantics::sanitizeScheduleForDisk($writtenSchedule);

        SchedulerSync::verifyScheduleJsonMatchesManifestOrThrow(
            $manifestEntriesForVerify,
            $writtenSchedule
        );

        // Commit manifest snapshot after successful apply (managed entries only)
        $store = new ManifestStore();
        $calendarMeta = [
            'icsUrl' => $cfg['settings']['ics_url'] ?? null,
        ];
        $store->commitCurrent(
            $calendarMeta,
            $applyPlan['manifestEntries'] ?? [],
            (isset($applyPlan['manifestOrder']) && is_array($applyPlan['manifestOrder'])) ? $applyPlan['manifestOrder'] : []
        );

        return [
            'ok'     => true,
            'dryRun' => false,
            'counts' => $previewCounts,
            'backup' => $backupPath,
        ];
    }

    /**
     * Build apply plan:
     * - Unmanaged entries preserved in original order
     * - Managed entries rewritten in Planner-provided order
     *
     * Identity model:
     * - Canonical manifest identity (manifest id) only.
     * - Legacy/hand-written FPP entries without manifest identity are treated as unmanaged.
     *
     * @param array<int,array<string,mixed>> $existing
     * @param array<int,array<string,mixed>> $desired
     * @return array<string,mixed>
     */
    private static function planApply(array $existing, array $desired): array
    {
        // Desired managed entries indexed by canonical UID (VEVENT UID)
        $desiredByUid = [];
        $uidsInOrder  = [];

        // Keep original desired entries keyed by UID for manifest payload extraction
        $desiredOriginalByUid = [];

        foreach ($desired as $d) {
            if (!is_array($d)) {
                continue;
            }

            $uid = self::extractManagedUid($d);
            if ($uid === null) {
                // Desired entries should normally have a UID; if not, skip rather than writing malformed data.
                continue;
            }

            if (!isset($desiredByUid[$uid])) {
                $uidsInOrder[] = $uid;
            }

            $desiredByUid[$uid] = self::normalizeForApply($d);
            $desiredOriginalByUid[$uid] = $d;
        }

        // Existing managed entries indexed by canonical UID (VEVENT UID)
        $existingManagedByUid = [];
        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                continue;
            }

            $uid = self::extractManagedUid($ex);
            if ($uid === null) {
                continue;
            }

            $existingManagedByUid[$uid] = $ex;
        }

        // Compute creates / updates / deletes (keyed by canonical UID)
        $creates = [];
        $updates = [];
        $deletes = [];

        foreach ($desiredByUid as $uid => $d) {
            if (!isset($existingManagedByUid[$uid])) {
                $creates[] = $uid;
                continue;
            }

            if (!self::entriesEquivalentForCompare($existingManagedByUid[$uid], $d)) {
                $updates[] = $uid;
            }
        }

        foreach ($existingManagedByUid as $uid => $_) {
            if (!isset($desiredByUid[$uid])) {
                $deletes[] = $uid;
            }
        }

        /*
         * Construct new schedule.json:
         * 1) Preserve UNMANAGED entries in original order
         * 2) Rebuild MANAGED entries from Planner order (replacing any previously-managed entries)
         *
         * This guarantees idempotence and prevents duplication.
         */
        $newSchedule = [];

        // Preserve only unmanaged entries (anything without a manifest uid)
        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                // Defensive: keep non-array rows as-is
                $newSchedule[] = $ex;
                continue;
            }

            if (self::extractManagedUid($ex) === null) {
                $newSchedule[] = $ex;
            }
        }

        // Append managed entries in canonical Planner order
        foreach ($uidsInOrder as $uid) {
            if (!isset($desiredByUid[$uid])) {
                continue;
            }
            $newSchedule[] = $desiredByUid[$uid];
        }

        // NOTE: Apply never fabricates manifest identity. Planner must supply it.

        return [
            'creates'             => $creates,
            'updates'             => $updates,
            'deletes'             => $deletes,
            'newSchedule'         => $newSchedule,
            'expectedManagedUids' => array_keys($desiredByUid),
            'expectedDeletedUids' => $deletes,
            // NOTE: Apply never fabricates manifest identity. Planner must supply it.
            'manifestEntries'     => [],
            'manifestOrder'       => [],
        ];
    }

    /**
     * Extract the canonical "managed UID" for an entry.
     *
     * Managed entries must have a managed UID in the entry (`uid` in the gcs- namespace).
     * schedule.json never stores manifest data; manifest is stored separately.
     * Legacy FPP entries without a managed UID are unmanaged by definition.
     */
    private static function extractManagedUid(array $entry): ?string
    {
        // Fallback: entry uid prefixed with 'gcs-' (managed namespace)
        if (isset($entry['uid']) && is_string($entry['uid']) && $entry['uid'] !== '' && str_starts_with($entry['uid'], 'gcs-')) {
            return $entry['uid'];
        }

        // No fallback: without a managed uid, the entry is unmanaged by definition.
        return null;
    }

    private static function normalizeForApply(array $entry): array
    {
        // Strip only GCS-internal metadata keys before writing to schedule.json.
        // IMPORTANT: schedule.json must remain valid FPP schema; manifest data is never stored here.
        // Do NOT remove arbitrary underscore-prefixed keys since FPP/other plugins may legitimately use them.
        foreach (['_manifest', '_gcs', '_payload'] as $k) {
            if (array_key_exists($k, $entry)) {
                unset($entry[$k]);
            }
        }

        // Ensure FPP "day" enum sanity (0-15). Default to Everyday (7).
        if (!isset($entry['day']) || !is_int($entry['day']) || $entry['day'] < 0 || $entry['day'] > 15) {
            $entry['day'] = 7; // Everyday
        }

        // Ensure uid exists if present in the original entry: If isset($entry['uid']) do nothing; otherwise leave as-is.

        return $entry;
    }

    /**
     * Equivalence check for apply decisions.
     *
     * Compares normalized schedule-entry arrays, not domain objects.
     *
     * Delegates to SchedulerComparator when available (canonical field list),
     * otherwise falls back to stable structural compare.
     */
    private static function entriesEquivalentForCompare(array $a, array $b): bool
    {
        if (class_exists('SchedulerComparator') && method_exists('SchedulerComparator', 'isEquivalent')) {
            return SchedulerComparator::isEquivalent($a, $b);
        }

        // Fallback: ignore runtime-only fields and compare deterministically
        unset($a['id'], $a['lastRun'], $b['id'], $b['lastRun']);
        ksort($a);
        ksort($b);
        return $a === $b;
    }

    public static function undoLastApply(): array
    {
        // TODO: Wire to endpoint. This will rollback manifest and restore schedule.json from backup.
        return ['ok' => false, 'error' => 'Undo not implemented yet'];
    }
}
