<?php

class IcsParser
{
    public function parse(string $ics, ?DateTime $now, DateTime $horizonEnd): array
    {
        $events = [];

        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $matches);

        foreach ($matches[1] as $raw) {
            $dtstart = null;
            $dtend   = null;
            $summary = '';

            if (preg_match('/SUMMARY:(.+)/', $raw, $m)) {
                $summary = trim($m[1]);
            }

            if (preg_match('/DTSTART[^:]*:(.+)/', $raw, $m)) {
                $dtstart = $this->parseDate($m[1]);
            }

            if (preg_match('/DTEND[^:]*:(.+)/', $raw, $m)) {
                $dtend = $this->parseDate($m[1]);
            }

            if (!$dtstart || !$dtend) {
                continue;
            }

            if ($now && $dtend < $now) {
                continue;
            }

            if ($dtstart > $horizonEnd) {
                continue;
            }

            $events[] = [
                'summary' => $summary,
                'start'   => $dtstart->format('Y-m-d H:i:s'),
                'end'     => $dtend->format('Y-m-d H:i:s'),
            ];
        }

        return $events;
    }

    private function parseDate(string $raw): ?DateTime
    {
        try {
            if (preg_match('/^\d{8}T\d{6}Z$/', $raw)) {
                return DateTime::createFromFormat(
                    'Ymd\THis\Z',
                    $raw,
                    new DateTimeZone('UTC')
                );
            }

            if (preg_match('/^\d{8}T\d{6}$/', $raw)) {
                return DateTime::createFromFormat('Ymd\THis', $raw);
            }

            if (preg_match('/^\d{8}$/', $raw)) {
                return DateTime::createFromFormat('Ymd', $raw);
            }

            return new DateTime($raw);
        } catch (Throwable $e) {
            return null;
        }
    }
}

class GcsIcsParser extends IcsParser {}
