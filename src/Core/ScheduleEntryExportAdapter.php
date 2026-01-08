<?php
declare(strict_types=1);

final class ScheduleEntryExportAdapter
{
    private const MAX_EXPORT_SPAN_DAYS = 366;

    /**
     * TEMP DEBUG helper — remove once export path is validated.
     *
     * Logs *why* an entry was skipped + a compact snapshot of key fields.
     *
     * @param array<string,mixed> $entry
     */
    private static function debugSkip(string $summary, string $reason, array $entry): void
    {
        $playlist = is_string($entry['playlist'] ?? null) ? $entry['playlist'] : '';
        $command  = is_string($entry['command'] ?? null) ? $entry['command'] : '';
        $enabled  = isset($entry['enabled']) ? (string)$entry['enabled'] : '(unset)';
        $args     = $entry['args'] ?? null;

        $startDate = is_string($entry['startDate'] ?? null) ? $entry['startDate'] : '';
        $endDate   = is_string($entry['endDate'] ?? null) ? $entry['endDate'] : '';
        $startTime = is_string($entry['startTime'] ?? null) ? $entry['startTime'] : '';
        $endTime   = is_string($entry['endTime'] ?? null) ? $entry['endTime'] : '';
        $day       = isset($entry['day']) ? (string)$entry['day'] : '(unset)';
        $repeat    = isset($entry['repeat']) ? (string)$entry['repeat'] : '(unset)';
        $stopType  = isset($entry['stopType']) ? (string)$entry['stopType'] : '(unset)';

        error_log(sprintf(
            '[GCS DEBUG][ExportAdapter] SKIP "%s": %s | playlist=%s command=%s enabled=%s day=%s repeat=%s stopType=%s startDate=%s endDate=%s startTime=%s endTime=%s args=%s',
            ($summary !== '' ? $summary : '(no summary)'),
            $reason,
            ($playlist !== '' ? $playlist : '(none)'),
            ($command !== '' ? $command : '(none)'),
            $enabled,
            $day,
            $repeat,
            $stopType,
            ($startDate !== '' ? $startDate : '(empty)'),
            ($endDate !== '' ? $endDate : '(empty)'),
            ($startTime !== '' ? $startTime : '(empty)'),
            ($endTime !== '' ? $endTime : '(empty)'),
            json_encode($args)
        ));
    }

    public static function adapt(array $entry, array &$warnings): ?array
    {
        $summary = trim((string)($entry['playlist'] ?? $entry['command'] ?? ''));
        if ($summary === '') {
            self::debugSkip('', 'missing playlist/command summary', $entry);
            $warnings[] = 'Skipped entry with no playlist or command name';
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

        /* ---------------- YAML base ---------------- */

        $yaml = [
            'stopType' => FPPSemantics::stopTypeToString((int)($entry['stopType'] ?? 0)),
            'repeat'   => FPPSemantics::repeatToYaml((int)($entry['repeat'] ?? 0)),
        ];

        /* ---------------- DTSTART ---------------- */

        [$dtStart, $startYaml] = self::resolveTime(
            $startDate,
            (string)($entry['startTime'] ?? '00:00:00'),
            (int)($entry['startTimeOffset'] ?? 0),
            $warnings,
            "{$summary} startTime",
            $entry,
            $summary
        );

        if (!$dtStart) {
            self::debugSkip($summary, 'invalid DTSTART (resolveTime returned null)', $entry);
            $warnings[] = "Export: '{$summary}' invalid DTSTART; entry skipped.";
            return null;
        }

        if ($startYaml) {
            $yaml['start'] = $startYaml;
        }

        /* ---------------- DTEND ---------------- */

        if (FPPSemantics::isEndOfDayTime((string)($entry['endTime'] ?? ''))) {
            $dtEnd = (clone $dtStart)->modify('+1 day')->setTime(0, 0, 0);
        } else {
            [$dtEnd, $endYaml] = self::resolveTime(
                $startDate,
                (string)($entry['endTime'] ?? '00:00:00'),
                (int)($entry['endTimeOffset'] ?? 0),
                $warnings,
                "{$summary} endTime",
                $entry,
                $summary
            );

            if (!$dtEnd) {
                self::debugSkip($summary, 'invalid DTEND (resolveTime returned null)', $entry);
                $warnings[] = "Export: '{$summary}' invalid DTEND; entry skipped.";
                return null;
            }

            if ($endYaml) {
                $yaml['end'] = $endYaml;
            }
        }

        if ($dtEnd <= $dtStart) {
            // Not a skip, but useful for diagnosing “end before start” patterns
            error_log(sprintf(
                '[GCS DEBUG][ExportAdapter] ADJUST "%s": DTEND <= DTSTART, rolling end +1 day',
                $summary
            ));
            $dtEnd = (clone $dtEnd)->modify('+1 day');
        }

        /* ---------------- RRULE ---------------- */

        $rrule = self::buildClampedRrule(
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

    /* ===================================================================== */

    private static function resolveTime(
        string $date,
        string $time,
        int $offsetMinutes,
        array &$warnings,
        string $context,
        array $entryForDebug,
        string $summaryForDebug
    ): array {
        // Absolute time
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            $dt = FPPSemantics::combineDateTime($date, $time);
            if (!$dt) {
                self::debugSkip($summaryForDebug, "combineDateTime failed for {$context} ({$date} {$time})", $entryForDebug);
                $warnings[] = "Export: {$context} unable to combine date/time '{$date} {$time}'.";
                return [null, null];
            }
            return [$dt, null];
        }

        // Symbolic time
        if (FPPSemantics::isSymbolicTime($time)) {
            $resolved = FPPSemantics::resolveSymbolicTime(
                $date,
                $time,
                $offsetMinutes
            );

            if (!$resolved) {
                self::debugSkip($summaryForDebug, "unable to resolve symbolic time for {$context} ({$time}, offset={$offsetMinutes})", $entryForDebug);
                $warnings[] =
                    "Export: {$context} unable to resolve symbolic time '{$time}'.";
                return [null, null];
            }

            return [
                $resolved['datetime'],
                $resolved['yaml'],
            ];
        }

        // Unknown / malformed time string
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
            $y = (int)substr($endDate, 0, 4);
            $mmdd = substr($endDate, 5);
            $candidate = sprintf('%04d-%s', $y + 1, $mmdd);

            $warnings[] =
                "Export: '{$summary}' endDate adjusted across year boundary ({$endDate} → {$candidate}).";

            return $candidate;
        }

        return $endDate;
    }

    private static function buildClampedRrule(
        array $entry,
        string $startDate,
        string $endDate,
        array &$warnings,
        string $summary
    ): ?string {
        if ($startDate === $endDate) {
            return null;
        }

        $start = new DateTime($startDate);
        $end   = new DateTime($endDate);
        $max   = (clone $start)->modify('+' . self::MAX_EXPORT_SPAN_DAYS . ' days');

        if ($end > $max) {
            $warnings[] =
                "Export: '{$summary}' endDate {$endDate} clamped for Google compatibility.";
            $end = $max;
        }

        $until = $end->format('Ymd') . 'T235959';
        $dayEnum = (int)($entry['day'] ?? -1);

        if ($dayEnum === 7) {
            return "FREQ=DAILY;UNTIL={$until}";
        }

        $byDay = FPPSemantics::dayEnumToByDay($dayEnum);

        return $byDay !== ''
            ? "FREQ=WEEKLY;BYDAY={$byDay};UNTIL={$until}"
            : "FREQ=DAILY;UNTIL={$until}";
    }
}