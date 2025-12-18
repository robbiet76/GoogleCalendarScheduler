<?php

class IcsParser
{
    /**
     * Parse ICS into an array of VEVENTs with recurrence metadata.
     *
     * Output event shape (strings for start/end to preserve current pipeline):
     * [
     *   'uid'          => string|null,
     *   'summary'      => string,
     *   'start'        => 'Y-m-d H:i:s',
     *   'end'          => 'Y-m-d H:i:s',
     *   'isAllDay'     => bool,
     *   'rrule'        => array|null,      // ['FREQ'=>'DAILY','COUNT'=>'10',...]
     *   'exDates'      => array<int,string>,// ['Y-m-d H:i:s', ...]
     *   'recurrenceId' => string|null,     // 'Y-m-d H:i:s'
     *   'isOverride'   => bool
     * ]
     */
    public function parse(string $ics, ?DateTime $now, DateTime $horizonEnd): array
    {
        $events = [];

        if ($ics === '') {
            return $events;
        }

        $ics = $this->unfoldLines($ics);

        // Extract VEVENT blocks
        if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $matches)) {
            return $events;
        }

        $localTz = new DateTimeZone(date_default_timezone_get());

        foreach ($matches[1] as $raw) {
            $lines = preg_split("/\r\n|\n|\r/", trim($raw));
            if (!$lines) {
                continue;
            }

            // Gather properties (handle repeats like EXDATE)
            $props = $this->parseLinesToProps($lines);

            $summary = isset($props['SUMMARY'][0]['value']) ? trim((string)$props['SUMMARY'][0]['value']) : '';
            $uid     = isset($props['UID'][0]['value']) ? trim((string)$props['UID'][0]['value']) : null;

            // DTSTART (required)
            $dtStartInfo = $props['DTSTART'][0] ?? null;
            if (!$dtStartInfo) {
                continue;
            }
            $dtstartObj = $this->parseIcsDateValue($dtStartInfo['value'], $dtStartInfo['params'] ?? [], $localTz);
            if (!$dtstartObj) {
                continue;
            }

            $isAllDay = $this->isAllDayValue($dtStartInfo['value'], $dtStartInfo['params'] ?? []);

            // DTEND (optional in some ICS; for all-day, Google usually provides)
            $dtendObj = null;
            if (isset($props['DTEND'][0])) {
                $dtEndInfo = $props['DTEND'][0];
                $dtendObj = $this->parseIcsDateValue($dtEndInfo['value'], $dtEndInfo['params'] ?? [], $localTz);
            } else {
                // Fallback: if DTEND missing, infer a reasonable end:
                // - all-day => next day at 00:00
                // - timed   => +30 minutes (safe default)
                $dtendObj = clone $dtstartObj;
                if ($isAllDay) {
                    $dtendObj->modify('+1 day');
                } else {
                    $dtendObj->modify('+30 minutes');
                }
            }

            if (!$dtendObj) {
                continue;
            }

            // Horizon filtering (based on actual DTSTART/DTEND of this VEVENT)
            if ($now && $dtendObj < $now) {
                continue;
            }
            if ($dtstartObj > $horizonEnd) {
                continue;
            }

            // RRULE (optional)
            $rrule = null;
            if (isset($props['RRULE'][0]['value'])) {
                $rrule = $this->parseRrule((string)$props['RRULE'][0]['value']);
            }

            // EXDATE (optional, may appear multiple times and may contain multiple comma-separated values)
            $exDates = [];
            if (isset($props['EXDATE'])) {
                foreach ($props['EXDATE'] as $ex) {
                    $vals = $this->splitCsvValues((string)($ex['value'] ?? ''));
                    foreach ($vals as $v) {
                        $dt = $this->parseIcsDateValue($v, $ex['params'] ?? [], $localTz);
                        if ($dt) {
                            $exDates[] = $dt->format('Y-m-d H:i:s');
                        }
                    }
                }
                // Ensure deterministic ordering
                sort($exDates);
                $exDates = array_values(array_unique($exDates));
            }

            // RECURRENCE-ID (override indicator)
            $recurrenceId = null;
            $isOverride = false;
            if (isset($props['RECURRENCE-ID'][0])) {
                $ri = $props['RECURRENCE-ID'][0];
                $ridObj = $this->parseIcsDateValue((string)$ri['value'], $ri['params'] ?? [], $localTz);
                if ($ridObj) {
                    $recurrenceId = $ridObj->format('Y-m-d H:i:s');
                    $isOverride = true;
                }
            }

            $events[] = [
                'uid'          => $uid,
                'summary'      => $summary,
                'start'        => $dtstartObj->format('Y-m-d H:i:s'),
                'end'          => $dtendObj->format('Y-m-d H:i:s'),
                'isAllDay'     => $isAllDay,
                'rrule'        => $rrule,
                'exDates'      => $exDates,
                'recurrenceId' => $recurrenceId,
                'isOverride'   => $isOverride,
            ];
        }

        return $events;
    }

    /**
     * Unfold ICS lines: lines that start with SPACE or TAB are continuations.
     */
    private function unfoldLines(string $ics): string
    {
        // Normalize newlines first, then unfold continuations
        $ics = str_replace("\r\n", "\n", $ics);
        $ics = str_replace("\r", "\n", $ics);

        // Replace "\n " or "\n\t" with "" (continuation)
        $ics = preg_replace("/\n[ \t]/", "", $ics);

        return $ics ?? '';
    }

    /**
     * Parse VEVENT lines into a property map:
     * $props['DTSTART'][] = ['value'=>..., 'params'=>[...]]
     */
    private function parseLinesToProps(array $lines): array
    {
        $props = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }

            // Split on first ":" into left(name+params) and right(value)
            [$left, $value] = explode(':', $line, 2);

            $parts = explode(';', $left);
            $name = strtoupper(trim(array_shift($parts)));

            $params = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '' || strpos($p, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $p, 2);
                $k = strtoupper(trim($k));
                $v = trim($v);

                // Strip surrounding quotes
                if (strlen($v) >= 2 && $v[0] === '"' && $v[strlen($v) - 1] === '"') {
                    $v = substr($v, 1, -1);
                }

                $params[$k] = $v;
            }

            if (!isset($props[$name])) {
                $props[$name] = [];
            }

            $props[$name][] = [
                'value'  => $value,
                'params' => $params,
            ];
        }

        return $props;
    }

    /**
     * Determine if DTSTART indicates an all-day event.
     */
    private function isAllDayValue(string $rawValue, array $params): bool
    {
        $v = trim($rawValue);

        if (isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') {
            return true;
        }

        // Plain YYYYMMDD
        if (preg_match('/^\d{8}$/', $v)) {
            return true;
        }

        return false;
    }

    /**
     * Parse an ICS date/time value into a DateTime in local timezone.
     *
     * Supports:
     *  - YYYYMMDD (all-day date)
     *  - YYYYMMDDTHHMMSS
     *  - YYYYMMDDTHHMMSSZ
     * With optional TZID parameter (e.g., DTSTART;TZID=America/New_York:...)
     */
    private function parseIcsDateValue(string $rawValue, array $params, DateTimeZone $localTz): ?DateTime
    {
        $v = trim($rawValue);

        // If VALUE=DATE, treat as local date at 00:00
        $isDateOnly = (isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE') || preg_match('/^\d{8}$/', $v);

        $tzid = $params['TZID'] ?? null;

        try {
            if ($isDateOnly) {
                $dt = DateTime::createFromFormat('Ymd', $v, $localTz);
                if (!$dt) {
                    return null;
                }
                $dt->setTime(0, 0, 0);
                return $dt;
            }

            // UTC Zulu
            if (preg_match('/^\d{8}T\d{6}Z$/', $v)) {
                $dt = DateTime::createFromFormat('Ymd\THis\Z', $v, new DateTimeZone('UTC'));
                if (!$dt) {
                    return null;
                }
                $dt->setTimezone($localTz);
                return $dt;
            }

            // Local with TZID
            if ($tzid) {
                $srcTz = new DateTimeZone($tzid);
                $dt = DateTime::createFromFormat('Ymd\THis', $v, $srcTz);
                if (!$dt) {
                    return null;
                }
                $dt->setTimezone($localTz);
                return $dt;
            }

            // Floating/local time (assume local timezone)
            if (preg_match('/^\d{8}T\d{6}$/', $v)) {
                $dt = DateTime::createFromFormat('Ymd\THis', $v, $localTz);
                if (!$dt) {
                    return null;
                }
                return $dt;
            }

            // Fallback: let DateTime try
            $dt = new DateTime($v, $localTz);
            return $dt;

        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Parse RRULE into associative array.
     * Example: "FREQ=DAILY;COUNT=10" => ['FREQ'=>'DAILY','COUNT'=>'10']
     */
    private function parseRrule(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $out = [];
        $parts = explode(';', $raw);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || strpos($p, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $p, 2);
            $k = strtoupper(trim($k));
            $v = trim($v);
            if ($k !== '') {
                $out[$k] = $v;
            }
        }

        return $out ?: null;
    }

    /**
     * Split comma-separated values (EXDATE can contain multiple values).
     */
    private function splitCsvValues(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = explode(',', $raw);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}

class GcsIcsParser extends IcsParser {}

