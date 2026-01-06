<?php
declare(strict_types=1);

final class ScheduleEntryExportAdapter
{
    /**
     * Hard clamp for RRULE window (Google import stability).
     * If an entry spans longer than this, we clamp the UNTIL date.
     */
    private const MAX_EXPORT_SPAN_DAYS = 366;

    public static function adapt(array $entry, array &$warnings): ?array
    {
        $summary = '';
        if (!empty($entry['playlist']) && is_string($entry['playlist'])) {
            $summary = trim($entry['playlist']);
        } elseif (!empty($entry['command']) && is_string($entry['command'])) {
            $summary = trim($entry['command']);
        }

        if ($summary === '') {
            $warnings[] = 'Skipped entry with no playlist or command name';
            return null;
        }

        // ---- START / END DATE RESOLUTION ----

        $startDate = self::resolveDateForExport(
            (string)($entry['startDate'] ?? ''),
            $warnings,
            'startDate',
            $entry,
            null
        );

        if ($startDate === null) {
            $warnings[] = "Skipped '{$summary}': unable to resolve start date";
            return null;
        }

        $endDate = self::resolveDateForExport(
            (string)($entry['endDate'] ?? ''),
            $warnings,
            'endDate',
            $entry,
            $startDate
        );

        if ($endDate === null) {
            $warnings[] = "Skipped '{$summary}': unable to resolve end date";
            return null;
        }

        // If we resolved something like Thanksgiving (Nov) to Epiphany (Jan) and got the wrong year,
        // bump end year forward until end >= start (best-effort).
        $endDate = self::ensureEndDateNotBeforeStart($startDate, $endDate, $warnings, $summary);

        // ---- TIMES ----

        $startTime = (string)($entry['startTime'] ?? '00:00:00');
        $endTime   = (string)($entry['endTime'] ?? '00:00:00');

        // ---- YAML (start with base yaml, then merge/augment) ----
        $yaml = [
            'stopType' => self::stopTypeToString((int)($entry['stopType'] ?? 0)),
            'repeat'   => self::repeatToYaml($entry['repeat'] ?? 0),
        ];

        // Preserve normalized YAML from FppSemantics if present
        if (isset($entry['__gcs_yaml']) && is_array($entry['__gcs_yaml'])) {
            $yaml = array_merge($yaml, $entry['__gcs_yaml']);
        }

        // DTSTART
        $dtStart = self::parseDateTime($startDate, $startTime);

        // If time is still symbolic (Dusk/Dawn/SunRise/SunSet), try resolving here as a safety net.
        if (!$dtStart && self::looksSymbolicTime($startTime)) {
            $resolved = FppSemantics::resolveSymbolicTime(
                $startDate,
                $startTime,
                (int)($entry['startTimeOffset'] ?? 0),
                $warnings
            );

            if (is_array($resolved) && isset($resolved['displayTime'])) {
                $startTimeResolved = (string)$resolved['displayTime'];
                $dtStart = self::parseDateTime($startDate, $startTimeResolved);

                if ($dtStart) {
                    // Capture intent so import can restore symbolic time later
                    $yaml['start'] = $resolved['yaml'] ?? [
                        'symbolic' => $startTime,
                        'offsetMinutes' => (int)($entry['startTimeOffset'] ?? 0),
                        'resolvedBy' => 'adapter_safety_net',
                    ];
                }
            }
        }

        if (!$dtStart) {
            $warnings[] = "Skipped '{$summary}': invalid DTSTART";
            return null;
        }

        // DTEND
        if ($endTime === '24:00:00') {
            $dtEnd = (clone $dtStart)->modify('+1 day')->setTime(0, 0, 0);
        } else {
            $dtEnd = self::parseDateTime($startDate, $endTime);

            if (!$dtEnd && self::looksSymbolicTime($endTime)) {
                $resolved = FppSemantics::resolveSymbolicTime(
                    $startDate,
                    $endTime,
                    (int)($entry['endTimeOffset'] ?? 0),
                    $warnings
                );

                if (is_array($resolved) && isset($resolved['displayTime'])) {
                    $endTimeResolved = (string)$resolved['displayTime'];
                    $dtEnd = self::parseDateTime($startDate, $endTimeResolved);

                    if ($dtEnd) {
                        $yaml['end'] = $resolved['yaml'] ?? [
                            'symbolic' => $endTime,
                            'offsetMinutes' => (int)($entry['endTimeOffset'] ?? 0),
                            'resolvedBy' => 'adapter_safety_net',
                        ];
                    }
                }
            }
        }

        if (!$dtEnd) {
            $warnings[] = "Skipped '{$summary}': invalid DTEND";
            return null;
        }

        // If dtEnd <= dtStart, treat as crossing midnight.
        if ($dtEnd <= $dtStart) {
            $dtEnd = (clone $dtEnd)->modify('+1 day');
        }

        // ---- RRULE ----
        $rrule = self::buildClampedRrule($entry, $startDate, $endDate, $warnings, $summary);

        // ---- EXDATES ----
        $exdates = self::extractExdates($entry, $warnings, $summary);

        // Helpful debug context for export/import loops
        $yaml['resolvedStartDate'] = $startDate;
        $yaml['resolvedEndDate']   = $endDate;

        return [
            'summary' => $summary,
            'dtstart' => $dtStart,
            'dtend'   => $dtEnd,
            'rrule'   => $rrule,
            'exdates' => $exdates,
            'yaml'    => $yaml,
        ];
    }

