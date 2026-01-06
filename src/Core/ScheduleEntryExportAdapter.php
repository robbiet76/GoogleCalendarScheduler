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
            $warnings[] = 'Export skip: entry has no playlist or command name';
            return null;
        }

        // Debug header for this entry
        $warnings[] = self::dbg($summary, 'BEGIN', [
            'raw.startDate' => $entry['startDate'] ?? null,
            'raw.endDate'   => $entry['endDate'] ?? null,
            'raw.startTime' => $entry['startTime'] ?? null,
            'raw.endTime'   => $entry['endTime'] ?? null,
            'day'           => $entry['day'] ?? null,
            'repeat'        => $entry['repeat'] ?? null,
            'stopType'      => $entry['stopType'] ?? null,
        ]);

        // ---- START / END DATE RESOLUTION ----

        $startDate = self::resolveDateForExport(
            (string)($entry['startDate'] ?? ''),
            $warnings,
            'startDate',
            $entry,
            null,
            $summary
        );

        if ($startDate === null) {
            $warnings[] = self::dbg($summary, 'SKIP', ['reason' => 'unable to resolve startDate']);
            return null;
        }

        $endDate = self::resolveDateForExport(
            (string)($entry['endDate'] ?? ''),
            $warnings,
            'endDate',
            $entry,
            $startDate,
            $summary
        );

        if ($endDate === null) {
            $warnings[] = self::dbg($summary, 'SKIP', ['reason' => 'unable to resolve endDate', 'startDate' => $startDate]);
            return null;
        }

        // If we resolved something like Thanksgiving (Nov) to Epiphany (Jan) and got the wrong year,
        // bump end year forward until end >= start (best-effort).
        $endDate = self::ensureEndDateNotBeforeStart($startDate, $endDate, $warnings, $summary);

        // ---- TIMES ----

        $startTime = (string)($entry['startTime'] ?? '00:00:00');
        $endTime   = (string)($entry['endTime'] ?? '00:00:00');

        // DTSTART
        $dtStart = self::parseDateTime($startDate, $startTime);

        // Safety net: resolve symbolic times if they still exist here.
        if (!$dtStart && self::looksSymbolicTime($startTime)) {
            $resolved = FppSemantics::resolveSymbolicTime(
                $startDate,
                $startTime,
                (int)($entry['startTimeOffset'] ?? 0),
                $warnings
            );
            if (is_array($resolved) && isset($resolved['displayTime'])) {
                $startTime = (string)$resolved['displayTime'];
                $dtStart = self::parseDateTime($startDate, $startTime);
                $warnings[] = self::dbg($summary, 'INFO', ['startTime.resolved' => $startTime]);
            }
        }

        if (!$dtStart) {
            $warnings[] = self::dbg($summary, 'SKIP', [
                'reason' => 'invalid DTSTART after resolution attempts',
                'startDate' => $startDate,
                'startTime' => $startTime,
            ]);
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
                    $endTime = (string)$resolved['displayTime'];
                    $dtEnd = self::parseDateTime($startDate, $endTime);
                    $warnings[] = self::dbg($summary, 'INFO', ['endTime.resolved' => $endTime]);
                }
            }
        }

        if (!$dtEnd) {
            $warnings[] = self::dbg($summary, 'SKIP', [
                'reason' => 'invalid DTEND after resolution attempts',
                'startDate' => $startDate,
                'endTime'   => $endTime,
            ]);
            return null;
        }

        // If dtEnd <= dtStart, treat as crossing midnight.
        if ($dtEnd <= $dtStart) {
            $dtEnd = (clone $dtEnd)->modify('+1 day');
        }

        // ---- RRULE ----
        $rrule = self::buildClampedRrule($entry, $startDate, $endDate, $warnings, $summary);

        // ---- YAML ----
        $yaml = [
            'stopType' => self::stopTypeToString((int)($entry['stopType'] ?? 0)),
            'repeat'   => self::repeatToYaml($entry['repeat'] ?? 0),
        ];

        if (isset($entry['__gcs_yaml']) && is_array($entry['__gcs_yaml'])) {
            $yaml = array_merge($yaml, $entry['__gcs_yaml']);
        }

        $warnings[] = self::dbg($summary, 'EXPORT', [
            'resolved.startDate' => $startDate,
            'resolved.endDate'   => $endDate,
            'final.DTSTART'      => $dtStart->format('Y-m-d H:i:s'),
            'final.DTEND'        => $dtEnd->format('Y-m-d H:i:s'),
            'RRULE'              => $rrule,
        ]);

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
        ?string $fallback,
        string $summary
    ): ?string {
        $raw = trim($raw);

        // YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            if (strpos($raw, '0000-') === 0) {
                // For "0000-.." use current year (export display only).
                $year = (int)date('Y');
                $resolved = sprintf('%04d-%s', $year, substr($raw, 5));
                $warnings[] = self::dbg($summary, 'INFO', ["{$field}.0000" => "{$raw} -> {$resolved}"]);
                return $resolved;
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
                $warnings[] = self::dbg($summary, 'INFO', ["{$field}.holiday" => "{$raw}({$year}) -> {$d}"]);
                return $d;
            }

            $warnings[] = self::dbg($summary, 'WARN', [
                'reason' => "{$field} holiday not resolved in current locale",
                "{$field}.raw" => $raw,
                'hintYear' => $year,
            ]);
        }

        // Fallback to startDate (resolved) if we must
        if ($fallback !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback)) {
            $warnings[] = self::dbg($summary, 'WARN', [
                'reason' => "{$field} unresolved; clamped to fallback",
                "{$field}.raw" => $raw,
                'fallback' => $fallback,
            ]);
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

        if ($endDate < $startDate) {
            $y = (int)substr($endDate, 0, 4);
            $mmdd = substr($endDate, 5);

            for ($i = 0; $i < 2; $i++) {
                $y++;
                $candidate = sprintf('%04d-%s', $y, $mmdd);
                if ($candidate >= $startDate) {
                    $warnings[] = self::dbg($summary, 'WARN', [
                        'reason' => 'endDate adjusted across year boundary',
                        'endDate.before' => $endDate,
                        'endDate.after'  => $candidate,
                    ]);
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
                $warnings[] = self::dbg($summary, 'WARN', [
                    'reason' => 'RRULE endDate exceeds clamp window',
                    'endDate.before' => $endDate,
                    'endDate.after'  => $maxEnd->format('Y-m-d'),
                    'maxDays' => self::MAX_EXPORT_SPAN_DAYS,
                ]);
                $endDate = $maxEnd->format('Y-m-d');
            }

            if ($endDate < $startDate) {
                $warnings[] = self::dbg($summary, 'WARN', [
                    'reason' => 'RRULE endDate < startDate after clamp; forcing to startDate',
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                ]);
                $endDate = $startDate;
            }
        }

        // UNTIL: floating local UNTIL
        $untilLocal = str_replace('-', '', $endDate) . 'T235959';

        $dayEnum = (int)($entry['day'] ?? -1);

        if ($dayEnum === 7) {
            return 'FREQ=DAILY;UNTIL=' . $untilLocal;
        }

        $byDay = self::fppDayEnumToByDay($dayEnum);
        if ($byDay !== '') {
            return 'FREQ=WEEKLY;BYDAY=' . $byDay . ';UNTIL=' . $untilLocal;
        }

        $warnings[] = self::dbg($summary, 'WARN', [
            'reason' => 'unknown day enum; using DAILY',
            'dayEnum' => $dayEnum,
        ]);

        return 'FREQ=DAILY;UNTIL=' . $untilLocal;
    }

    /* ===================================================================== */

    private static function parseDateTime(string $date, string $time): ?DateTime
    {
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

    private static function dbg(string $summary, string $tag, array $kv = []): string
    {
        // Single-line, grep-friendly debug string in warnings
        $parts = [];
        foreach ($kv as $k => $v) {
            if (is_array($v)) $v = json_encode($v);
            if (is_bool($v)) $v = $v ? 'true' : 'false';
            if ($v === null) $v = 'null';
            $parts[] = $k . '=' . $v;
        }
        return '[export:' . $tag . '] ' . $summary . (empty($parts) ? '' : ' | ' . implode(' ', $parts));
    }
}