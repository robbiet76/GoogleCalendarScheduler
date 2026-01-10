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
 * HARD GUARANTEES (Phase 29+):
 * - Unmanaged entries are never modified
 * - Managed entries are matched by UID ONLY
 * - Exactly one canonical managed tag per entry
 * - schedule.json is never partially written
 * - Apply is idempotent for the same planner output
 *
 * Phase 28/29:
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

        $previewCounts = [
            'creates' => isset($plan['creates']) && is_array($plan['creates']) ? count($plan['creates']) : 0,
            'updates' => isset($plan['updates']) && is_array($plan['updates']) ? count($plan['updates']) : 0,
            'deletes' => isset($plan['deletes']) && is_array($plan['deletes']) ? count($plan['deletes']) : 0,
        ];

        if ($dryRun) {
            return [
                'ok'             => true,
                'dryRun'         => true,
                'counts'         => $previewCounts,
                'creates'        => $plan['creates'] ?? [],
                'updates'        => $plan['updates'] ?? [],
                'deletes'        => $plan['deletes'] ?? [],
                'desiredEntries' => $desired,
                'existingRaw'    => $existing,
                'desiredBundles' => $plan['desiredBundles'] ?? [],
            ];
        }

        $applyPlan = self::planApply($existing, $desired);

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

        // Commit manifest snapshot after successful apply
        $manifest = $plan['manifest'] ?? null;
        if (is_array($manifest)) {
            $store = new ManifestStore();
            $store->commitCurrent(
                $manifest['calendarMeta'] ?? [],
                $manifest['entries'] ?? [],
                $manifest['order'] ?? []
            );
        }

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
     * Identity model (Phase 29+):
     * - UID-only
     *
     * @param array<int,array<string,mixed>> $existing
     * @param array<int,array<string,mixed>> $desired
     * @return array<string,mixed>
     */
    private static function planApply(array $existing, array $desired): array
    {
        // Desired managed entries indexed by UID
        $desiredByUid   = [];
        $uidsInOrder    = [];

        foreach ($desired as $d) {
            if (!is_array($d)) {
                continue;
            }

            $uid = SchedulerIdentity::extractKey($d);
            if ($uid === null) {
                continue;
            }

            if (!isset($desiredByUid[$uid])) {
                $uidsInOrder[] = $uid;
            }

            $desiredByUid[$uid] = self::normalizeForApply($d);
        }

        // Existing managed entries indexed by UID
        $existingManagedByUid = [];
        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                continue;
            }

            $uid = SchedulerIdentity::extractKey($ex);
            if ($uid === null) {
                continue;
            }

            $existingManagedByUid[$uid] = $ex;
        }

        // Compute creates / updates / deletes
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
         * 1) Preserve unmanaged entries in original order
         * 2) Append managed entries in Planner order
         */
        $newSchedule = [];

        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                $newSchedule[] = $ex;
                continue;
            }

            if (SchedulerIdentity::extractKey($ex) === null) {
                $newSchedule[] = $ex;
            }
        }

        foreach ($uidsInOrder as $uid) {
            if (isset($desiredByUid[$uid])) {
                $newSchedule[] = $desiredByUid[$uid];
            }
        }

        return [
            'creates'             => $creates,
            'updates'             => $updates,
            'deletes'             => $deletes,
            'newSchedule'         => $newSchedule,
            'expectedManagedKeys' => array_keys($desiredByUid),
            'expectedDeletedKeys' => $deletes,
        ];
    }

    private static function normalizeForApply(array $entry): array
    {
        // Ensure FPP "day" enum sanity
        if (!isset($entry['day']) || !is_int($entry['day']) || $entry['day'] < 0 || $entry['day'] > 15) {
            $entry['day'] = 7; // Everyday
        }

        if (!isset($entry['args']) || !is_array($entry['args'])) {
            $entry['args'] = [];
        }

        return $entry;
    }

    private static function entriesEquivalentForCompare(array $a, array $b): bool
    {
        unset(
            $a['id'],
            $a['lastRun'],
            $b['id'],
            $b['lastRun']
        );

        ksort($a);
        ksort($b);

        return $a === $b;
    }
}
