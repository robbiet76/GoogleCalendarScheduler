<?php
declare(strict_types=1);

final class GcsSchedulerRunner
{
    private array $cfg;
    private int $horizonDays;

    public function __construct(array $cfg, int $horizonDays)
    {
        $this->cfg = $cfg;
        $this->horizonDays = $horizonDays;
    }

    /**
     * Pure calendar ingestion + intent generation.
     *
     * @return array{ok:bool,intents:array<int,array<string,mixed>>,intents_seen:int,errors:array<int,string>}
     */
    public function run(): array
    {
        $icsUrl = trim((string)($this->cfg['calendar']['ics_url'] ?? ''));
        if ($icsUrl === '') {
            GcsLogger::instance()->warn('No ICS URL configured');
            return $this->emptyResult();
        }

        $ics = (new GcsIcsFetcher())->fetch($icsUrl);
        if ($ics === '') {
            return $this->emptyResult();
        }

        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify('+' . $this->horizonDays . ' days');

        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);
        if (empty($events)) {
            return $this->emptyResult();
        }

        // Group events by UID (base + overrides)
        $byUid = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            $uid = (string)($ev['uid'] ?? '');
            if ($uid !== '') {
                $byUid[$uid][] = $ev;
            }
        }

        $intentsOut = [];

        foreach ($byUid as $uid => $items) {
            $base = null;
            $overrides = [];

            foreach ($items as $ev) {
                if (!is_array($ev)) continue;

                if (!empty($ev['isOverride']) && !empty($ev['recurrenceId'])) {
                    $overrides[(string)$ev['recurrenceId']] = $ev;
                } elseif ($base === null) {
                    $base = $ev;
                }
            }

            $refEv = $base ?? $items[0];
            if (!is_array($refEv)) continue;

            if (!empty($refEv['isAllDay'])) {
                continue;
            }

            $summary = (string)($refEv['summary'] ?? '');
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            $occurrences = self::expandEventOccurrences(
                $base,
                $overrides,
                $now,
                $horizonEnd
            );

            if (empty($occurrences)) {
                continue;
            }

            // Determine whether we can safely emit ONE intent per UID.
            // If overrides exist OR occurrence times vary, we fall back to per-occurrence intents
            // and let GcsIntentConsolidator split into ranges losslessly.
            $hasOverride = false;
            $timeKey = null;
            $timesVary = false;

            foreach ($occurrences as $occ) {
                if (!is_array($occ)) continue;

                if (!empty($occ['isOverride'])) {
                    $hasOverride = true;
                }

                $s = new DateTime((string)($occ['start'] ?? ''));
                $e = new DateTime((string)($occ['end'] ?? ''));

                $k = $s->format('H:i:s') . '|' . $e->format('H:i:s');
                if ($timeKey === null) {
                    $timeKey = $k;
                } elseif ($timeKey !== $k) {
                    $timesVary = true;
                }
            }

            $canEmitSingle = (!$hasOverride && !$timesVary);

            if ($canEmitSingle) {
                // IMPORTANT (Phase 20): We emit ONE intent per UID (one scheduler entry), not one per occurrence.
                // That means we MUST explicitly provide a range (start/end/days). Consolidator cannot infer
                // "Everyday" or the correct endDate from a single occurrence.

                $first = $occurrences[0];
                if (!is_array($first) || empty($first['start']) || empty($first['end'])) {
                    continue;
                }

                $occStart = new DateTime((string)$first['start']);
                $occEnd   = new DateTime((string)$first['end']);

                // Series DTSTART date (calendar series start)
                $seriesStartDate = $occStart->format('Y-m-d');
                if ($base && !empty($base['start'])) {
                    $tmp = substr((string)$base['start'], 0, 10);
                    if (self::isValidYmd($tmp)) {
                        $seriesStartDate = $tmp;
                    }
                }

                // Series end date:
                // Prefer RRULE UNTIL (true series boundary), else use last expanded occurrence date.
                $seriesEndDate = $occStart->format('Y-m-d');
                $lastOccDate = $occStart->format('Y-m-d');

                foreach ($occurrences as $occ) {
                    if (!is_array($occ) || empty($occ['start'])) continue;
                    $d = substr((string)$occ['start'], 0, 10);
                    if (self::isValidYmd($d) && $d > $lastOccDate) {
                        $lastOccDate = $d;
                    }
                }

                $seriesEndDate = $lastOccDate;

                $rrule = ($base && isset($base['rrule']) && is_array($base['rrule'])) ? $base['rrule'] : null;
                if (is_array($rrule) && !empty($rrule['UNTIL'])) {
                    $until = self::parseRruleUntil((string)$rrule['UNTIL']);
                    if ($until instanceof DateTime) {
                        // Normalize to local timezone before taking date
                        try {
                            $until->setTimezone(new DateTimeZone(date_default_timezone_get()));
                        } catch (Throwable $ignored) {}
                        $untilDate = $until->format('Y-m-d');
                        if (self::isValidYmd($untilDate)) {
                            $seriesEndDate = $untilDate;
                        }
                    }
                }

                // Days:
                // - DAILY => Everyday (SuMoTuWeThFrSa)
                // - WEEKLY => derive from BYDAY, else fallback to the start DOW only
                $daysShort = '';
                if (is_array($rrule)) {
                    $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
                    if ($freq === 'DAILY') {
                        $daysShort = 'SuMoTuWeThFrSa';
                    } elseif ($freq === 'WEEKLY') {
                        $daysShort = self::shortDaysFromByDay((string)($rrule['BYDAY'] ?? ''));
                    }
                }
                if ($daysShort === '') {
                    // Fallback: single day based on DTSTART
                    $dow = (int)$occStart->format('w'); // 0=Sun..6=Sat
                    $daysShort = self::dowToShortDay($dow);
                }

                $template = [
                    'uid'        => $uid,
                    'summary'    => $summary,
                    'type'       => (string)$resolved['type'],
                    'target'     => (string)$resolved['target'],
                    'start'      => $occStart->format('Y-m-d H:i:s'),
                    'end'        => $occEnd->format('Y-m-d H:i:s'),
                    'stopType'   => 'graceful',
                    'repeat'     => 'none',
                    'isOverride' => false,
                ];

                $intentsOut[] = [
                    'uid'      => $uid,
                    'template' => $template,
                    'range'    => [
                        'start' => $seriesStartDate,
                        'end'   => $seriesEndDate,
                        'days'  => $daysShort,
                    ],
                ];

                continue;
            }

            // Fallback: per-occurrence (lossless), then consolidator will merge into correct ranges/days.
            $rawIntents = [];
            foreach ($occurrences as $occ) {
                if (!is_array($occ)) continue;

                $rawIntents[] = [
                    'uid'        => $uid,
                    'summary'    => $summary,
                    'type'       => $resolved['type'],
                    'target'     => $resolved['target'],
                    'start'      => $occ['start'],
                    'end'        => $occ['end'],
                    'stopType'   => 'graceful',
                    'repeat'     => 'none',
                    'isOverride' => !empty($occ['isOverride']),
                ];
            }

            try {
                $consolidator = new GcsIntentConsolidator();
                $maybe = $consolidator->consolidate($rawIntents);
                if (is_array($maybe) && !empty($maybe)) {
                    foreach ($maybe as $row) {
                        if (is_array($row)) {
                            $intentsOut[] = $row;
                        }
                    }
                }
            } catch (Throwable $ignored) {
                // If consolidation fails, still return the raw intents (best effort)
                foreach ($rawIntents as $ri) {
                    $intentsOut[] = $ri;
                }
            }
        }

        return [
            'ok'           => true,
            'intents'      => $intentsOut,
            'intents_seen' => count($intentsOut),
            'errors'       => [],
        ];
    }

    private function emptyResult(): array
    {
        return [
            'ok'           => true,
            'intents'      => [],
            'intents_seen' => 0,
            'errors'       => [],
        ];
    }

    /**
     * Recurrence expansion helper.
     *
     * Generates concrete occurrences intersecting the horizon.
     */
    private static function expandEventOccurrences(
        ?array $base,
        array $overrides,
        DateTime $horizonStart,
        DateTime $horizonEnd
    ): array {
        $out = [];
        $overrideKeys = [];

        // Include overrides first
        foreach ($overrides as $rid => $ov) {
            $s = new DateTime($ov['start']);
            if ($s >= $horizonStart && $s <= $horizonEnd) {
                $overrideKeys[$rid] = true;
                $out[] = [
                    'start'      => $s->format('Y-m-d H:i:s'),
                    'end'        => (new DateTime($ov['end']))->format('Y-m-d H:i:s'),
                    'isOverride' => true,
                ];
            }
        }

        if (!$base) {
            return $out;
        }

        $start = new DateTime($base['start']);
        $end   = new DateTime($base['end']);
        $duration = max(0, $end->getTimestamp() - $start->getTimestamp());

        // Non-recurring
        if (empty($base['rrule'])) {
            if ($start >= $horizonStart && $start <= $horizonEnd) {
                $rid = $start->format('Y-m-d H:i:s');
                if (empty($overrideKeys[$rid])) {
                    $out[] = [
                        'start'      => $rid,
                        'end'        => (clone $start)->modify("+{$duration} seconds")->format('Y-m-d H:i:s'),
                        'isOverride' => false,
                    ];
                }
            }
            return $out;
        }

        $rrule = $base['rrule'];
        $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
        $interval = max(1, (int)($rrule['INTERVAL'] ?? 1));

        $until = null;
        if (!empty($rrule['UNTIL'])) {
            $until = self::parseRruleUntil((string)$rrule['UNTIL']);
        }

        $countLimit = isset($rrule['COUNT']) ? max(1, (int)$rrule['COUNT']) : null;

        $exDates = [];
        if (!empty($base['exDates'])) {
            foreach ($base['exDates'] as $ex) {
                $exDates[$ex] = true;
            }
        }

        $addOccurrence = function(DateTime $s) use (
            &$out,
            $duration,
            $horizonStart,
            $horizonEnd,
            $until,
            &$countLimit,
            &$overrideKeys,
            &$exDates
        ): bool {
            if ($s < $horizonStart || $s > $horizonEnd) return true;
            if ($until && $s > $until) return false;

            $rid = $s->format('Y-m-d H:i:s');
            if (!empty($overrideKeys[$rid]) || !empty($exDates[$rid])) return true;

            $out[] = [
                'start'      => $rid,
                'end'        => (clone $s)->modify("+{$duration} seconds")->format('Y-m-d H:i:s'),
                'isOverride' => false,
            ];

            if ($countLimit !== null && --$countLimit <= 0) {
                return false;
            }
            return true;
        };

        $h = (int)$start->format('H');
        $i = (int)$start->format('i');
        $s = (int)$start->format('s');

        if ($freq === 'DAILY') {
            $cursor = clone $start;
            while ($cursor < $horizonStart) {
                $cursor->modify("+{$interval} days");
            }
            while ($cursor <= $horizonEnd) {
                $cursor->setTime($h, $i, $s);
                if (!$addOccurrence($cursor)) break;
                $cursor->modify("+{$interval} days");
            }
            return $out;
        }

        if ($freq === 'WEEKLY') {
            $byday = [];
            if (!empty($rrule['BYDAY'])) {
                foreach (explode(',', strtoupper((string)$rrule['BYDAY'])) as $tok) {
                    $d = self::byDayToDow($tok);
                    if ($d !== null) $byday[] = $d;
                }
            }
            if (empty($byday)) {
                $byday[] = (int)$start->format('w');
            }

            $weekCursor = clone $start;
            while ($weekCursor < $horizonStart) {
                $weekCursor->modify("+{$interval} weeks");
            }

            while ($weekCursor <= $horizonEnd) {
                foreach ($byday as $dow) {
                    $occ = (clone $weekCursor)->modify("sunday this week +{$dow} days");
                    $occ->setTime($h, $i, $s);
                    if ($occ < $start) continue;
                    if (!$addOccurrence($occ)) return $out;
                }
                $weekCursor->modify("+{$interval} weeks");
            }
            return $out;
        }

        return $out;
    }

    private static function byDayToDow(string $tok): ?int
    {
        $tok = preg_replace('/^[+-]?\d+/', '', $tok);
        return match ($tok) {
            'SU' => 0,
            'MO' => 1,
            'TU' => 2,
            'WE' => 3,
            'TH' => 4,
            'FR' => 5,
            'SA' => 6,
            default => null,
        };
    }

    private static function parseRruleUntil(string $raw): ?DateTime
    {
        try {
            if (preg_match('/^\d{8}$/', $raw)) {
                return (new DateTime($raw))->setTime(23, 59, 59);
            }
            if (preg_match('/^\d{8}T\d{6}Z$/', $raw)) {
                return new DateTime($raw, new DateTimeZone('UTC'));
            }
            if (preg_match('/^\d{8}T\d{6}$/', $raw)) {
                return new DateTime($raw);
            }
        } catch (Throwable) {}
        return null;
    }

    private static function isValidYmd(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $s);
        return ($dt instanceof DateTime) && ($dt->format('Y-m-d') === $s);
    }

    private static function dowToShortDay(int $dow): string
    {
        return match ($dow) {
            0 => 'Su',
            1 => 'Mo',
            2 => 'Tu',
            3 => 'We',
            4 => 'Th',
            5 => 'Fr',
            6 => 'Sa',
            default => '',
        };
    }

    /**
     * Convert RRULE BYDAY like "SU,MO,TU,WE,TH,FR,SA" into "SuMoTuWeThFrSa".
     * Returns empty string if BYDAY missing/invalid.
     */
    private static function shortDaysFromByDay(string $bydayRaw): string
    {
        $bydayRaw = strtoupper(trim($bydayRaw));
        if ($bydayRaw === '') return '';

        $tokens = explode(',', $bydayRaw);
        $present = [
            'SU' => false,
            'MO' => false,
            'TU' => false,
            'WE' => false,
            'TH' => false,
            'FR' => false,
            'SA' => false,
        ];

        foreach ($tokens as $tok) {
            $tok = preg_replace('/^[+-]?\d+/', '', trim($tok)); // remove ordinal like 1MO
            if (isset($present[$tok])) {
                $present[$tok] = true;
            }
        }

        $out = '';
        if ($present['SU']) $out .= 'Su';
        if ($present['MO']) $out .= 'Mo';
        if ($present['TU']) $out .= 'Tu';
        if ($present['WE']) $out .= 'We';
        if ($present['TH']) $out .= 'Th';
        if ($present['FR']) $out .= 'Fr';
        if ($present['SA']) $out .= 'Sa';

        return $out;
    }
}
