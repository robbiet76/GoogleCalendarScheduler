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
 * - Managed entries are matched by canonical identity (manifest id) only
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

        $existing = (isset($plan['existingRaw']) && is_array($plan['existingRaw']))
            ? $plan['existingRaw']
            : [];

        $desired = (isset($plan['desiredEntries']) && is_array($plan['desiredEntries']))
            ? $plan['desiredEntries']
            : [];

        $applyPlan = $plan['applyPlan'] ?? null;
        if (!is_array($applyPlan)) {
            $applyPlan = self::planApply($existing, $desired);
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

        if (
            count($applyPlan['creates']) === 0 &&
            count($applyPlan['updates']) === 0 &&
            count($applyPlan['deletes']) === 0
        ) {
            return [
                'ok'     => true,
                'dryRun' => false,
                'counts' => ['creates' => 0, 'updates' => 0, 'deletes' => 0],
                'noop'   => true,
            ];
        }

        $backupPath = SchedulerSync::backupScheduleFileOrThrow(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        SchedulerSync::writeScheduleJsonAtomicallyOrThrow(
            SchedulerSync::SCHEDULE_JSON_PATH,
            $applyPlan['newSchedule']
        );

        SchedulerSync::verifyScheduleJsonKeysOrThrow(
            $applyPlan['expectedManagedKeys'],
            $applyPlan['expectedDeletedKeys']
        );

        // Commit manifest snapshot after successful apply (managed entries only)
        $store = new ManifestStore();
        $calendarMeta = [
            'icsUrl' => $cfg['settings']['ics_url'] ?? null,
        ];
        $store->commitCurrent(
            $calendarMeta,
            $applyPlan['manifestEntries'] ?? [],
            $applyPlan['manifestOrder'] ?? []
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
        // Desired managed entries indexed by canonical key (manifest id)
        $desiredByKey = [];
        $keysInOrder  = [];

        // Keep original desired entries keyed by id for manifest payload extraction
        $desiredOriginalByKey = [];

        foreach ($desired as $d) {
            if (!is_array($d)) {
                continue;
            }

            $key = self::extractManagedKey($d);
            if ($key === null) {
                // Desired entries should normally have a key; if not, skip rather than writing malformed data.
                continue;
            }

            if (!isset($desiredByKey[$key])) {
                $keysInOrder[] = $key;
            }

            $desiredByKey[$key] = self::normalizeForApply($d);
            $desiredOriginalByKey[$key] = $d;
        }

        // Existing managed entries indexed by canonical key (manifest id)
        $existingManagedByKey = [];
        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                continue;
            }

            $key = self::extractManagedKey($ex);
            if ($key === null) {
                continue;
            }

            $existingManagedByKey[$key] = $ex;
        }

        // Compute creates / updates / deletes (keyed by canonical identity)
        $creates = [];
        $updates = [];
        $deletes = [];

        foreach ($desiredByKey as $key => $d) {
            if (!isset($existingManagedByKey[$key])) {
                $creates[] = $key;
                continue;
            }

            if (!self::entriesEquivalentForCompare($existingManagedByKey[$key], $d)) {
                $updates[] = $key;
            }
        }

        foreach ($existingManagedByKey as $key => $_) {
            if (!isset($desiredByKey[$key])) {
                $deletes[] = $key;
            }
        }

        /*
         * Construct new schedule.json:
         * 1) Preserve unmanaged entries in original order
         * 2) Append managed entries in Planner order
         */
        $newSchedule = [];

        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                $newSchedule[] = $ex;
                continue;
            }

            // Unmanaged = no canonical managed key
            if (self::extractManagedKey($ex) === null) {
                $newSchedule[] = $ex;
            }
        }

        foreach ($keysInOrder as $key) {
            if (isset($desiredByKey[$key])) {
                $newSchedule[] = $desiredByKey[$key];
            }
        }

        // Build manifestEntries and manifestOrder per contract
        $manifestEntries = [];
        foreach ($keysInOrder as $key) {
            if (!isset($desiredOriginalByKey[$key])) {
                continue;
            }
            $dOriginal = $desiredOriginalByKey[$key];
            $m = $dOriginal['_manifest'] ?? [];

            $uid = isset($m['uid']) && is_string($m['uid']) ? $m['uid'] : '';
            $id = isset($m['id']) && is_string($m['id']) ? $m['id'] : '';
            $hash = isset($m['hash']) && is_string($m['hash']) ? $m['hash'] : '';
            $identity = $m['identity'] ?? [];
            $payload = $m['payload'] ?? [];

            // Defensive rebuild of identity if possible
            if (class_exists('ManifestIdentity') && method_exists('ManifestIdentity', 'fromScheduleEntry')) {
                $identity = ManifestIdentity::fromScheduleEntry($payload);
            }

            // Ensure manifest entry shape
            $manifestEntries[] = [
                'uid' => (string)$uid,
                'id' => (string)$id,
                'hash' => (string)$hash,
                'identity' => is_array($identity) ? $identity : [],
                'payload' => is_array($payload) ? $payload : [],
            ];
        }

        return [
            'creates'             => $creates,
            'updates'             => $updates,
            'deletes'             => $deletes,
            'newSchedule'         => $newSchedule,
            'expectedManagedKeys' => array_keys($desiredByKey),
            'expectedDeletedKeys' => $deletes,
            'manifestEntries'     => $manifestEntries,
            'manifestOrder'       => $keysInOrder,
        ];
    }

    /**
     * Extract the canonical "managed key" for an entry.
     *
     * Managed entries must have a manifest identity. Legacy FPP entries without
     * manifest identity are unmanaged by definition.
     */
    private static function extractManagedKey(array $entry): ?string
    {
        // Preferred: manifest id
        if (isset($entry['_manifest']) && is_array($entry['_manifest'])) {
            $id = $entry['_manifest']['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        // No fallback: without a manifest id, the entry is unmanaged by definition.
        return null;
    }

    private static function normalizeForApply(array $entry): array
    {
        // Strip ONLY known GCS-internal metadata keys before writing to schedule.json.
        // IMPORTANT: _manifest is canonical state and MUST NOT persist in schedule.json entries.
        // Do NOT remove arbitrary underscore-prefixed keys since FPP/other plugins may
        // legitimately use them.
        foreach (['_manifest', '_gcs', '_payload'] as $k) {
            if (array_key_exists($k, $entry)) {
                unset($entry[$k]);
            }
        }

        // Ensure FPP "day" enum sanity (0-15). Default to Everyday (7).
        if (!isset($entry['day']) || !is_int($entry['day']) || $entry['day'] < 0 || $entry['day'] > 15) {
            $entry['day'] = 7; // Everyday
        }

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