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

        $rawIntents = [];

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

            // Expand occurrences ONLY to decide whether this series intersects the horizon.
            // IMPORTANT (Phase 20): We emit ONE intent per UID (one scheduler entry), not one per occurrence.
            $occurrences = self::expandEventOccurrences(
                $base,
                $overrides,
                $now,
                $horizonEnd
            );

            if (empty($occurrences)) {
                // Nothing relevant within the horizon; do not emit an intent.
                continue;
            }

            // Determine the template start/end time-of-day for the schedule entry.
            // Prefer base DTSTART/DTEND when available (series anchor); otherwise use first in-horizon occurrence.
            $tplStart = null;
            $tplEnd = null;

            if ($base && !empty($base['start']) && !empty($base['end'])) {
                $tplStart = (string)$base['start'];
                $tplEnd   = (string)$base['end'];
            } else {
                $first = $occurrences[0];
                if (is_array($first) && !empty($first['start']) && !empty($first['end'])) {
                    $tplStart = (string)$first['start'];
                    $tplEnd   = (string)$first['end'];
                }
            }

            if ($tplStart === null || $tplEnd === null) {
                continue;
            }

            // Series start date should reflect calendar DTSTART (date-only).
            $seriesStartDate = substr($tplStart, 0, 10); // YYYY-MM-DD
            if (!self::isValidYmd($seriesStartDate)) {
                // Fallback: if somehow malformed, use horizon start date
                $seriesStartDate = $now->format('Y-m-d');
            }

            // Series end date:
            // - If RRULE:UNTIL is present, it is authoritative.
            // - Otherwise fall back to horizon end (conservative; prevents infinite ranges).
            $seriesEndDate = null;

            if ($base && !empty($base['rrule']) && is_array($base['rrule']) && !empty($base['rrule']['UNTIL'])) {
                $untilDt = self::parseRruleUntil((string)$base['rrule']['UNTIL']);
                if ($untilDt instanceof DateTime) {
                    $seriesEndDate = $untilDt->format('Y-m-d');
                }
            }

            if ($seriesEndDate === null || !self::isValidYmd($seriesEndDate)) {
                $seriesEndDate = $horizonEnd->format('Y-m-d');
            }

            // Days mask: prefer RRULE BYDAY when present, else default to the weekday of DTSTART.
            $daysShort = '';
            if ($base && !empty($base['rrule']) && is_array($base['rrule'])) {
                $rrule = $base['rrule'];
                $freq = strtoupper((string)($rrule['FREQ'] ?? ''));

                if ($freq === 'DAILY') {
                    // Daily recurrence implies all days.
                    $daysShort = 'SuMoTuWeThFrSa';
                } elseif (!empty($rrule['BYDAY'])) {
                    $mask = 0;
                    foreach (explode(',', strtoupper((string)$rrule['BYDAY'])) as $tok) {
                        $dow = self::byDayToDow($tok);
                        if ($dow !== null) {
                            $mask |= (1 << $dow);
                        }
                    }
                    if ($mask !== 0) {
                        $daysShort = (string)GcsIntentConsolidator::weekdayMaskToShortDays($mask);
                    }
                }
            }

            if ($daysShort === '') {
                // Default to DTSTART weekday.
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $tplStart);
                if ($dt instanceof DateTime) {
                    $dow = (int)$dt->format('w'); // 0=Sun..6=Sat
                    $mask = (1 << $dow);
                    $daysShort = (string)GcsIntentConsolidator::weekdayMaskToShortDays($mask);
                } else {
                    $daysShort = 'SuMoTuWeThFrSa';
                }
            }

            // Emit ONE intent for this UID (one scheduler entry).
            // Use range start/end so SchedulerSync produces startDate=endDate correctly for the series.
            $rawIntents[] = [
                'uid'     => $uid,
                'summary' => $summary,
                'type'    => $resolved['type'],
                'target'  => $resolved['target'],
                'start'   => $tplStart,
                'end'     => $tplEnd,
                'stopType'=> 'graceful',
                'repeat'  => 'none',
                'range'   => [
                    'start' => $seriesStartDate,
                    'end'   => $seriesEndDate,
                    'days'  => $daysShort,
                ],
            ];
        }

        // Consolidate raw intents (multi-day, adjacency, overlaps)
        // Note: With Phase 20 "one intent per UID", consolidator should be effectively a no-op for series events.
        $consolidated = $rawIntents;
        try {
            $consolidator = new GcsIntentConsolidator();
            $maybe = $consolidator->consolidate($rawIntents);
            if (is_array($maybe)) {
                $consolidated = $maybe;
            }
        } catch (Throwable $ignored) {}

        return [
            'ok'           => true,
            'intents'      => $consolidated,
            'intents_seen' => count($consolidated),
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
            // DATE form (YYYYMMDD) — treat as inclusive end-of-day
            if (preg_match('/^\d{8}$/', $raw)) {
                return (new DateTime($raw))->setTime(23, 59, 59);
            }

            // UTC timestamp
            if (preg_match('/^\d{8}T\d{6}Z$/', $raw)) {
                return new DateTime($raw, new DateTimeZone('UTC'));
            }

            // Local timestamp
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
}
