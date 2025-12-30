<?php
declare(strict_types=1);

/**
 * GcsIcsParser
 *
 * Low-level ICS parser responsible for converting raw ICS text into
 * normalized calendar event records suitable for scheduler planning.
 *
 * RESPONSIBILITIES:
 * - Unfold RFC5545 lines
 * - Detect calendar timezone (X-WR-TIMEZONE)
 * - Parse VEVENT blocks
 * - Normalize all timestamps into FPP system timezone
 * - Extract recurrence rules, overrides, and exclusions
 *
 * NON-GOALS:
 * - No scheduler knowledge
 * - No horizon trimming logic beyond basic end-time filtering
 * - No intent consolidation
 * - No side effects outside logging
 *
 * TIMEZONE RULES:
 * - Calendar timezone is derived from X-WR-TIMEZONE if present
 * - DTSTART/DTEND TZID parameters are honored
 * - All resulting DateTime values are converted to FPP system timezone
 */
final class GcsIcsParser
{
    /** Calendar-declared timezone (may be null until detected) */
    private ?DateTimeZone $calendarTz = null;

    /** FPP system timezone (authoritative for scheduler execution) */
    private DateTimeZone $fppTz;

    public function __construct()
    {
        // FPP system timezone (authoritative)
        $this->fppTz = new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Parse raw ICS content into structured event records.
     *
     * @param string        $ics        Raw ICS text
     * @param DateTime|null $now        Optional "now" for pruning expired non-recurring events
     * @param DateTime      $horizonEnd Upper bound used by caller (not enforced here)
     *
     * @return array<int,array<string,mixed>> Normalized event records
     */
    public function parse(string $ics, ?DateTime $now, DateTime $horizonEnd): array
    {
        // Normalize newlines
        $ics = str_replace("\r\n", "\n", $ics);
        $lines = explode("\n", $ics);

        // --------------------------------------------------
        // RFC5545 line unfolding
        // --------------------------------------------------
        $unfolded = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }

            if (!empty($unfolded) && ($line[0] === ' ' || $line[0] === "\t")) {
                // Continuation line
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
            } else {
                $unfolded[] = $line;
            }
        }

        // --------------------------------------------------
        // Detect calendar timezone (X-WR-TIMEZONE)
        // --------------------------------------------------
        foreach ($unfolded as $line) {
            if (preg_match('/^X-WR-TIMEZONE:(.+)$/', $line, $m)) {
                try {
                    $this->calendarTz = new DateTimeZone(trim($m[1]));
                } catch (Throwable) {
                    $this->calendarTz = null;
                }
                break;
            }
        }

        if (!$this->calendarTz) {
            // Fallback to FPP timezone if calendar does not declare one
            $this->calendarTz = $this->fppTz;
            GcsLogger::instance()->warn(
                'ICS calendar timezone missing; defaulting to FPP timezone',
                ['fpp_tz' => $this->fppTz->getName()]
            );
        }

        // --------------------------------------------------
        // VEVENT parsing
        // --------------------------------------------------
        $events = [];
        $inEvent = false;
        $raw = '';

        foreach ($unfolded as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $inEvent = true;
                $raw = '';
                continue;
            }

