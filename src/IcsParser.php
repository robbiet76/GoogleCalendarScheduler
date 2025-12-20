<?php

class GcsIcsParser
{
    /**
     * Parse ICS into an array of VEVENTs with normalized fields.
     *
     * @param string   $ics
     * @param DateTime $now
     * @param DateTime $horizonEnd
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $ics, DateTime $now, DateTime $horizonEnd): array
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
        $curr = [];

        foreach ($unfolded as $line) {
            if (trim($line) === 'BEGIN:VEVENT') {
                $inEvent = true;
                $curr = [];
                continue;
            }

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
            }

            $name = strtoupper(trim($name));
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            if (!isset($curr[$name])) {
                $curr[$name] = [];
            }

            $curr[$name][] = [
                'params' => $params,
                'value'  => $value,
            ];
        }

        return $events;
    }

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
