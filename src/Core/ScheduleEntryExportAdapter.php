<?php
declare(strict_types=1);

final class ScheduleEntryExportAdapter
{
    /* =====================================================================
     * DEBUG
     * ===================================================================== */

    private static function debugSkip(string $summary, string $reason, array $entry): void
    {
        $playlist = trim((string)($entry['playlist'] ?? ''));
        $command  = trim((string)($entry['command'] ?? ''));
        $enabled  = array_key_exists('enabled', $entry) ? (string)$entry['enabled'] : '(unset)';

        error_log(sprintf(
            '[GCS DEBUG][ExportAdapter] SKIP "%s": %s | playlist=%s command=%s enabled=%s',
            ($summary !== '' ? $summary : '(no summary)'),
            $reason,
            ($playlist !== '' ? $playlist : '(none)'),
            ($command !== '' ? $command : '(none)'),
            $enabled
        ));
    }

    /* =====================================================================
     * PUBLIC
     * ===================================================================== */

    public static function adapt(array $entry, array &$warnings): ?array
    {
        $playlist = trim((string)($entry['playlist'] ?? ''));
        $command  = trim((string)($entry['command'] ?? ''));

        /* ---------------- Determine entry type ---------------- */

        if ($playlist !== '') {
            $summary = $playlist;
            $type = str_ends_with(strtolower($playlist), '.fseq')
                ? FPPSemantics::TYPE_SEQUENCE
                : FPPSemantics::TYPE_PLAYLIST;
        } elseif ($command !== '') {
            $summary = $command;
            $type = FPPSemantics::TYPE_COMMAND;
        } else {
            self::debugSkip('', 'missing playlist, sequence, or command name', $entry);
            $warnings[] = 'Skipped entry with no playlist, sequence, or command name';
            return null;
        }

        /* ---------------- Date resolution ---------------- */

        $startDate = FPPSemantics::resolveDate(
            (string)($entry['startDate'] ?? ''),
            null,
            $warnings,
            'startDate'
        );

        if (!$startDate) {
            self::debugSkip($summary, 'unable to resolve startDate', $entry);
            $warnings[] = "Export: '{$summary}' unable to resolve startDate; entry skipped.";
            return null;
        }

        $endDate = FPPSemantics::resolveDate(
            (string)($entry['endDate'] ?? ''),
            $startDate,
            $warnings,
            'endDate'
        );

        if (!$endDate) {
            self::debugSkip($summary, 'unable to resolve endDate', $entry);
            $warnings[] = "Export: '{$summary}' unable to resolve endDate; entry skipped.";
            return null;
        }

        $endDate = self::ensureEndDateNotBeforeStart(
            $startDate,
            $endDate,
            $warnings,
            $summary
        );

        /* ---------------- DTSTART day-mask alignment ---------------- */

        $dayEnum = (int)($entry['day'] ?? 7);
        if ($dayEnum !== 7) {
            $aligned = self::alignStartDateToDayMask($startDate, $dayEnum);
            if ($aligned !== $startDate) {
                $warnings[] =
                    "Export: '{$summary}' startDate adjusted to first valid day-of-week ({$startDate} → {$aligned}).";
                $startDate = $aligned;
            }
        }

        /* ---------------- YAML (minimal, semantic) ---------------- */

        $yaml = [];

        if ($type !== FPPSemantics::TYPE_PLAYLIST) {
            $yaml['type'] = $type;
        }

        $enabled = FPPSemantics::normalizeEnabled($entry['enabled'] ?? true);
        if (!FPPSemantics::isDefaultEnabled($enabled)) {
            $yaml['enabled'] = false;
        }

        if ($type === FPPSemantics::TYPE_COMMAND) {
            $yaml['command'] = [
                'name' => $command,
                'args' => array_values($entry['args'] ?? []),
            ];
        }

        $stopType = FPPSemantics::stopTypeToString((int)($entry['stopType'] ?? 0));
        if ($stopType !== FPPSemantics::getDefaultStopType()) {
            $yaml['stopType'] = $stopType;
        }

        $repeat = FPPSemantics::repeatToYaml((int)($entry['repeat'] ?? 0));
        $defaultRepeat = FPPSemantics::getDefaultRepeatForType($type);
        if ($repeat !== $defaultRepeat) {
            $yaml['repeat'] = $repeat;
        }

        /* ---------------- DTSTART ---------------- */

        $startTime = (string)($entry['startTime'] ?? '00:00:00');

        // Commands must have a real "fire time" (00:00:00 is almost always unintended)
        if ($type === FPPSemantics::TYPE_COMMAND && $startTime === '00:00:00') {
            self::debugSkip($summary, 'command has startTime 00:00:00 (invalid for export)', $entry);
            $warnings[] = "Export: '{$summary}' command startTime '00:00:00' is invalid; entry skipped.";
            return null;
        }

        [$dtStart, $startYaml] = self::resolveTime(
            $startDate,
            $startTime,
            (int)($entry['startTimeOffset'] ?? 0),
            $warnings,
            "{$summary} startTime",
            $entry,
            $summary
        );

        if (!$dtStart) {
            self::debugSkip($summary, 'invalid DTSTART', $entry);
            $warnings[] = "Export: '{$summary}' invalid DTSTART; entry skipped.";
            return null;
        }

        if ($startYaml) {
            $yaml['start'] = $startYaml;
        }

        /* ---------------- DTEND ---------------- */

        if ($type === FPPSemantics::TYPE_COMMAND) {
            // Commands are exported as 1-minute events for clean round-trip import.
            $dtEnd = (clone $dtStart)->modify('+1 minute');
        } else {
            $endTime = (string)($entry['endTime'] ?? '');

            if (FPPSemantics::isEndOfDayTime($endTime)) {
                $dtEnd = (clone $dtStart)->modify('+1 day')->setTime(0, 0, 0);
            } else {
                [$dtEnd] = self::resolveTime(
                    $startDate,
                    ($endTime !== '' ? $endTime : '00:00:00'),
                    (int)($entry['endTimeOffset'] ?? 0),
                    $warnings,
                    "{$summary} endTime",
                    $entry,
                    $summary
                );

                if (!$dtEnd) {
                    self::debugSkip($summary, 'invalid DTEND', $entry);
                    $warnings[] = "Export: '{$summary}' invalid DTEND; entry skipped.";
                    return null;
                }
            }

            if ($dtEnd <= $dtStart) {
                $dtEnd = (clone $dtEnd)->modify('+1 day');
            }
        }

        /* ---------------- RRULE ---------------- */

        $rrule = self::buildGuardedRrule(
            $entry,
            $startDate,
            $endDate,
            $warnings,
            $summary
        );

        return [
            'summary' => $summary,
            'dtstart' => $dtStart,
            'dtend'   => $dtEnd,
            'rrule'   => $rrule,
            'exdates' => [],
            'yaml'    => $yaml,
        ];
    }

