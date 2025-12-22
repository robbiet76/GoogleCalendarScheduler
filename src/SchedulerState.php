<?php

final class GcsSchedulerState
{
    /** @var array<int,GcsExistingScheduleEntry> */
    private array $entries = [];

    /**
     * Load existing FPP scheduler entries within the horizon window.
     *
     * Phase 11.1 intent:
     * - Wire real FPP schedule state into the diff pipeline.
     * - No YAML behavior changes.
     * - No new scheduler features.
     *
     * @param int $horizonDays
     * @param array<string,mixed>|null $cfg
     */
    public static function load(int $horizonDays, ?array $cfg = null): self
    {
        $self = new self();

        $cfg = is_array($cfg) ? $cfg : [];

        $paths = [];

        // Optional override via config (non-breaking if absent)
        $override = $cfg['fpp']['schedule_json_path'] ?? $cfg['schedule']['json_path'] ?? null;
        if (is_string($override) && trim($override) !== '') {
            $paths[] = $override;
        }

        // Common FPP locations (safe to try; first existing wins)
        $paths[] = '/home/fpp/media/config/schedule.json';
        $paths[] = '/home/fpp/media/settings/schedule.json';
        $paths[] = '/home/fpp/media/schedule.json';

        $schedulePath = self::firstExistingPath($paths);

        if ($schedulePath === null) {
            GcsLog::warn('SchedulerState: no schedule.json found; returning empty state', [
                'tried' => $paths,
            ]);
            return $self;
        }

        $raw = @file_get_contents($schedulePath);
        if ($raw === false || trim($raw) === '') {
            GcsLog::warn('SchedulerState: schedule.json unreadable or empty; returning empty state', [
                'path' => $schedulePath,
            ]);
            return $self;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            GcsLog::warn('SchedulerState: schedule.json invalid JSON; returning empty state', [
                'path' => $schedulePath,
                'jsonError' => function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown',
            ]);
            return $self;
        }

        // Accept multiple shapes:
        // - [ {entry}, {entry} ]
        // - { "schedule": [ {entry}, ... ] }
        // - { "entries": [ {entry}, ... ] }
        $rows = null;
        if (self::isList($decoded)) {
            $rows = $decoded;
        } elseif (isset($decoded['schedule']) && is_array($decoded['schedule'])) {
            $rows = $decoded['schedule'];
        } elseif (isset($decoded['entries']) && is_array($decoded['entries'])) {
            $rows = $decoded['entries'];
        } else {
            // Unknown shape; best effort: if any top-level value looks like a list, take it.
            foreach ($decoded as $v) {
                if (is_array($v) && self::isList($v)) {
                    $rows = $v;
                    break;
                }
            }
        }

        if (!is_array($rows) || empty($rows)) {
            GcsLog::info('SchedulerState: schedule.json contained no entries (or unrecognized structure)', [
                'path' => $schedulePath,
                'topLevelKeys' => array_keys($decoded),
            ]);
            return $self;
        }

        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify("+{$horizonDays} days");

        $kept = 0;
        $skipped = 0;
        $convertFailures = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            // Horizon filter (best-effort). If we cannot read time fields, keep it (diff layer can decide).
            if (!self::rowIntersectsHorizon($row, $now, $horizonEnd)) {
                $skipped++;
                continue;
            }

            $entry = self::toExistingScheduleEntry($row);
            if ($entry === null) {
                $convertFailures++;
                continue;
            }

            $self->entries[] = $entry;
            $kept++;
        }

        GcsLog::info('SchedulerState: loaded existing schedule entries', [
            'path' => $schedulePath,
            'totalRows' => count($rows),
            'kept' => $kept,
            'skippedOutOfHorizonOrInvalid' => $skipped,
            'convertFailures' => $convertFailures,
        ]);

        return $self;
    }

    /**
     * @return array<int,GcsExistingScheduleEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @param array<int,string> $paths
     */
    private static function firstExistingPath(array $paths): ?string
    {
        foreach ($paths as $p) {
            if (is_string($p) && $p !== '' && @file_exists($p)) {
                return $p;
            }
        }
        return null;
    }

    private static function isList(array $a): bool
    {
        if ($a === []) return true;
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i) return false;
            $i++;
        }
        return true;
    }

    private static function rowIntersectsHorizon(array $row, DateTime $now, DateTime $horizonEnd): bool
    {
        // Common possible keys (best effort; we do not enforce schema changes)
        $startKeys = ['start', 'startDateTime', 'start_time', 'startTime'];
        $endKeys   = ['end', 'endDateTime', 'end_time', 'endTime'];

        $startStr = null;
        $endStr = null;

        foreach ($startKeys as $k) {
            if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
                $startStr = $row[$k];
                break;
            }
        }
        foreach ($endKeys as $k) {
            if (isset($row[$k]) && is_string($row[$k]) && trim($row[$k]) !== '') {
                $endStr = $row[$k];
                break;
            }
        }

        // If we can't determine, keep it.
        if ($startStr === null && $endStr === null) {
            return true;
        }

        try {
            $start = ($startStr !== null) ? new DateTime($startStr) : null;
            $end = ($endStr !== null) ? new DateTime($endStr) : null;

            // Normalize missing endpoints
            if ($start === null && $end !== null) $start = clone $end;
            if ($end === null && $start !== null) $end = clone $start;

            if ($start === null || $end === null) {
                return true;
            }

            // Intersects [now, horizonEnd]
            return !($end < $now || $start > $horizonEnd);
        } catch (Exception $e) {
            // If parsing fails, keep it (diff layer can decide)
            return true;
        }
    }

    /**
     * Convert an arbitrary decoded schedule row into a GcsExistingScheduleEntry instance.
     * This is written defensively to match whatever constructor/factory exists in your repo.
     */
    private static function toExistingScheduleEntry(array $row): ?GcsExistingScheduleEntry
    {
        // Prefer a named factory if present
        if (method_exists('GcsExistingScheduleEntry', 'fromArray')) {
            try {
                /** @var GcsExistingScheduleEntry $e */
                $e = GcsExistingScheduleEntry::fromArray($row);
                return $e;
            } catch (Throwable $t) {
                return null;
            }
        }

        // Try common constructor patterns
        try {
            /** @var GcsExistingScheduleEntry $e */
            $e = new GcsExistingScheduleEntry($row);
            return $e;
        } catch (Throwable $t) {
            // Fall through
        }

        // If your existing entry class expects specific args, we can adapt once we see it.
        return null;
    }
}
