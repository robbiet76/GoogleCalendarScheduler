<?php
declare(strict_types=1);

final class ScheduleEntryExportAdapter
{
    /**
     * Hard clamp for RRULE window (Google import stability).
     */
    private const MAX_EXPORT_SPAN_DAYS = 366;

    /**
     * Deterministic fallback times for symbolic values
     * (display-only; real execution handled by FPP).
     */
    private const SYMBOLIC_FALLBACK_TIMES = [
        'Dawn'    => '06:00:00',
        'SunRise' => '06:00:00',
        'SunSet'  => '18:00:00',
        'Dusk'    => '18:00:00',
    ];

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

        // --------------------------------------------------
        // START / END DATE RESOLUTION
        // --------------------------------------------------

        $startDate = self::resolveDateForExport(
            (string)($entry['startDate'] ?? ''),
            $warnings,
            'startDate',
            $entry,
            null
        );

        if ($startDate === null) {
            $warnings[] = "Export: '{$summary}' unable to resolve startDate; entry skipped.";
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
            $warnings[] = "Export: '{$summary}' unable to resolve endDate; entry skipped.";
            return null;
        }

        $endDate = self::ensureEndDateNotBeforeStart($startDate, $endDate, $warnings, $summary);

        // --------------------------------------------------
        // TIMES
        // --------------------------------------------------

        $startTime = (string)($entry['startTime'] ?? '00:00:00');
        $endTime   = (string)($entry['endTime'] ?? '00:00:00');

        // DTSTART
        $dtStart = self::parseDateTime($startDate, $startTime);

        if (!$dtStart && self::looksSymbolicTime($startTime)) {
            $fallback = self::SYMBOLIC_FALLBACK_TIMES[$startTime] ?? '18:00:00';
            $warnings[] =
                "Export: {$startTime} resolved to {$fallback} for calendar display; actual time varies daily.";
            $startTime = $fallback;
            $dtStart = self::parseDateTime($startDate, $startTime);
        }

        if (!$dtStart) {
            $warnings[] = "Export: '{$summary}' invalid DTSTART after fallback; entry skipped.";
            return null;
        }

        // DTEND
        if ($endTime === '24:00:00') {
            $dtEnd = (clone $dtStart)->modify('+1 day')->setTime(0, 0, 0);
        } else {
            $dtEnd = self::parseDateTime($startDate, $endTime);

            if (!$dtEnd && self::looksSymbolicTime($endTime)) {
                $fallback = self::SYMBOLIC_FALLBACK_TIMES[$endTime] ?? '23:00:00';
                $warnings[] =
                    "Export: {$endTime} resolved to {$fallback} for calendar display; actual time varies daily.";
                $endTime = $fallback;
                $dtEnd = self::parseDateTime($startDate, $endTime);
            }
        }

        if (!$dtEnd) {
            $warnings[] = "Export: '{$summary}' invalid DTEND after fallback; entry skipped.";
            return null;
        }

        if ($dtEnd <= $dtStart) {
            $dtEnd = (clone $dtEnd)->modify('+1 day');
        }

        // --------------------------------------------------
        // RRULE
        // --------------------------------------------------

        $rrule = self::buildClampedRrule($entry, $startDate, $endDate, $warnings, $summary);

        // --------------------------------------------------
        // YAML METADATA
        // --------------------------------------------------

        $yaml = [
            'stopType' => self::stopTypeToString((int)($entry['stopType'] ?? 0)),
            'repeat'   => self::repeatToYaml($entry['repeat'] ?? 0),
        ];

        if (isset($entry['__gcs_yaml']) && is_array($entry['__gcs_yaml'])) {
            $yaml = array_merge($yaml, $entry['__gcs_yaml']);
        }

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
                $year = (int)date('Y');
                return sprintf('%04d-%s', $year, substr($raw, 5));
            }
            return $raw;
        }

        // Holiday shortName
        if ($raw !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $raw)) {
            $year = $fallback ? (int)substr($fallback, 0, 4) : (int)date('Y');
            $d = FppSemantics::dateForHoliday($raw, $year);
            if ($d !== null) {
                return $d;
            }

            $warnings[] = "Export: {$field} '{$raw}' not resolved in locale.";
        }

        // Final fallback: do NOT skip
        if ($fallback !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback)) {
            $year = substr($fallback, 0, 4);
            $warnings[] =
                "Export: {$field} '{$raw}' unresolved; using {$year}-01-01 for calendar display.";
            return "{$year}-01-01";
        }

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
            return 'FREQ=DAILY;UNTIL=' . $until;
        }

        $byDay = self::fppDayEnumToByDay($dayEnum);
        if ($byDay !== '') {
            return 'FREQ=WEEKLY;BYDAY=' . $byDay . ';UNTIL=' . $until;
        }

        return 'FREQ=DAILY;UNTIL=' . $until;
    }

    /* ===================================================================== */

    private static function parseDateTime(string $date, string $time): ?DateTime
    {
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return null;
        }
        return DateTime::createFromFormat('Y-m-d H:i:s', "{$date} {$time}") ?: null;
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