    /* =====================================================================
     * HELPERS
     * ===================================================================== */

    private static function alignStartDateToDayMask(string $ymd, int $dayEnum): string
    {
        $byDay = FPPSemantics::dayEnumToByDay($dayEnum);
        if ($byDay === '') {
            return $ymd;
        }

        $allowed = array_flip(explode(',', $byDay));
        $dt = new DateTime($ymd);

        for ($i = 0; $i < 7; $i++) {
            $dow = substr(strtoupper($dt->format('D')), 0, 2);
            if (isset($allowed[$dow])) {
                return $dt->format('Y-m-d');
            }
            $dt->modify('+1 day');
        }

        return $ymd;
    }

    private static function resolveTime(
        string $date,
        string $time,
        int $offsetMinutes,
        array &$warnings,
        string $context,
        array $entryForDebug,
        string $summaryForDebug
    ): array {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            $dt = FPPSemantics::combineDateTime($date, $time);
            if (!$dt) {
                self::debugSkip($summaryForDebug, "combineDateTime failed for {$context} ({$date} {$time})", $entryForDebug);
                $warnings[] = "Export: {$context} unable to combine date/time '{$date} {$time}'.";
            }
            return [$dt, null];
        }

        if (FPPSemantics::isSymbolicTime($time)) {
            $resolved = FPPSemantics::resolveSymbolicTime($date, $time, $offsetMinutes);
            if (!$resolved) {
                self::debugSkip($summaryForDebug, "unable to resolve symbolic time for {$context} ({$time}, offset={$offsetMinutes})", $entryForDebug);
                $warnings[] = "Export: {$context} unable to resolve symbolic time '{$time}'.";
                return [null, null];
            }
            return [$resolved['datetime'], $resolved['yaml']];
        }

        self::debugSkip($summaryForDebug, "unrecognized time format for {$context} ('{$time}')", $entryForDebug);
        $warnings[] = "Export: {$context} unrecognized time format '{$time}'.";
        return [null, null];
    }

    private static function ensureEndDateNotBeforeStart(
        string $startDate,
        string $endDate,
        array &$warnings,
        string $summary
    ): string {
        if ($endDate < $startDate) {
            $candidate = sprintf(
                '%04d-%s',
                ((int)substr($endDate, 0, 4)) + 1,
                substr($endDate, 5)
            );
            $warnings[] =
                "Export: '{$summary}' endDate adjusted across year boundary ({$endDate} → {$candidate}).";
            return $candidate;
        }
        return $endDate;
    }

    private static function buildGuardedRrule(
        array $entry,
        string $startDate,
        string $endDate,
        array &$warnings,
        string $summary
    ): ?string {
        if ($startDate === $endDate) {
            return null;
        }

        $end = new DateTime($endDate);
        $guard = FPPSemantics::getSchedulerGuardDate();

        // Guard date is the single cap (matches FPP semantics)
        if ($end > $guard) {
            $warnings[] =
                "Export: '{$summary}' endDate {$endDate} clamped for Google compatibility.";
            $end = clone $guard;
        }

        $until = $end->format('Ymd') . 'T235959';
        $dayEnum = (int)($entry['day'] ?? 7);

        if ($dayEnum === 7) {
            return "FREQ=DAILY;UNTIL={$until}";
        }

        $byDay = FPPSemantics::dayEnumToByDay($dayEnum);

        return $byDay !== ''
            ? "FREQ=WEEKLY;BYDAY={$byDay};UNTIL={$until}"
            : "FREQ=DAILY;UNTIL={$until}";
    }
}