            if ($line === 'END:VEVENT') {
                $inEvent = false;

                $uid            = null;
                $summary        = '';
                $description    = null;
                $dtstart        = null;
                $dtend          = null;
                $isAllDay       = false;
                $rrule          = null;
                $exDates        = [];
                $recurrenceId   = null;

                if (preg_match('/UID:(.+)/', $raw, $m)) {
                    $uid = trim($m[1]);
                }

                if (preg_match('/SUMMARY:(.+)/', $raw, $m)) {
                    $summary = trim($m[1]);
                }

                if (preg_match('/DESCRIPTION:(.+)/s', $raw, $m)) {
                    // Normalize Google Calendar literal "\n"
                    $description = str_replace('\n', "\n", trim($m[1]));
                }

                if (preg_match('/DTSTART([^:]*):(.+)/', $raw, $m)) {
                    [$dtstart, $isAllDay] = $this->parseDateWithTimezone($m[2], $m[1]);
                }

                if (preg_match('/DTEND([^:]*):(.+)/', $raw, $m)) {
                    [$dtend, $isAllDayEnd] = $this->parseDateWithTimezone($m[2], $m[1]);
                    $isAllDay = $isAllDay || $isAllDayEnd;
                }

                if (preg_match('/RRULE:(.+)/', $raw, $m)) {
                    $rrule = $this->parseRrule($m[1]);
                }

                if (preg_match_all('/EXDATE([^:]*):([^\r\n]+)/', $raw, $m, PREG_SET_ORDER)) {
                    foreach ($m as $ex) {
                        $exDates = array_merge(
                            $exDates,
                            $this->parseExDates($ex[2], $ex[1])
                        );
                    }
                }

                if (preg_match('/RECURRENCE-ID([^:]*):(.+)/', $raw, $m)) {
                    $recurrenceId = $this->parseDateWithTimezone($m[2], $m[1])[0];
                }

                // Minimal validity check
                if (!$uid || !$dtstart || !$dtend) {
                    continue;
                }

                // Skip fully-expired non-recurring events
                if ($now && $dtend < $now && empty($rrule)) {
                    continue;
                }

                $events[] = [
                    'uid'          => $uid,
                    'summary'      => $summary,
                    'description'  => $description,
                    'start'        => $dtstart->format('Y-m-d H:i:s'),
                    'end'          => $dtend->format('Y-m-d H:i:s'),
                    'isAllDay'     => $isAllDay,
                    'rrule'        => $rrule,
                    'exDates'      => array_map(
                        fn(DateTime $d) => $d->format('Y-m-d H:i:s'),
                        $exDates
                    ),
                    'recurrenceId' => $recurrenceId
                        ? $recurrenceId->format('Y-m-d H:i:s')
                        : null,
                    'isOverride'   => ($recurrenceId !== null),
                ];
            }

            if ($inEvent) {
                $raw .= $line . "\n";
            }
        }

        return $events;
    }

    /* ==========================================================
     * Helpers
     * ========================================================== */

    /**
     * Parse DTSTART / DTEND values with timezone normalization.
     *
     * @return array{0:?DateTime,1:bool} DateTime (or null) and all-day flag
     */
    private function parseDateWithTimezone(string $value, string $params): array
    {
        $isAllDay = str_contains($params, 'VALUE=DATE');
        $tz = $this->extractTimezone($params, $value);

        try {
            if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
                $dt = DateTime::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
            } elseif (preg_match('/^\d{8}T\d{6}$/', $value)) {
                $dt = DateTime::createFromFormat('Ymd\THis', $value, $tz);
            } elseif (preg_match('/^\d{8}$/', $value)) {
                $dt = DateTime::createFromFormat('Ymd', $value, $tz);
            } else {
                return [null, false];
            }

            // Normalize into FPP timezone
            $dt->setTimezone($this->fppTz);

            if ($isAllDay) {
                $dt->setTime(0, 0, 0);
            }

            return [$dt, $isAllDay];
        } catch (Throwable) {
            return [null, false];
        }
    }

    /**
     * Resolve timezone from DTSTART/DTEND parameters.
     */
    private function extractTimezone(string $params, string $value): DateTimeZone
    {
        if (preg_match('/TZID=([^;:]+)/', $params, $m)) {
            try {
                return new DateTimeZone($m[1]);
            } catch (Throwable) {}
        }

        if (str_ends_with($value, 'Z')) {
            return new DateTimeZone('UTC');
        }

        return $this->calendarTz ?? $this->fppTz;
    }

    /**
     * Parse RRULE string into key/value array.
     */
    private function parseRrule(string $raw): array
    {
        $out = [];
        foreach (explode(';', trim($raw)) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $out[strtoupper($k)] = $v;
            }
        }
        return $out;
    }

    /**
     * Parse EXDATE values into DateTime list.
     *
     * @return array<int,DateTime>
     */
    private function parseExDates(string $raw, string $params): array
    {
        $dates = [];
        foreach (explode(',', trim($raw)) as $val) {
            [$dt] = $this->parseDateWithTimezone($val, $params);
            if ($dt) {
                $dates[] = $dt;
            }
        }
        return $dates;
    }
}

