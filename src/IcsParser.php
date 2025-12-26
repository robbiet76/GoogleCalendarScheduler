<?php

class GcsIcsParser
{
    /**
     * Parse raw ICS into structured event records.
     *
     * IMPORTANT (Phase 16.1 timezone rule):
     * - Times in Google Calendar should match local times in FPP.
     * - Therefore, all parsed DateTime values are normalized to the local timezone
     *   (date_default_timezone_get()) before formatting into strings.
     *
     * @param string        $ics
     * @param DateTime|null $now
     * @param DateTime      $horizonEnd
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $ics, ?DateTime $now, DateTime $horizonEnd): array
    {
        $ics = str_replace("\r\n", "\n", $ics);
        $lines = explode("\n", $ics);

        // Unfold lines (RFC5545)
        $unfolded = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            if (!empty($unfolded) && (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t"))) {
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
            } else {
                $unfolded[] = $line;
            }
        }

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

                $uid          = null;
                $summary      = '';
                $dtstart      = null;
                $dtend        = null;
                $isAllDay     = false;
                $rrule        = null;
                $exDates      = [];
                $recurrenceId = null;

                if (preg_match('/UID:(.+)/', $raw, $m)) {
                    $uid = trim($m[1]);
                }

                if (preg_match('/SUMMARY:(.+)/', $raw, $m)) {
                    $summary = trim($m[1]);
                }

                if (preg_match('/DTSTART([^:]*):(.+)/', $raw, $m)) {
                    [$dtstart, $isAllDay] = $this->parseDateWithFlags($m[2], $m[1]);
                }

                if (preg_match('/DTEND([^:]*):(.+)/', $raw, $m)) {
                    [$dtend, $isAllDayEnd] = $this->parseDateWithFlags($m[2], $m[1]);
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
                    $recurrenceId = $this->parseDate($m[2], $m[1]);
                }

                // Skip malformed events
                if (!$uid || !$dtstart || !$dtend) {
                    continue;
                }

                /**
                 * Phase 13.3:
                 * - Non-recurring events that ended before "now" can be dropped.
                 * - Recurring series MUST NOT be dropped here just because
                 *   base DTSTART/DTEND is in the past.
                 */
                if ($now && $dtend < $now && empty($rrule)) {
                    continue;
                }

                $events[] = [
                    'uid'          => $uid,
                    'summary'      => $summary,
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
                continue;
            }

            if ($inEvent) {
                $raw .= $line . "\n";
            }
        }

        return $events;
    }

    /* ----------------------------------------------------------------- */

    private function parseDateWithFlags(string $value, string $params): array
    {
        $isAllDay = str_contains($params, 'VALUE=DATE');
        $dt = $this->parseDate($value, $params);

        if ($dt && $isAllDay) {
            $dt = $dt->setTime(0, 0, 0);
        }

        return [$dt, $isAllDay];
    }

    /**
     * Parse an ICS datetime/date and normalize it to local (FPP) timezone.
     *
     * Supported forms:
     * - YYYYMMDDTHHMMSSZ     => UTC instant, then converted to local timezone
     * - YYYYMMDDTHHMMSS      => interpreted in TZID if supplied, else local timezone
     * - YYYYMMDD             => date-only interpreted in local timezone
     *
     * @param string $raw
     * @param string $params
     * @return DateTime|null
     */
    private function parseDate(string $raw, string $params = ''): ?DateTime
    {
        try {
            $localTz = new DateTimeZone(date_default_timezone_get());
            $paramTz = $this->extractTzid($params);
            $eventTz = $paramTz ? new DateTimeZone($paramTz) : $localTz;

            // UTC form
            if (preg_match('/^\d{8}T\d{6}Z$/', $raw)) {
                $dt = DateTime::createFromFormat(
                    'Ymd\THis\Z',
                    $raw,
                    new DateTimeZone('UTC')
                );
                if (!$dt) {
                    return null;
                }
                // Normalize to local wall-clock time
                $dt->setTimezone($localTz);
                return $dt;
            }

            // Floating/localized time form (TZID or local)
            if (preg_match('/^\d{8}T\d{6}$/', $raw)) {
                $dt = DateTime::createFromFormat('Ymd\THis', $raw, $eventTz);
                if (!$dt) {
                    return null;
                }
                // Normalize to local wall-clock time
                $dt->setTimezone($localTz);
                return $dt;
            }

            // Date-only
            if (preg_match('/^\d{8}$/', $raw)) {
                $dt = DateTime::createFromFormat('Ymd', $raw, $localTz);
                if (!$dt) {
                    return null;
                }
                $dt->setTimezone($localTz);
                return $dt;
            }
        } catch (Throwable $ignored) {
        }

        return null;
    }

    /**
     * Extract TZID from ICS params, e.g. ";TZID=America/New_York" or "TZID=America/New_York"
     *
     * @param string $params
     * @return string|null
     */
    private function extractTzid(string $params): ?string
    {
        if ($params === '') {
            return null;
        }

        // Common patterns include leading ';' or other params before TZID
        if (preg_match('/TZID=([^;:]+)/', $params, $m)) {
            $tzid = trim($m[1]);
            return $tzid !== '' ? $tzid : null;
        }

        return null;
    }

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

    private function parseExDates(string $raw, string $params): array
    {
        $dates = [];
        foreach (explode(',', trim($raw)) as $val) {
            $dt = $this->parseDate($val, $params);
            if ($dt) {
                $dates[] = $dt;
            }
        }
        return $dates;
    }
}
