<?php
declare(strict_types=1);

final class ScheduleEntryExportAdapter
{
    private const MAX_EXPORT_SPAN_DAYS = 366;

    /* =====================================================================
     * DEBUG
     * ===================================================================== */

    private static function debugSkip(string $summary, string $reason, array $entry): void
    {
        $playlist = is_string($entry['playlist'] ?? null) ? $entry['playlist'] : '';
        $command  = is_string($entry['command'] ?? null) ? $entry['command'] : '';
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

        /* ---------------- YAML (minimal) ---------------- */

        $yaml = [];

        $enabled = FPPSemantics::normalizeEnabled($entry['enabled'] ?? true);
        if (!FPPSemantics::isDefaultEnabled($enabled)) {
            $yaml['enabled'] = false;
        }

        $stopType = FPPSemantics::stopTypeToString((int)($entry['stopType'] ?? 0));
        if ($stopType !== FPPSemantics::getDefaultStopType()) {
            $yaml['stopType'] = $stopType;
        }

        $repeat = FPPSemantics::repeatToYaml((int)($entry['repeat'] ?? 0));
        if ($repeat !== FPPSemantics::getDefaultRepeat()) {
            $yaml['repeat'] = $repeat;
        }

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
            self::debugSkip($summary, 'invalid DTSTART', $entry);
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
                self::debugSkip($summary, 'invalid DTEND', $entry);
                $warnings[] = "Export: '{$summary}' invalid DTEND; entry skipped.";
                return null;
            }

            if ($endYaml) {
                $yaml['end'] = $endYaml;
            }
        }

        if ($dtEnd <= $dtStart) {
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
            $dow = strtoupper($dt->format('D')); // MON → MO
            $dow = substr($dow, 0, 2);

            if (isset($allowed[$dow])) {
                return $dt->format('Y-m-d');
            }
            $dt->modify('+1 day');
        }

        return $ymd; // safety fallback
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
            return [$dt, null];
        }

        if (FPPSemantics::isSymbolicTime($time)) {
            $resolved = FPPSemantics::resolveSymbolicTime($date, $time, $offsetMinutes);
            return $resolved ? [$resolved['datetime'], $resolved['yaml']] : [null, null];
        }

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
            $candidate = sprintf('%04d-%s', $y + 1, substr($endDate, 5));
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

        $end = new DateTime($endDate);
        $max = (new DateTime($startDate))->modify('+' . self::MAX_EXPORT_SPAN_DAYS . ' days');

        if ($end > $max) {
            $warnings[] =
                "Export: '{$summary}' endDate {$endDate} clamped for Google compatibility.";
            $end = $max;
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