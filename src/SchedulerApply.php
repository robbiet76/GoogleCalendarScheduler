<?php
declare(strict_types=1);

final class GcsSchedulerApply
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

        return [
            'ok'     => true,
            'dryRun' => false,
            'counts' => $previewCounts,
            'backup' => $backupPath,
        ];
    }

    /**
     * Build final apply plan.
     */
    private static function planApply(array $existing, array $desired): array
    {
        $desiredByKey       = [];
        $desiredKeysInOrder = [];

        foreach ($desired as $d) {
            if (!is_array($d)) {
                continue;
            }

            $k = GcsSchedulerIdentity::extractKey($d);
            if ($k === null) {
                continue;
            }

            if (!isset($desiredByKey[$k])) {
                $desiredKeysInOrder[] = $k;
            }

            // Normalize + HARD-ENFORCE tag preservation
            $norm = self::normalizeForApply($d);

            if (!isset($norm['args']) || !is_array($norm['args'])) {
                $norm['args'] = [];
            }

            $hasTag = false;
            foreach ($norm['args'] as $a) {
                if (is_string($a) && strpos($a, GcsSchedulerIdentity::TAG_MARKER) === 0) {
                    $hasTag = true;
                    break;
                }
            }

            if (!$hasTag) {
                $norm['args'][] = $k;
            }

            $desiredByKey[$k] = $norm;
        }

        $existingManagedByKey = [];
        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                continue;
            }

            $k = GcsSchedulerIdentity::extractKey($ex);
            if ($k === null) {
                continue;
            }

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

        // First pass: preserve all existing unmanaged entries and update existing managed entries in place
        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                continue;
            }

            $k = GcsSchedulerIdentity::extractKey($ex);

            if ($k === null) {
                // Unmanaged entry — preserve content (ordering will be tiered later)
                $newSchedule[] = $ex;
                continue;
            }

            if (!isset($desiredByKey[$k])) {
                // Managed but deleted
                continue;
            }

            $newSchedule[]     = $desiredByKey[$k];
            $writtenKeys[$k] = true;
        }

        // Second pass: append any newly-created desired entries (in desired order)
        foreach ($desiredKeysInOrder as $k) {
            if (!isset($writtenKeys[$k])) {
                $newSchedule[]     = $desiredByKey[$k];
                $writtenKeys[$k] = true;
            }
        }

        // Phase 24: enforce tiered priority ordering across the final schedule
        // Unmanaged entries must remain above managed entries (higher priority), preserving relative order.
        // Managed entries are split into immutable vs reorderable. Reorderable managed entries are sorted deterministically.
        $newSchedule = self::applyTieredOrderingPhase24($newSchedule);

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
     * Phase 24 tiered ordering:
     *  - Unmanaged entries first (relative order preserved)
     *  - Managed immutable next (unknown tag version or insufficient data; relative order preserved)
     *  - Managed reorderable last (valid v1 + key fields), sorted deterministically
     *
     * Enabled/disabled status is intentionally ignored for ordering.
     */
    private static function applyTieredOrderingPhase24(array $schedule): array
    {
        $log = static function (string $msg, array $ctx = []): void {
            GcsLogger::instance()->info($msg, $ctx);
        };

        $unmanaged        = [];
        $managedImmutable = [];
        $managedSortable  = [];

        $warnings = 0;
        $idx = 0;

        foreach ($schedule as $entry) {
            $idx++;
            if (!is_array($entry)) {
                // Shouldn't happen, but preserve safely as unmanaged-like.
                $unmanaged[] = $entry;
                continue;
            }

            $key = GcsSchedulerIdentity::extractKey($entry);
            if ($key === null) {
                $unmanaged[] = $entry;
                continue;
            }

            $version = self::extractTagVersion($key);
            if ($version !== 'v1') {
                // Unknown tag versions are treated as managed but immutable.
                $managedImmutable[] = $entry;
                continue;
            }

            $uid = self::extractUidFromKey($key);
            [$startTs, $endTs] = self::extractStartEndTsForSort($entry, $key);

            if ($uid === null || $startTs === null || $endTs === null) {
                $warnings++;
                $managedImmutable[] = $entry;
                continue;
            }

            $managedSortable[] = [
                'entry' => $entry,
                'start' => $startTs,
                'end'   => $endTs,
                'uid'   => $uid,
                'orig'  => count($managedSortable), // stability guard
            ];
        }

        usort($managedSortable, static function (array $a, array $b): int {
            // Later-starting (more specific) schedules first
            if ($a['start'] !== $b['start']) {
                return $b['start'] <=> $a['start'];   // DESC ✅
            }

            // Shorter window first when starts match
            if ($a['end'] !== $b['end']) {
                return $a['end'] <=> $b['end'];       // ASC ✅
            }

            if ($a['uid'] !== $b['uid']) {
                return strcmp($a['uid'], $b['uid']);
            }

            return $a['orig'] <=> $b['orig'];
        });

        $sortedManaged = array_map(static fn(array $x): array => $x['entry'], $managedSortable);

        $final = array_merge($unmanaged, $managedImmutable, $sortedManaged);

        if (count($final) !== count($schedule)) {
            // Safety: never drop/dup entries due to ordering.
            $log('[GCS][Phase24] ordering ABORT: entry count mismatch', [
                'oldCount' => count($schedule),
                'newCount' => count($final),
            ]);
            return $schedule;
        }

        $log('[GCS][Phase24] ordering applied', [
            'total'           => count($schedule),
            'unmanaged'       => count($unmanaged),
            'managedImmutable'=> count($managedImmutable),
            'managedSorted'   => count($sortedManaged),
            'warnings'        => $warnings,
        ]);

        return $final;
    }

    /**
     * Extract GCS tag version from a key string like: |GCS:v1|uid=...|range=...|
     */
    private static function extractTagVersion(string $key): ?string
    {
        if (preg_match('/^\|GCS:([^\|]+)\|/', $key, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Extract uid from a key string like: |GCS:v1|uid=...|range=...|
     */
    private static function extractUidFromKey(string $key): ?string
    {
        if (preg_match('/\|uid=([^\|]+)\|/', $key, $m)) {
            $uid = trim($m[1]);
            return $uid !== '' ? $uid : null;
        }
        return null;
    }

    /**
     * Derive start/end timestamps for deterministic sorting.
     *
     * Preferred source: entry fields (startDate/startTime and endDate/endTime) when present.
     * Fallback: tag range=YYYY-MM-DD..YYYY-MM-DD (day-bounded).
     *
     * We do NOT use enabled/disabled or stop strategy.
     */
    private static function extractStartEndTsForSort(array $entry, string $key): array
    {
        $startDate = isset($entry['startDate']) && is_string($entry['startDate']) ? $entry['startDate'] : null;
        $endDate   = isset($entry['endDate']) && is_string($entry['endDate']) ? $entry['endDate'] : null;

        $startTime = isset($entry['startTime']) && is_string($entry['startTime']) ? $entry['startTime'] : null;
        $endTime   = isset($entry['endTime']) && is_string($entry['endTime']) ? $entry['endTime'] : null;

        // If we have dates, use them with best-available times.
        if ($startDate !== null && $endDate !== null) {
            $st = self::safeParseDateTime($startDate, $startTime ?? '00:00:00');
            $en = self::safeParseDateTime($endDate, $endTime ?? '23:59:59');
            if ($st !== null && $en !== null) {
                return [$st, $en];
            }
        }

        // Fallback to tag range (day bounded)
        if (preg_match('/\|range=([0-9]{4}-[0-9]{2}-[0-9]{2})\.\.([0-9]{4}-[0-9]{2}-[0-9]{2})\|/', $key, $m)) {
            $st = self::safeParseDateTime($m[1], '00:00:00');
            $en = self::safeParseDateTime($m[2], '23:59:59');
            return [$st, $en];
        }

        return [null, null];
    }

    private static function safeParseDateTime(string $date, string $time): ?int
    {
        // Accept "HH:MM" or "HH:MM:SS"
        $t = $time;
        if (preg_match('/^\d{2}:\d{2}$/', $t)) {
            $t .= ':00';
        }
        $ts = strtotime($date . ' ' . $t);
        return ($ts === false) ? null : $ts;
    }

    /**
     * Normalize entry for FPP apply.
     */
    private static function normalizeForApply(array $entry): array
    {
        // FPP "day" is an enum (0..15), NOT a bitmask
        if (!isset($entry['day']) || !is_int($entry['day']) || $entry['day'] < 0 || $entry['day'] > 15) {
            $entry['day'] = 7; // Everyday
        }

        if (isset($entry['args']) && !is_array($entry['args'])) {
            $entry['args'] = [];
        }

        return $entry;
    }

    /**
     * Compare scheduler entries ignoring runtime noise.
     */
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
