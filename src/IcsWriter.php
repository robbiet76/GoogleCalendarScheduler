<?php
declare(strict_types=1);

/**
 * IcsWriter
 *
 * Phase 23.2
 *
 * Pure ICS generator.
 *
 * Responsibilities:
 * - Convert export intents into a valid ICS string
 * - RFC5545-compatible
 * - Google Calendar–friendly
 *
 * HARD RULES:
 * - No scheduler knowledge
 * - No filtering or validation
 * - Assumes inputs are already sanitized
 */
final class IcsWriter
{
    /**
     * Generate an ICS calendar from export intents.
     *
     * @param array<int,array<string,mixed>> $events
     * @param DateTimeZone|null $tz
     * @return string
     */
    public static function build(array $events, ?DateTimeZone $tz = null): string
    {
        if ($tz === null) {
            $tz = new DateTimeZone(date_default_timezone_get());
        }

        $lines = [];

        // Calendar header
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'PRODID:-//GoogleCalendarScheduler//Scheduler Export//EN';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';

        // Timezone block (minimal, Google-tolerant)
        $lines = array_merge($lines, self::buildTimezoneBlock($tz));

        // Events
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            $lines = array_merge($lines, self::buildEventBlock($ev, $tz));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /* ------------------------------------------------------------------ */

    /**
     * Build a VEVENT block.
     *
     * @param array<string,mixed> $ev
     * @param DateTimeZone $tz
     * @return array<int,string>
     */
    private static function buildEventBlock(array $ev, DateTimeZone $tz): array
    {
        /** @var DateTime $dtStart */
        $dtStart = $ev['dtstart'];
        /** @var DateTime $dtEnd */
        $dtEnd   = $ev['dtend'];

        $summary = (string)($ev['summary'] ?? '');
        $rrule   = $ev['rrule'] ?? null;
        $yaml    = (array)($ev['yaml'] ?? []);

        $lines = [];
        $lines[] = 'BEGIN:VEVENT';

        // DTSTART / DTEND with TZID
        $lines[] = 'DTSTART;TZID=' . $tz->getName() . ':' . $dtStart->format('Ymd\THis');
        $lines[] = 'DTEND;TZID='   . $tz->getName() . ':' . $dtEnd->format('Ymd\THis');

        // RRULE (if any)
        if (is_string($rrule) && $rrule !== '') {
            $lines[] = 'RRULE:' . $rrule;
        }

        // SUMMARY
        if ($summary !== '') {
            $lines[] = 'SUMMARY:' . self::escapeText($summary);
        }

        // DESCRIPTION (YAML block)
        if (!empty($yaml)) {
            $lines[] = 'DESCRIPTION:' . self::escapeText(self::yamlToText($yaml));
        }

        // Required metadata
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'UID:' . self::generateUid();

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Build a minimal timezone definition.
     *
     * Google accepts simplified TZ blocks when TZID matches.
     */
    private static function buildTimezoneBlock(DateTimeZone $tz): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:' . $tz->getName(),
            'END:VTIMEZONE',
        ];
    }

    /**
     * Convert YAML array into plain-text block.
     *
     * @param array<string,mixed> $yaml
     */
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

    /**
     * Escape ICS text per RFC5545.
     */
    private static function escapeText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace(',', '\,', $text);
        $text = str_replace(';', '\;', $text);
        return $text;
    }

    /**
     * Generate a simple UID.
     *
     * Google will replace or normalize as needed.
     */
    private static function generateUid(): string
    {
        return uniqid('gcs-export-', true) . '@local';
    }
}
