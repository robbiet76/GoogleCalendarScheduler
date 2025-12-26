<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 *
 * Phase 16 structural refactor:
 * - Preview MUST NOT call SchedulerSync (even in dry-run)
 * - Preview computes diffs by:
 *   1) planning intents (plan-only)
 *   2) mapping intents -> would-be schedule.json entries (pure mapping)
 *   3) reading schedule.json
 *   4) computing creates/updates/deletes (currently: creates-only; updates/deletes scaffold)
 *
 * Apply remains guarded and is the ONLY execution path that writes.
 */
final class DiffPreviewer
{
    /**
     * Compute a diff preview using the plan-only pipeline.
     *
     * Return shape:
     *   ['creates'=>array, 'updates'=>array, 'deletes'=>array]
     */
    public static function preview(array $config): array
    {
        $horizonDays = GcsFppSchedulerHorizon::getDays();

        // PLAN ONLY: no writes, no SchedulerSync construction
        $runner = new GcsSchedulerRunner($config, $horizonDays, true);
        $intents = $runner->plan();

        // Map intents -> entries (pure)
        $map = SchedulerSync::mapIntentsToScheduleEntries($intents);
        $plannedEntries = $map['entries'] ?? [];
        $errors = $map['errors'] ?? [];

        if (!empty($errors)) {
            // If planning produced invalid entries, surface as "no preview"
            // while still logging details.
            GcsLogger::instance()->warn('Preview mapping errors', ['errors' => $errors]);
            return [
                'creates' => [],
                'updates' => [],
                'deletes' => [],
            ];
        }

        // Read current schedule.json
        $existing = SchedulerSync::readScheduleJsonStatic(SchedulerSync::SCHEDULE_JSON_PATH);

        // Compute signature sets
        $existingSigs = [];
        foreach ($existing as $e) {
            if (is_array($e)) {
                $existingSigs[self::entrySignature($e)] = true;
            }
        }

        $creates = [];
        foreach ($plannedEntries as $p) {
            if (!is_array($p)) continue;
            $sig = self::entrySignature($p);
            if (empty($existingSigs[$sig])) {
                $creates[] = self::entryDescriptor($p);
            }
        }

        // Phase 16: updates/deletes scaffolding (to be implemented next)
        return [
            'creates' => $creates,
            'updates' => [],
            'deletes' => [],
        ];
    }

    /**
     * Apply scheduler changes using the real pipeline.
     * (Guarded by config + dry-run gate)
     */
    public static function apply(array $config): array
    {
        if (empty($config['experimental']['enabled'])) {
            throw new RuntimeException('Experimental mode is not enabled');
        }
        if (empty($config['experimental']['allow_apply'])) {
            throw new RuntimeException('Experimental apply is not allowed');
        }
        if (!empty($config['runtime']['dry_run'])) {
            throw new RuntimeException('Apply blocked while dry-run is enabled');
        }

        $horizonDays = GcsFppSchedulerHorizon::getDays();
        $runner = new GcsSchedulerRunner($config, $horizonDays, false);

        return $runner->apply();
    }

    /**
     * Helper for endpoints/UI: compute counts from any result schema.
     */
    public static function countsFromResult(array $result): array
    {
        // Common result schema from SchedulerSync:
        // { adds:n, updates:n, deletes:n, ... }
        $adds = isset($result['adds']) && is_numeric($result['adds']) ? (int)$result['adds'] : 0;
        $upd  = isset($result['updates']) && is_numeric($result['updates']) ? (int)$result['updates'] : 0;
        $del  = isset($result['deletes']) && is_numeric($result['deletes']) ? (int)$result['deletes'] : 0;

        return [
            'creates' => $adds,
            'updates' => $upd,
            'deletes' => $del,
        ];
    }

    private static function entryDescriptor(array $e): array
    {
        $type = (!empty($e['playlist'])) ? 'playlist' : 'command';

        return [
            'type' => $type,
            'playlist' => (string)($e['playlist'] ?? ''),
            'command'  => (string)($e['command'] ?? ''),
            'day'      => $e['day'] ?? null,
            'startDate'=> $e['startDate'] ?? null,
            'endDate'  => $e['endDate'] ?? null,
            'startTime'=> $e['startTime'] ?? null,
            'endTime'  => $e['endTime'] ?? null,
        ];
    }

    private static function entrySignature(array $e): string
    {
        // Signature fields chosen to match FPP schedule identity (creates-only safety)
        $type = (!empty($e['playlist'])) ? 'playlist' : 'command';
        $name = ($type === 'playlist') ? (string)($e['playlist'] ?? '') : (string)($e['command'] ?? '');

        $day = (string)($e['day'] ?? '');
        $sd  = (string)($e['startDate'] ?? '');
        $ed  = (string)($e['endDate'] ?? '');
        $st  = (string)($e['startTime'] ?? '');
        $et  = (string)($e['endTime'] ?? '');
        $stop= (string)($e['stopType'] ?? '');
        $rep = (string)($e['repeat'] ?? '');

        // args can matter for command identity
        $args = '';
        if (isset($e['args']) && is_array($e['args'])) {
            $args = json_encode(array_values($e['args']));
        }

        return implode('|', [$type, $name, $day, $sd, $ed, $st, $et, $stop, $rep, $args]);
    }
}
