<?php
declare(strict_types=1);

/**
 * IcsWriter
 *
 * Pure ICS generator.
 *
 * Phase 30 (final model):
 * - Export uses the FPP system timezone (date_default_timezone_get()).
 * - DTSTART / DTEND are LOCAL wall-clock times with TZID.
 * - VTIMEZONE is emitted so Google handles DST correctly.
 * - EXDATE is emitted to represent precedence overlaps faithfully.
 */
final class IcsWriter
{
    /**
     * Generate a complete ICS calendar document.
     *
     * @param array<int,array<string,mixed>> $events Export intents
     * @return string RFC5545-compatible ICS content
     */
    public static function build(array $events): string
    {
        $tzName = date_default_timezone_get();
        $tz     = new DateTimeZone($tzName);

        $lines = [];

        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'PRODID:-//GoogleCalendarScheduler//Scheduler Export//EN';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-TIMEZONE:' . $tzName;

        $lines = array_merge($lines, self::buildVtimezone($tz));

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $lines = array_merge($lines, self::buildEventBlock($ev, $tzName));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Build a single VEVENT block.
     *
     * @param array<string,mixed> $ev
     * @param string $tzName
     * @return array<int,string>
     */
    private static function buildEventBlock(array $ev, string $tzName): array
    {
        /** @var DateTime $dtStart */
        $dtStart = $ev['dtstart'];
        /** @var DateTime $dtEnd */
        $dtEnd   = $ev['dtend'];

        $summary = (string)($ev['summary'] ?? '');
        $rrule   = $ev['rrule'] ?? null;
        $yaml    = (array)($ev['yaml'] ?? []);
        $uid     = (string)($ev['uid'] ?? '');

        /** @var array<int,DateTime> $exdates */
        $exdates = [];
        if (isset($ev['exdates']) && is_array($ev['exdates'])) {
            foreach ($ev['exdates'] as $d) {
                if ($d instanceof DateTime) {
                    $exdates[] = $d;
                }
            }
        }

        $lines = [];
        $lines[] = 'BEGIN:VEVENT';

        $lines[] = 'DTSTART;TZID=' . $tzName . ':' . $dtStart->format('Ymd\THis');
        $lines[] = 'DTEND;TZID='   . $tzName . ':' . $dtEnd->format('Ymd\THis');

        if (is_string($rrule) && $rrule !== '') {
            $lines[] = 'RRULE:' . $rrule;
        }

        // EXDATE exceptions (one per line for simplicity)
        foreach ($exdates as $ex) {
            $lines[] = 'EXDATE;TZID=' . $tzName . ':' . $ex->format('Ymd\THis');
        }

        if ($summary !== '') {
            $lines[] = 'SUMMARY:' . self::escapeText($summary);
        }

        if (!empty($yaml)) {
            $lines[] = 'DESCRIPTION:' . self::escapeText(self::yamlToText($yaml));
        }

        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'UID:' . ($uid !== '' ? $uid : self::generateUid());

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Build a practical VTIMEZONE from transitions.
     * Uses TZOFFSETFROM/TZOFFSETTO pairs based on successive transitions.
     */
    private static function buildVtimezone(DateTimeZone $tz): array
    {
        $tzName = $tz->getName();
        $lines = [
            'BEGIN:VTIMEZONE',
            'TZID:' . $tzName,
        ];

        $transitions = $tz->getTransitions();
        if (empty($transitions)) {
            $lines[] = 'END:VTIMEZONE';
            return $lines;
        }

        // Keep transitions reasonably bounded to avoid huge ICS.
        // Include transitions from (now - 1 year) to (now + 6 years).
        $now = time();
        $minTs = $now - (365 * 24 * 3600);
        $maxTs = $now + (6 * 365 * 24 * 3600);

        $prevOffset = null;

        foreach ($transitions as $t) {
            if (!isset($t['ts'], $t['offset'], $t['isdst'])) {
                continue;
            }

            $ts = (int)$t['ts'];
            if ($ts < $minTs || $ts > $maxTs) {
                $prevOffset = (int)$t['offset'];
                continue;
            }

            $currOffset = (int)$t['offset'];
            $isdst = (bool)$t['isdst'];

            // If we don't have a previous offset, approximate it with current offset
            $fromOffset = ($prevOffset !== null) ? $prevOffset : $currOffset;

            $type = $isdst ? 'DAYLIGHT' : 'STANDARD';

            $dt = (new DateTime('@' . $ts))->setTimezone($tz);

            $lines[] = 'BEGIN:' . $type;
            $lines[] = 'DTSTART:' . $dt->format('Ymd\THis');
            $lines[] = 'TZOFFSETFROM:' . self::formatOffset($fromOffset);
            $lines[] = 'TZOFFSETTO:'   . self::formatOffset($currOffset);

            if (isset($t['abbr']) && is_string($t['abbr']) && $t['abbr'] !== '') {
                $lines[] = 'TZNAME:' . self::escapeText($t['abbr']);
            }

            $lines[] = 'END:' . $type;

            $prevOffset = $currOffset;
        }

        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    private static function formatOffset(int $seconds): string
    {
        $sign = ($seconds >= 0) ? '+' : '-';
        $seconds = abs($seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        return sprintf('%s%02d%02d', $sign, $hours, $minutes);
    }

    private static function yamlToText(array $yaml): string
    {
        $out = [];
        foreach ($yaml as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $out[] = $k . ': ' . $v;
        }
        return implode("\n", $out);
    }

    private static function escapeText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace(',', '\,', $text);
        $text = str_replace(';', '\;', $text);
        return $text;
    }

    private static function generateUid(): string
    {
        return uniqid('gcs-export-', true) . '@local';
    }
}
