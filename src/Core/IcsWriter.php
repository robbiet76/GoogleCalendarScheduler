<?php
declare(strict_types=1);

/**
 * IcsWriter
 *
 * Phase 23.2
 *
 * Pure ICS generator.
 *
 * PURPOSE:
 * - Convert export intents into a valid RFC5545-compatible ICS document
 * - Intended for re-import into Google Calendar or similar systems
 *
 * RESPONSIBILITIES:
 * - Serialize VEVENT blocks from pre-sanitized export intents
 * - Emit minimal but valid calendar + timezone metadata
 * - Encode YAML metadata into DESCRIPTION field
 *
 * HARD RULES:
 * - No scheduler knowledge
 * - No filtering or validation
 * - No mutation of inputs
 * - Assumes all DateTime values are valid and timezone-correct
 */
final class IcsWriter
{
    /**
     * Generate a complete ICS calendar document.
     *
     * @param array<int,array<string,mixed>> $events Export intents
     * @param DateTimeZone|null $tz Timezone for DTSTART/DTEND (defaults to system TZ)
     * @return string RFC5545-compatible ICS content
     */
    public static function build(array $events, ?DateTimeZone $tz = null): string
    {
        if ($tz === null) {
            $tz = new DateTimeZone(date_default_timezone_get());
        }

        $lines = [];

        // --------------------------------------------------
        // Calendar header
        // --------------------------------------------------
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'PRODID:-//GoogleCalendarScheduler//Scheduler Export//EN';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';

        // Minimal timezone block (Google Calendar tolerant)
        $lines = array_merge($lines, self::buildTimezoneBlock($tz));

        // --------------------------------------------------
        // Events
        // --------------------------------------------------
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $lines = array_merge($lines, self::buildEventBlock($ev, $tz));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /* ==========================================================
     * VEVENT generation
     * ========================================================== */

    /**
     * Build a single VEVENT block.
     *
     * @param array<string,mixed> $ev Export intent
     * @param DateTimeZone        $tz Timezone used for DTSTART/DTEND
     * @return array<int,string> RFC5545 VEVENT lines
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

        // DTSTART / DTEND with explicit TZID
        $lines[] = 'DTSTART;TZID=' . $tz->getName() . ':' . $dtStart->format('Ymd\THis');
        $lines[] = 'DTEND;TZID='   . $tz->getName() . ':' . $dtEnd->format('Ymd\THis');

        // RRULE (optional)
        if (is_string($rrule) && $rrule !== '') {
            $lines[] = 'RRULE:' . $rrule;
        }

        // SUMMARY
        if ($summary !== '') {
            $lines[] = 'SUMMARY:' . self::escapeText($summary);
        }

        // DESCRIPTION (embedded YAML metadata)
        if (!empty($yaml)) {
            $lines[] = 'DESCRIPTION:' . self::escapeText(self::yamlToText($yaml));
        }

        // Required metadata
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'UID:' . self::generateUid();

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /* ==========================================================
     * Calendar helpers
     * ========================================================== */

    /**
     * Build a minimal VTIMEZONE block.
     *
     * NOTE:
     * - Google Calendar accepts simplified timezone definitions
     *   as long as TZID matches DTSTART/DTEND.
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
     * Convert YAML metadata array into plain-text block.
     *
     * @param array<string,mixed> $yaml
     * @return string
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
     * Escape text for inclusion in ICS fields (RFC5545).
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
     * Generate a unique UID for exported events.
     *
     * NOTE:
     * - UID stability is not required for export use-case
     * - Google Calendar will normalize or replace as needed
     */
    private static function generateUid(): string
    {
        return uniqid('gcs-export-', true) . '@local';
    }
}
