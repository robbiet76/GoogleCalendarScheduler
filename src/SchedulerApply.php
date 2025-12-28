<?php
declare(strict_types=1);

final class GcsSchedulerApply
{
    public static function applyFromConfig(array $cfg): array
    {
        GcsLogger::instance()->info('GCS APPLY ENTERED', [
            'dryRun' => !empty($cfg['runtime']['dry_run']),
        ]);

        $plan = SchedulerPlanner::plan($cfg);
        $dryRun = !empty($cfg['runtime']['dry_run']);

        $existing = (isset($plan['existingRaw']) && is_array($plan['existingRaw'])) ? $plan['existingRaw'] : [];
        $desired  = (isset($plan['desiredEntries']) && is_array($plan['desiredEntries'])) ? $plan['desiredEntries'] : [];

        $previewCounts = [
            'creates' => (isset($plan['creates']) && is_array($plan['creates'])) ? count($plan['creates']) : 0,
            'updates' => (isset($plan['updates']) && is_array($plan['updates'])) ? count($plan['updates']) : 0,
            'deletes' => (isset($plan['deletes']) && is_array($plan['deletes'])) ? count($plan['deletes']) : 0,
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

        $backupPath = SchedulerSync::backupScheduleFileOrThrow(SchedulerSync::SCHEDULE_JSON_PATH);
        SchedulerSync::writeScheduleJsonAtomicallyOrThrow(
            SchedulerSync::SCHEDULE_JSON_PATH,
            $applyPlan['newSchedule']
        );

        SchedulerSync::verifyScheduleJsonKeysOrThrow(
            $applyPlan['expectedManagedKeys'],
            $applyPlan['expectedDeletedKeys']
        );

        return [
            'ok'     => true,
            'dryRun' => false,
            'counts' => $previewCounts,
            'backup' => $backupPath,
        ];
    }

    private static function planApply(array $existing, array $desired): array
    {
        $desiredByKey = [];
        $desiredKeysInOrder = [];

        foreach ($desired as $d) {
            if (!is_array($d)) continue;

            $k = GcsSchedulerIdentity::extractKey($d);
            if ($k === null) continue;

            if (!isset($desiredByKey[$k])) {
                $desiredKeysInOrder[] = $k;
            }

            // 🔒 Normalize desired entry BEFORE apply
            $desiredByKey[$k] = self::normalizeForApply($d);
        }

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

        foreach ($existingManagedByKey as $k => $_) {
            if (!isset($desiredByKey[$k])) {
                $deletesKeys[] = $k;
            }
        }

        $newSchedule = [];
        $writtenKeys = [];

        foreach ($existing as $ex) {
            if (!is_array($ex)) continue;

            $k = GcsSchedulerIdentity::extractKey($ex);

            if ($k === null) {
                $newSchedule[] = $ex;
                continue;
            }

            if (!isset($desiredByKey[$k])) {
                continue;
            }

            $newSchedule[] = $desiredByKey[$k];
            $writtenKeys[$k] = true;
        }

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

    private static function normalizeForApply(array $entry): array
    {
        // 🔥 FPP REQUIREMENT: day must ALWAYS be present and valid
        if (!isset($entry['day']) || !is_int($entry['day']) || $entry['day'] <= 0) {
            $entry['day'] = 127; // Everyday
        }

        return $entry;
    }

    private static function entriesEquivalentForCompare(array $a, array $b): bool
    {
        unset($a['id'], $a['lastRun'], $b['id']);
        ksort($a);
        ksort($b);
        return $a === $b;
    }
}
