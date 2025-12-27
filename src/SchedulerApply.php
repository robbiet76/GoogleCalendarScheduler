<?php
declare(strict_types=1);

final class GcsSchedulerApply
{
    /**
     * Apply the current plan.
     *
     * dryRun is ONLY respected here (apply boundary).
     *
     * @param array $cfg
     * @return array<string,mixed>
     */
    public static function applyFromConfig(array $cfg): array
    {
        $plan = SchedulerPlanner::plan($cfg);

        $dryRun = !empty($cfg['runtime']['dry_run']);

        // Extract authoritative plan inputs
        $existing = (isset($plan['existingRaw']) && is_array($plan['existingRaw'])) ? $plan['existingRaw'] : [];
        $desired  = (isset($plan['desiredEntries']) && is_array($plan['desiredEntries'])) ? $plan['desiredEntries'] : [];

        // Planner diff counts (preview-oriented)
        $previewCounts = [
            'creates' => (isset($plan['creates']) && is_array($plan['creates'])) ? count($plan['creates']) : 0,
            'updates' => (isset($plan['updates']) && is_array($plan['updates'])) ? count($plan['updates']) : 0,
            'deletes' => (isset($plan['deletes']) && is_array($plan['deletes'])) ? count($plan['deletes']) : 0,
        ];

        // If dry-run, do NOT write; just return counts and plan state (useful for debugging)
        if ($dryRun) {
            return [
                'ok'            => true,
                'dryRun'        => true,
                'counts'        => $previewCounts,
                'creates'       => $plan['creates'] ?? [],
                'updates'       => $plan['updates'] ?? [],
                'deletes'       => $plan['deletes'] ?? [],
                'desiredEntries'=> $desired,
                'existingRaw'   => $existing,
            ];
        }

        // Live apply: build new schedule.json from existingRaw + desiredEntries (single truth)
        $applyPlan = self::planApply($existing, $desired);

        // If no changes, return noop
        if (count($applyPlan['creates']) === 0 && count($applyPlan['updates']) === 0 && count($applyPlan['deletes']) === 0) {
            return [
                'ok'     => true,
                'dryRun' => false,
                'counts' => ['creates' => 0, 'updates' => 0, 'deletes' => 0],
                'noop'   => true,
            ];
        }

        GcsLogger::instance()->info('GCS APPLY DEBUG', [
            'existingRawCount' => count($existing),
            'desiredCount'     => count($desired),
            'creates'          => $applyPlan['creates'],
            'updates'          => $applyPlan['updates'],
            'deletes'          => $applyPlan['deletes'],
            'newScheduleCount' => count($applyPlan['newSchedule']),
        ]);


        // Backup + atomic write
        $backupPath = SchedulerSync::backupScheduleFileOrThrow(SchedulerSync::SCHEDULE_JSON_PATH);
        SchedulerSync::writeScheduleJsonAtomicallyOrThrow(SchedulerSync::SCHEDULE_JSON_PATH, $applyPlan['newSchedule']);

        // Verify schedule.json keys match expected result
        SchedulerSync::verifyScheduleJsonKeysOrThrow($applyPlan['expectedManagedKeys'], $applyPlan['expectedDeletedKeys']);

        return [
            'ok'     => true,
            'dryRun' => false,
            'counts' => [
                'creates' => count($applyPlan['creates']),
                'updates' => count($applyPlan['updates']),
                'deletes' => count($applyPlan['deletes']),
            ],
            'backup' => $backupPath,
        ];
    }

    /**
     * Plan apply: compute create/update/delete and build next schedule.json.
     *
     * OWNERSHIP:
     * - Unmanaged entries (no key) are preserved exactly.
     * - Managed entries are replaced by desired if present, else removed.
     *
     * @param array<int,array<string,mixed>> $existing
     * @param array<int,array<string,mixed>> $desired
     * @return array<string,mixed>
     */
    private static function planApply(array $existing, array $desired): array
    {
        // Desired entries by key (keep insertion order for appends)
        $desiredByKey = [];
        $desiredKeysInOrder = [];

        foreach ($desired as $d) {
            if (!is_array($d)) continue;

            $k = GcsSchedulerIdentity::extractKey($d);
            if ($k === null) continue;

            if (!isset($desiredByKey[$k])) {
                $desiredKeysInOrder[] = $k;
            }

            // Last writer wins for same UID (deterministic)
            $desiredByKey[$k] = $d;
        }

        // Existing managed entries by key
        $existingManagedByKey = [];
        foreach ($existing as $ex) {
            if (!is_array($ex)) continue;

            $k = GcsSchedulerIdentity::extractKey($ex);
            if ($k === null) continue;

            $existingManagedByKey[$k] = $ex;
        }

        $createsKeys = [];
        $updatesKeys = [];
        $deletesKeys = [];

        foreach ($desiredByKey as $k => $d) {
            if (!isset($existingManagedByKey[$k])) {
                $createsKeys[] = $k;
                continue;
            }

            if (!self::entriesEquivalentForCompare($existingManagedByKey[$k], $d)) {
                $updatesKeys[] = $k;
            }
        }

        foreach ($existingManagedByKey as $k => $_ex) {
            if (!isset($desiredByKey[$k])) {
                $deletesKeys[] = $k;
            }
        }

        // Build new schedule content
        $newSchedule = [];
        $writtenKeys = [];

        foreach ($existing as $ex) {
            if (!is_array($ex)) continue;

            $k = GcsSchedulerIdentity::extractKey($ex);

            if ($k === null) {
                // Unmanaged: keep as-is
                $newSchedule[] = $ex;
                continue;
            }

            if (!isset($desiredByKey[$k])) {
                // Managed and no longer desired: delete
                continue;
            }

            // Managed and desired: replace with desired (update) or same (noop)
            $newSchedule[] = $desiredByKey[$k];
            $writtenKeys[$k] = true;
        }

        // Append new creates (desired keys not already written)
        foreach ($desiredKeysInOrder as $k) {
            if (!isset($writtenKeys[$k])) {
                $newSchedule[] = $desiredByKey[$k];
                $writtenKeys[$k] = true;
            }
        }

        return [
            'creates'             => $createsKeys,
            'updates'             => $updatesKeys,
            'deletes'             => $deletesKeys,
            'newSchedule'         => $newSchedule,
            'expectedManagedKeys' => array_keys($desiredByKey),
            'expectedDeletedKeys' => $deletesKeys,
        ];
    }

    /**
     * Compare entries ignoring non-semantic fields.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private static function entriesEquivalentForCompare(array $a, array $b): bool
    {
        unset($a['id'], $a['lastRun'], $b['id']);
        return self::normalizeEntryForCompare($a) === self::normalizeEntryForCompare($b);
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function normalizeEntryForCompare(array $entry): array
    {
        ksort($entry);
        return $entry;
    }
}