    /* ===================================================================== */

    private static function resolveDateForExport(
        string $raw,
        array &$warnings,
        string $field,
        array $entry,
        ?string $fallback = null
    ): ?string {
        $raw = trim($raw);

        // YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            if (strpos($raw, '0000-') === 0) {
                // For "0000-.." use current year (export display only).
                $year = (int)date('Y');
                return sprintf('%04d-%s', $year, substr($raw, 5));
            }
            return $raw;
        }

        // Holiday shortName
        if ($raw !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $raw)) {
            // If we already have a resolved startDate, use its year as a hint.
            $hintYear = null;
            if ($fallback !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback)) {
                $hintYear = (int)substr($fallback, 0, 4);
            }
            $year = $hintYear ?? (int)date('Y');

            $d = FppSemantics::dateForHoliday($raw, $year);
            if ($d !== null) {
                return $d;
            }

            $warnings[] = "Export: {$field} '{$raw}' is not a known holiday in current locale.";
        }

        // Fallback to startDate (resolved) if we must
        if ($fallback !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback)) {
            $warnings[] = "Export: {$field} '{$raw}' unresolved; clamped to {$fallback}";
            return $fallback;
        }

        return null;
    }

    private static function ensureEndDateNotBeforeStart(
        string $startDate,
        string $endDate,
        array &$warnings,
        string $summary
    ): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return $endDate;
        }

        // If end < start, bump end year by +1 preserving MM-DD (repeat until not before, max 2 bumps).
        if ($endDate < $startDate) {
            $y = (int)substr($endDate, 0, 4);
            $mmdd = substr($endDate, 5);

            for ($i = 0; $i < 2; $i++) {
                $y++;
                $candidate = sprintf('%04d-%s', $y, $mmdd);
                if ($candidate >= $startDate) {
                    $warnings[] = "Export: '{$summary}' endDate adjusted across year boundary ({$endDate} → {$candidate}).";
                    return $candidate;
                }
                $endDate = $candidate;
            }
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
        // If identical raw dates, treat as a single VEVENT (no RRULE).
        if (($entry['startDate'] ?? null) === ($entry['endDate'] ?? null)) {
            return null;
        }

        // If resolved dates are identical, also no RRULE.
        if ($startDate === $endDate) {
            return null;
        }

        // Clamp export span for Google stability
        $start = DateTime::createFromFormat('Y-m-d', $startDate);
        $end   = DateTime::createFromFormat('Y-m-d', $endDate);

        if ($start instanceof DateTime && $end instanceof DateTime) {
            $maxEnd = (clone $start)->modify('+' . self::MAX_EXPORT_SPAN_DAYS . ' days');

            if ($end > $maxEnd) {
                $warnings[] =
                    "Export: '{$summary}' endDate {$endDate} exceeds +" . self::MAX_EXPORT_SPAN_DAYS .
                    " days; clamped to " . $maxEnd->format('Y-m-d') . " for Google compatibility.";
                $endDate = $maxEnd->format('Y-m-d');
            }

            // If after clamp we ended up before start, force to start day
            if ($endDate < $startDate) {
                $warnings[] =
                    "Export: '{$summary}' endDate {$endDate} < startDate {$startDate}; clamped to startDate.";
                $endDate = $startDate;
            }
        }

        // Safety: if resolved endDate still < startDate (string compare), force endDate = startDate
        if ($endDate < $startDate) {
            $warnings[] =
                "Export: '{$summary}' RRULE endDate {$endDate} < startDate {$startDate}; forced to startDate.";
            $endDate = $startDate;
        }

        // UNTIL: use floating UNTIL to avoid TZID+Z mismatches in some importers.
        // (DTSTART is TZID local; UNTIL is date-time in the same "local" frame here.)
        $untilLocal = str_replace('-', '', $endDate) . 'T235959';

        $dayEnum = (int)($entry['day'] ?? -1);

        if ($dayEnum === 7) {
            return 'FREQ=DAILY;UNTIL=' . $untilLocal;
        }

        $byDay = self::fppDayEnumToByDay($dayEnum);
        if ($byDay !== '') {
            return 'FREQ=WEEKLY;BYDAY=' . $byDay . ';UNTIL=' . $untilLocal;
        }

        // Unknown day enum: fall back to DAILY
        $warnings[] = "Export: '{$summary}' unknown day enum ({$dayEnum}); using DAILY rule.";
        return 'FREQ=DAILY;UNTIL=' . $untilLocal;
    }

    /**
     * Extract EXDATEs emitted by ExportService precedence pass.
     * Source field: __gcs_export_exdates_dtstart: array<int,string> "YYYY-MM-DD HH:MM:SS"
     *
     * @return array<int,DateTime>
     */
    private static function extractExdates(array $entry, array &$warnings, string $summary): array
    {
        $raw = $entry['__gcs_export_exdates_dtstart'] ?? null;
        if (!is_array($raw) || empty($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
                $warnings[] = "Export: '{$summary}' EXDATE ignored (invalid format): {$v}";
                continue;
            }

            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $v);
            if (!$dt) {
                $warnings[] = "Export: '{$summary}' EXDATE ignored (unparseable): {$v}";
                continue;
            }

            $out[] = $dt;
        }

        return $out;
    }

    /* ===================================================================== */

    private static function parseDateTime(string $date, string $time): ?DateTime
    {
        // Expect H:i:s
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return null;
        }

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
        return ($dt instanceof DateTime) ? $dt : null;
    }

    private static function looksSymbolicTime(string $v): bool
    {
        return in_array($v, ['Dawn', 'Dusk', 'SunRise', 'SunSet'], true);
    }

    private static function fppDayEnumToByDay(int $enum): string
    {
        return match ($enum) {
            0  => 'SU',
            1  => 'MO',
            2  => 'TU',
            3  => 'WE',
            4  => 'TH',
            5  => 'FR',
            6  => 'SA',
            8  => 'MO,TU,WE,TH,FR',
            9  => 'SU,SA',
            10 => 'MO,WE,FR',
            11 => 'TU,TH',
            12 => 'SU,MO,TU,WE,TH',
            13 => 'FR,SA',
            default => '',
        };
    }

    private static function stopTypeToString(int $v): string
    {
        return match ($v) {
            1 => 'hard',
            2 => 'graceful_loop',
            default => 'graceful',
        };
    }

    private static function repeatToYaml($v)
    {
        if (is_int($v)) {
            if ($v === 0) return 'none';
            if ($v === 1) return 'immediate';
            if ($v >= 100) return (int)($v / 100);
        }
        return 'none';
    }
}