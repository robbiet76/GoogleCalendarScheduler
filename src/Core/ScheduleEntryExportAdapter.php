<?php
declare(strict_types=1);

final class ScheduleEntryExportAdapter
{
    private const MAX_EXPORT_SPAN_DAYS = 366;

    public static function adapt(array $entry, array &$warnings): ?array
    {
        $summary = trim((string)($entry['playlist'] ?? $entry['command'] ?? ''));
        if ($summary === '') {
            $warnings[] = 'Skipped entry with no playlist or command name';
            return null;
        }

        /* ---------------- Date resolution ---------------- */

        $startDate = self::resolveDateForExport(
            (string)($entry['startDate'] ?? ''),
            $warnings,
            'startDate',
            null
        );

        if (!$startDate) {
            $warnings[] = "Export: '{$summary}' unable to resolve startDate; entry skipped.";
            return null;
        }

        $endDate = self::resolveDateForExport(
            (string)($entry['endDate'] ?? ''),
            $warnings,
            'endDate',
            $startDate
        );

        if (!$endDate) {
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

        /* ---------------- Location ---------------- */

        $cfg = Config::load();
        $latRaw = $cfg['location']['latitude'] ?? null;
        $lonRaw = $cfg['location']['longitude'] ?? null;

        $lat = is_numeric($latRaw) ? (float)$latRaw : null;
        $lon = is_numeric($lonRaw) ? (float)$lonRaw : null;

        /* ---------------- DTSTART ---------------- */

        [$dtStart, $startYaml] = self::resolveTime(
            $startDate,
            (string)($entry['startTime'] ?? '00:00:00'),
            (int)($entry['startTimeOffset'] ?? 0),
            $lat,
            $lon,
            $warnings,
            "{$summary} startTime"
        );

        if (!$dtStart) {
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
                $lat,
                $lon,
                $warnings,
                "{$summary} endTime"
            );

            if (!$dtEnd) {
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

    /* ===================================================================== */

    private static function resolveTime(
        string $date,
        string $time,
        int $offsetMinutes,
        ?float $lat,
        ?float $lon,
        array &$warnings,
        string $context
    ): array {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return [self::parseDateTime($date, $time), null];
        }

        if (FPPSemantics::isSymbolicTime($time)) {
            if ($lat === null || $lon === null) {
                $warnings[] = "Export: {$context} symbolic '{$time}' but latitude/longitude not configured.";
                return [null, null];
            }

            $display = SunTimeEstimator::estimate(
                $date,
                $time,
                $lat,
                $lon,
                $offsetMinutes,
                30
            );

            if (!$display) {
                $warnings[] = "Export: {$context} unable to estimate '{$time}'.";
                return [null, null];
            }

            $dt = self::parseDateTime($date, $display);
            if (!$dt) {
                return [null, null];
            }

            return [
                $dt,
                [
                    'symbolic'       => $time,
                    'offsetMinutes' => $offsetMinutes,
                    'resolvedBy'     => 'SunTimeEstimator',
                    'displayTime'   => $display,
                ],
            ];
        }

        return [null, null];
    }

    private static function parseDateTime(string $date, string $time): ?DateTime
    {
        return DateTime::createFromFormat('Y-m-d H:i:s', "{$date} {$time}") ?: null;
    }

    private static function resolveDateForExport(
        string $raw,
        array &$warnings,
        string $field,
        ?string $fallbackDate
    ): ?string {
        $raw = trim($raw);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            if (FPPSemantics::isSentinelDate($raw)) {
                $year = (int)date('Y');
                return sprintf('%04d-%s', $year, substr($raw, 5));
            }
            return $raw;
        }

        if ($raw !== '') {
            $yearHint = $fallbackDate ? (int)substr($fallbackDate, 0, 4) : (int)date('Y');
            $dt = HolidayResolver::dateFromHoliday(
                $raw,
                $yearHint,
                HolidayResolver::LOCALE_USA
            );
            if ($dt) {
                return $dt->format('Y-m-d');
            }
        }

        $warnings[] = "Export: {$field} '{$raw}' invalid.";
        return null;
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