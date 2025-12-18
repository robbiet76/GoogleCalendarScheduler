<?php

final class SchedulerApply
{
    private const SCHEDULE_PATH = '/home/fpp/media/config/schedule.json';

    /**
     * Apply scheduler diff.
     *
     * Phase 8.4:
     * - REAL writes enabled
     * - ADD-ONLY
     * - Updates & deletes are logged but not executed
     *
     * @param array{adds:array,updates:array,deletes:array} $diff
     * @param bool $dryRun
     * @return array{adds:int,updates:int,deletes:int}
     */
    public static function apply(array $diff, bool $dryRun): array
    {
        $adds    = is_array($diff['adds'] ?? null) ? $diff['adds'] : [];
        $updates = is_array($diff['updates'] ?? null) ? $diff['updates'] : [];
        $deletes = is_array($diff['deletes'] ?? null) ? $diff['deletes'] : [];

        if ($dryRun) {
            self::logDryRun($adds, $updates, $deletes);
            return self::counts($adds, $updates, $deletes);
        }

        // Live mode (still safe)
        $existing = self::loadSchedule();
        $originalCount = count($existing);

        if ($adds) {
            self::backupSchedule();
            foreach ($adds as $entry) {
                $existing[] = self::normalizeForSchedule($entry);
            }
            self::saveSchedule($existing);
        }

        GcsLog::info('SchedulerApply live add-only complete', [
            'originalCount' => $originalCount,
            'added'         => count($adds),
            'finalCount'    => count($existing),
            'updatesSkipped'=> count($updates),
            'deletesSkipped'=> count($deletes),
        ]);

        return self::counts($adds, $updates, $deletes);
    }

    // ------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------

    private static function loadSchedule(): array
    {
        if (!file_exists(self::SCHEDULE_PATH)) {
            return [];
        }

        $raw = file_get_contents(self::SCHEDULE_PATH);
        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    private static function saveSchedule(array $entries): void
    {
        file_put_contents(
            self::SCHEDULE_PATH,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private static function backupSchedule(): void
    {
        $backup = self::SCHEDULE_PATH . '.bak-' . date('Ymd-His');
        if (file_exists(self::SCHEDULE_PATH)) {
            copy(self::SCHEDULE_PATH, $backup);
        }
    }

    /**
     * Normalize mapped entry into FPP schedule.json format
     */
    private static function normalizeForSchedule(array $e): array
    {
        return [
            'enabled'        => (int)($e['enabled'] ?? 1),
            'sequence'       => (int)($e['sequence'] ?? 0),
            'playlist'       => (string)($e['playlist'] ?? ''),
            'dayMask'        => (int)($e['dayMask'] ?? 0),
            'startTime'      => (string)($e['startTime'] ?? ''),
            'endTime'        => (string)($e['endTime'] ?? ''),
            'startTimeOffset'=> 0,
            'endTimeOffset'  => 0,
            'repeat'         => (int)($e['repeat'] ?? 0),
            'startDate'      => (string)($e['startDate'] ?? ''),
            'endDate'        => (string)($e['endDate'] ?? ''),
            'stopType'       => (int)($e['stopType'] ?? 0),
        ];
    }

    private static function logDryRun(array $adds, array $updates, array $deletes): void
    {
        foreach ($adds as $e) {
            GcsLog::info('[DRY-RUN] ADD', self::summary($e));
        }

        foreach ($updates as $u) {
            GcsLog::info('[DRY-RUN] UPDATE (skipped)', []);
        }

        foreach ($deletes as $e) {
            GcsLog::info('[DRY-RUN] DELETE (skipped)', self::summary($e));
        }
    }

    private static function summary(array $e): array
    {
        return [
            'playlist'  => (string)($e['playlist'] ?? ''),
            'days'      => (int)($e['dayMask'] ?? 0),
            'start'     => (string)($e['startTime'] ?? ''),
            'end'       => (string)($e['endTime'] ?? ''),
            'from'      => (string)($e['startDate'] ?? ''),
            'to'        => (string)($e['endDate'] ?? ''),
        ];
    }

    private static function counts(array $adds, array $updates, array $deletes): array
    {
        return [
            'adds'    => count($adds),
            'updates' => count($updates),
            'deletes' => count($deletes),
        ];
    }
}
