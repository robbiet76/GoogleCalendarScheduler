<?php

class GcsIcsParser
{
    /**
<<<<<<< HEAD
     * Parse ICS into an array of VEVENTs with normalized fields.
     *
     * @param string   $ics
     * @param DateTime $now
     * @param DateTime $horizonEnd
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $ics, DateTime $now, DateTime $horizonEnd): array
=======
     * Parse raw ICS into structured event records.
     *
     * @param string        $ics
     * @param DateTime|null $now
     * @param DateTime      $horizonEnd
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $ics, ?DateTime $now, DateTime $horizonEnd): array
>>>>>>> master
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

<<<<<<< HEAD
        $events = [];
        $inEvent = false;
        $curr = [];
=======
        foreach ($matches[1] as $raw) {
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
>>>>>>> master

        foreach ($unfolded as $line) {
            if (trim($line) === 'BEGIN:VEVENT') {
                $inEvent = true;
                $curr = [];
                continue;
            }

<<<<<<< HEAD
            if (trim($line) === 'END:VEVENT') {
                if ($inEvent) {
                    $ev = $this->normalizeEvent($curr, $now, $horizonEnd);
                    if ($ev !== null) {
                        $events[] = $ev;
                    }
                }
                $inEvent = false;
                $curr = [];
                continue;
            }

            if (!$inEvent) {
                continue;
            }

            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $left = substr($line, 0, $pos);
            $value = substr($line, $pos + 1);

            $name = $left;
            $params = null;

            $semi = strpos($left, ';');
            if ($semi !== false) {
                $name = substr($left, 0, $semi);
                $params = substr($left, $semi + 1);
=======
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

            // Horizon filtering applies to base DTSTART/DTEND
            if ($now && $dtend < $now) {
                continue;
>>>>>>> master
            }

            $name = strtoupper(trim($name));
            $value = trim($value);

            if ($name === '') {
                continue;
            }

<<<<<<< HEAD
            if (!isset($curr[$name])) {
                $curr[$name] = [];
            }

            $curr[$name][] = [
                'params' => $params,
                'value'  => $value,
=======
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
>>>>>>> master
            ];
        }

        return $events;
    }

<<<<<<< HEAD
    private function normalizeEvent(array $raw, DateTime $now, DateTime $horizonEnd): ?array
    {
        $uid = $this->getFirstValue($raw, 'UID');
        if ($uid === null || $uid === '') {
            return null;
        }

        $summary = $this->getFirstValue($raw, 'SUMMARY') ?? '';

        // ✅ NEW: surface DESCRIPTION (preserve escaped \n)
        $description = $this->getJoinedTextValue($raw, 'DESCRIPTION');

        $dtStart = $this->getFirstProp($raw, 'DTSTART');
        $dtEnd   = $this->getFirstProp($raw, 'DTEND');

        if ($dtStart === null) {
            return null;
        }

        $start = $this->parseDateTimeProp($dtStart);
        if ($start === null) {
            return null;
        }
=======
    /* -----------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    private function parseDateWithFlags(string $value, string $params): array
    {
        $isAllDay = str_contains($params, 'VALUE=DATE');
        $dt = $this->parseDate($value, $params);

        if ($dt && $isAllDay) {
            $dt = $dt->setTime(0, 0, 0);
        }

        return [$dt, $isAllDay];
    }

    private function parseDate(string $raw, string $params = ''): ?DateTime
    {
        try {
            // UTC timestamp
            if (preg_match('/^\d{8}T\d{6}Z$/', $raw)) {
                return DateTime::createFromFormat(
                    'Ymd\THis\Z',
                    $raw,
                    new DateTimeZone('UTC')
                );
            }

            // Floating local time
            if (preg_match('/^\d{8}T\d{6}$/', $raw)) {
                return DateTime::createFromFormat('Ymd\THis', $raw);
            }

            // All-day date
            if (preg_match('/^\d{8}$/', $raw)) {
                return DateTime::createFromFormat('Ymd', $raw);
            }
>>>>>>> master

        $end = $dtEnd ? $this->parseDateTimeProp($dtEnd) : null;
        if ($end === null) {
            $end = (clone $start)->modify('+30 minutes');
        }

        if ($start > $horizonEnd) {
            return null;
        }

        $isAllDay = preg_match('/^\d{8}$/', $dtStart['value']) === 1;

        return [
            'uid'         => $uid,
            'summary'     => $summary,
            'description' => $description,   // ← ONLY ADDITION
            'start'       => $start->format('Y-m-d H:i:s'),
            'end'         => $end->format('Y-m-d H:i:s'),
            'isAllDay'    => $isAllDay,
            'rrule'       => $this->getFirstValue($raw, 'RRULE'),
            'exdates'     => $this->getAllValues($raw, 'EXDATE'),
            'raw'         => $raw,
        ];
    }
<<<<<<< HEAD

    private function getFirstProp(array $raw, string $key): ?array
    {
        return $raw[$key][0] ?? null;
    }

    private function getFirstValue(array $raw, string $key): ?string
    {
        return isset($raw[$key][0]['value']) ? (string)$raw[$key][0]['value'] : null;
    }

    private function getAllValues(array $raw, string $key): array
    {
        if (!isset($raw[$key])) {
            return [];
        }
        return array_map(fn($v) => (string)$v['value'], $raw[$key]);
    }

    private function getJoinedTextValue(array $raw, string $key): ?string
    {
        if (!isset($raw[$key])) {
            return null;
        }
        $vals = array_map(fn($v) => (string)$v['value'], $raw[$key]);
        $text = trim(implode("\n", $vals));
        return $text === '' ? null : $text;
    }

    private function parseDateTimeProp(array $prop): ?DateTime
    {
        $val = $prop['value'];
        $params = $prop['params'] ?? null;

        if (preg_match('/^\d{8}$/', $val)) {
            return DateTime::createFromFormat('Ymd', $val) ?: null;
        }

        if (preg_match('/^\d{8}T\d{6}Z$/', $val)) {
            return DateTime::createFromFormat('Ymd\THis\Z', $val, new DateTimeZone('UTC')) ?: null;
        }

        if (preg_match('/^\d{8}T\d{6}$/', $val)) {
            $tz = null;
            if ($params) {
                $p = $this->parseParams($params);
                $tz = $p['TZID'] ?? null;
            }
            return DateTime::createFromFormat('Ymd\THis', $val, $tz ? new DateTimeZone($tz) : null) ?: null;
        }

        return null;
    }

    private function parseParams(string $raw): ?array
    {
        $out = [];
        foreach (explode(';', $raw) as $p) {
            if (strpos($p, '=') !== false) {
                [$k, $v] = explode('=', $p, 2);
                $out[strtoupper(trim($k))] = trim($v);
            }
        }
        return $out ?: null;
    }
}
=======

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

/**
 * Compatibility alias
 */
class GcsIcsParser extends IcsParser {}

>>>>>>> master
