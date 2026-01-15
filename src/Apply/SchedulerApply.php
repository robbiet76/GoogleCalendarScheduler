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
 * - Receive a canonical applyPlan from Planner
 * - Enforce dry-run/safety policies
 * - Sanitize and write schedule.json atomically
 * - Commit manifest snapshot after successful write
 *
 * HARD GUARANTEES (Manifest architecture):
 * - Unmanaged entries are never modified
 * - Managed entries are owned and matched by canonical UID (VEVENT UID) and tracked in the manifest by id/hash/identity.
 * - schedule.json is never partially written
 * - Apply is idempotent for the same planner output
 * - Apply never reads schedule.json; it is write target only.
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

        $applyPlan = $plan['applyPlan'] ?? null;
        if (!is_array($applyPlan)) {
            return [
                'ok'    => false,
                'error' => 'Planner did not provide applyPlan.',
            ];
        }

        if (!isset($applyPlan['newSchedule']) || !is_array($applyPlan['newSchedule'])) {
            return [
                'ok'    => false,
                'error' => 'Apply plan missing newSchedule.',
            ];
        }

        if (!isset($applyPlan['manifestEntries']) || !is_array($applyPlan['manifestEntries'])) {
            $applyPlan['manifestEntries'] = [];
        }

        if (!isset($applyPlan['manifestOrder']) || !is_array($applyPlan['manifestOrder'])) {
            $applyPlan['manifestOrder'] = [];
        }

        if (isset($applyPlan['counts']) && is_array($applyPlan['counts'])) {
            $previewCounts = [
                'creates' => $applyPlan['counts']['creates'] ?? 0,
                'updates' => $applyPlan['counts']['updates'] ?? 0,
                'deletes' => $applyPlan['counts']['deletes'] ?? 0,
            ];
        } else {
            $previewCounts = [
                'creates' => isset($applyPlan['creates']) && is_array($applyPlan['creates']) ? count($applyPlan['creates']) : 0,
                'updates' => isset($applyPlan['updates']) && is_array($applyPlan['updates']) ? count($applyPlan['updates']) : 0,
                'deletes' => isset($applyPlan['deletes']) && is_array($applyPlan['deletes']) ? count($applyPlan['deletes']) : 0,
            ];
        }

        if ($dryRun) {
            $result = new ManifestResult(
                $applyPlan['creates'] ?? [],
                $applyPlan['updates'] ?? [],
                $applyPlan['deletes'] ?? [],
                $plan['messages'] ?? []
            );

            return PreviewFormatter::format($result);
        }

        $createsCount = $previewCounts['creates'];
        $updatesCount = $previewCounts['updates'];
        $deletesCount = $previewCounts['deletes'];

        if ($createsCount === 0 && $updatesCount === 0 && $deletesCount === 0) {
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

        // Lightweight JSON encode sanity check (no disk read)
        $encoded = json_encode($finalSchedule);
        if ($encoded === false) {
            return ['ok' => false, 'error' => 'Final schedule JSON encode failed'];
        }

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

    public static function undoLastApply(): array
    {
        // TODO: Wire to endpoint. This will rollback manifest and restore schedule.json from backup.
        return ['ok' => false, 'error' => 'Undo not implemented yet'];
    }
}
