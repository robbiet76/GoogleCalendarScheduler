<?php
declare(strict_types=1);

/**
 * SchedulerCleanupApplier (Phase 23.4)
 *
 * GUARDED WRITE PATH.
 *
 * Removes unmanaged scheduler entries ONLY when a
 * managed GCS-controlled equivalent is present.
 *
 * This is a DESTRUCTIVE operation with strict safeguards.
 *
 * HARD RULES:
 * - Always compute a fresh cleanup plan at apply time
 * - Always back up schedule.json before writing
 * - NEVER delete managed (GCS-owned) entries
 * - NEVER rely on stale planner indices
 * - Always verify managed identity set remains unchanged
 *
 * FAILURE POLICY:
 * - Any safety violation aborts the operation
 * - schedule.json must remain intact on failure
 */

final class SchedulerCleanupApplier
{
    /**
     * @return array{
     *   ok: bool,
     *   backup: string|null,
     *   removed: int,
     *   blocked: int,
     *   total: int,
     *   managed: int,
     *   unmanaged: int,
     *   error: string|null
     * }
     */
    public static function apply(): array
    {
        try {
            // Plan fresh at apply time to avoid stale indices or race conditions
            $plan = SchedulerCleanupPlanner::plan();
            if (empty($plan['ok'])) {
                return [
                    'ok' => false,
                    'backup' => null,
                    'removed' => 0,
                    'blocked' => 0,
                    'total' => 0,
                    'managed' => 0,
                    'unmanaged' => 0,
                    'error' => implode('; ', $plan['errors'] ?? ['Unknown planner failure']),
                ];
            }

            $counts = $plan['counts'];
            $candidateIdx = [];
            foreach (($plan['candidates'] ?? []) as $c) {
                if (isset($c['index']) && is_int($c['index'])) {
                    $candidateIdx[$c['index']] = true;
                }
            }

            // Nothing to do
            if (empty($candidateIdx)) {
                return [
                    'ok' => true,
                    'backup' => null,
                    'removed' => 0,
                    'blocked' => (int)($counts['blocked'] ?? 0),
                    'total' => (int)($counts['total'] ?? 0),
                    'managed' => (int)($counts['managed'] ?? 0),
                    'unmanaged' => (int)($counts['unmanaged'] ?? 0),
                    'error' => null,
                ];
            }

            // Read strict current schedule.json
            $path = SchedulerSync::SCHEDULE_JSON_PATH;
            $before = SchedulerSync::readScheduleJsonOrThrow($path);

            // Capture managed keys before
            $managedKeysBefore = self::managedKeySet($before);

            // Backup
            $backup = SchedulerSync::backupScheduleFileOrThrow($path);

            // Build new schedule without candidate indices (but re-check safety)
            $after = [];
            $removed = 0;

            foreach ($before as $idx => $entry) {
                if (!is_array($entry)) {
                    // keep non-array entries as-is (shouldn't happen, but safest)
                    $after[] = $entry;
                    continue;
                }

                // Never remove managed
                if (GcsSchedulerIdentity::isGcsManaged($entry)) {
                    $after[] = $entry;
                    continue;
                }

                if (!empty($candidateIdx[$idx])) {
                    // SAFETY RE-CHECK:
                    // Even if the planner marked this index removable,
                    // we recompute the fingerprint and verify a managed
                    // equivalent STILL exists in the current snapshot.

                    $fp = SchedulerCleanupPlanner::fingerprint($entry);
                    if ($fp !== '' && self::managedFingerprintExists($before, $fp)) {
                        $removed++;
                        continue;
                    }

                    // If re-check fails, do NOT remove
                    $after[] = $entry;
                    continue;
                }

                $after[] = $entry;
            }

            // Write atomically
            SchedulerSync::writeScheduleJsonAtomicallyOrThrow($path, $after);

            // HARD INVARIANT:
            // Cleanup must NEVER remove or alter managed scheduler entries.
            $managedKeysAfter = self::managedKeySet(SchedulerSync::readScheduleJsonStatic($path));
            if ($managedKeysAfter !== $managedKeysBefore) {
                throw new RuntimeException('Post-write verification failed: managed key set changed during cleanup.');
            }

            return [
                'ok' => true,
                'backup' => $backup,
                'removed' => $removed,
                'blocked' => (int)($counts['blocked'] ?? 0),
                'total' => (int)($counts['total'] ?? 0),
                'managed' => (int)($counts['managed'] ?? 0),
                'unmanaged' => (int)($counts['unmanaged'] ?? 0),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'backup' => null,
                'removed' => 0,
                'blocked' => 0,
                'total' => 0,
                'managed' => 0,
                'unmanaged' => 0,
                'error' => 'Cleanup apply failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Build a sorted set of managed identity keys (full tag string).
     *
     * @param array<int,mixed> $entries
     * @return array<int,string>
     */
    private static function managedKeySet(array $entries): array
    {
        $set = [];
        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            $k = GcsSchedulerIdentity::extractKey($e);
            if ($k !== null) $set[$k] = true;
        }
        $keys = array_keys($set);
        sort($keys);
        return $keys;
    }

    /**
     * Check if managed fingerprint exists in the current schedule snapshot.
     *
     * @param array<int,mixed> $entries
     */
    private static function managedFingerprintExists(array $entries, string $fp): bool
    {
        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            if (!GcsSchedulerIdentity::isGcsManaged($e)) continue;
            if (SchedulerCleanupPlanner::fingerprint($e) === $fp) return true;
        }
        return false;
    }
}
