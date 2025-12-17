<?php

class GcsIcsParser
{
    /**
     * Parse ICS and return expanded VEVENT occurrences
     */
    public static function parse(string $ics, ?DateTime $now = null, ?DateTime $horizon = null): array
    {
        $now     = $now     ?: new DateTime('now');
        $horizon = $horizon ?: (clone $now)->modify('+30 days');

        $lines = self::unfold($ics);
        $events = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = [];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($current) {
                    $events = array_merge(
                        $events,
                        self::expandEvent($current, $now, $horizon)
                    );
                }
                $current = null;
                continue;
            }
            if ($current !== null && strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $current[$k] = $v;
            }
        }

        return $events;
    }

    /**
     * Expand a single VEVENT with RRULE support (WEEKLY + WKST)
     */
    private static function expandEvent(array $ev, DateTime $now, DateTime $horizon): array
    {
        if (empty($ev['DTSTART'])) {
            return [];
        }

        $dtStart = self::parseDate($ev['DTSTART']);
        $dtEnd   = isset($ev['DTEND'])
            ? self::parseDate($ev['DTEND'])
            : (clone $dtStart)->modify('+1 hour');

        $duration = $dtStart->diff($dtEnd);
        $uid = $ev['UID'] ?? null;
        $summary = $ev['SUMMARY'] ?? '';
        $desc = $ev['DESCRIPTION'] ?? null;

        // No RRULE → single event
        if (empty($ev['RRULE'])) {
            if ($dtEnd < $now || $dtStart > $horizon) {
                return [];
            }
            return [[
                'uid' => $uid,
                'summary' => $summary,
                'description' => $desc,
                'start' => $dtStart,
                'end' => $dtEnd,
            ]];
        }

        // Parse RRULE
        $rule = [];
        foreach (explode(';', $ev['RRULE']) as $part) {
            if (strpos($part, '=') !== false) {
                [$k, $v] = explode('=', $part, 2);
                $rule[$k] = $v;
            }
        }

        if (($rule['FREQ'] ?? null) !== 'WEEKLY') {
            return [];
        }

        $byDays = isset($rule['BYDAY'])
            ? explode(',', $rule['BYDAY'])
            : [];

        $wkst = $rule['WKST'] ?? 'MO';

        // Align cursor to NOW or DTSTART, whichever is later
        $cursor = clone $dtStart;
        if ($cursor < $now) {
            $cursor = clone $now;
        }

        // Align cursor to correct WKST-based week
        $weekStart = self::alignToWeekStart($cursor, $wkst);
        $weekEnd   = (clone $weekStart)->modify('+6 days');

        // Skip weeks fully before now
        while ($weekEnd < $now) {
            $weekStart->modify('+1 week');
            $weekEnd = (clone $weekStart)->modify('+6 days');
        }

        $results = [];

        while ($weekStart <= $horizon) {
            foreach ($byDays as $day) {
                $occ = self::weekdayInWeek($weekStart, $day);
                if (!$occ) {
                    continue;
                }
                if ($occ < $dtStart || $occ < $now || $occ > $horizon) {
                    continue;
                }

                $end = (clone $occ)->add($duration);

                $results[] = [
                    'uid' => $uid,
                    'summary' => $summary,
                    'description' => $desc,
                    'start' => clone $occ,
                    'end' => $end,
                ];
            }

            $weekStart->modify('+1 week');
        }

        return $results;
    }

    /**
     * Align a date to the start of its week per WKST
     */
    private static function alignToWeekStart(DateTime $dt, string $wkst): DateTime
    {
        $map = ['SU'=>0,'MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6];
        $wkstNum = $map[$wkst] ?? 1;

        $cur = (int)$dt->format('w');
        $delta = ($cur - $wkstNum + 7) % 7;

        return (clone $dt)->modify("-{$delta} days")->setTime(0,0,0);
    }

    /**
     * Return weekday DateTime within a given week
     */
    private static function weekdayInWeek(DateTime $weekStart, string $day): ?DateTime
    {
        $map = ['SU'=>0,'MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6];
        if (!isset($map[$day])) {
            return null;
        }

        return (clone $weekStart)->modify('+' . $map[$day] . ' days')
                                 ->setTime(
                                     (int)$weekStart->format('H'),
                                     (int)$weekStart->format('i'),
                                     (int)$weekStart->format('s')
                                 );
    }

    /**
     * Parse DTSTART / DTEND with TZ support
     */
    private static function parseDate(string $raw): DateTime
    {
        if (preg_match('/TZID=([^:]+):(.+)/', $raw, $m)) {
            return new DateTime($m[2], new DateTimeZone($m[1]));
        }
        return new DateTime($raw);
    }

    /**
     * RFC 5545 line unfolding
     */
    private static function unfold(string $ics): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $ics);
        $out = [];

        foreach ($lines as $line) {
            if (isset($out[count($out)-1]) && preg_match('/^[ \t]/', $line)) {
                $out[count($out)-1] .= ltrim($line);
            } else {
                $out[] = rtrim($line);
            }
        }
        return $out;
    }
}
