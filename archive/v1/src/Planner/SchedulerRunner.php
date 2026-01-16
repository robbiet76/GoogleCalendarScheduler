<?php
declare(strict_types=1);

/**
 * SchedulerRunner
 *
 * Pure calendar ingestion and analysis engine.
 *
 * Responsibilities:
 * - Fetch and parse ICS calendar data
 * - Expand occurrences within a bounded horizon (analysis-only)
 * - Resolve scheduler targets from event summaries
 * - Parse YAML metadata per occurrence (analysis-only)
 * - Emit series analysis suitable for Planner semantic projection
 *
 * Guarantees:
 * - No scheduler writes
 * - No schedule.json mutation
 * - Deterministic output for a given calendar and horizon window
 *
 * Guard window:
 * - Based on FPP system time
 * - End boundary is fixed to the FPPSemantics scheduler guard date
 *
 * NOTE:
 * Final scheduling policy (start-date validity, end-date capping, max entries)
 * is enforced in SchedulerPlanner.
 */
final class SchedulerRunner
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function run(): array
    {
        // heartbeat (debug only; safe side-effect)
        file_put_contents('/tmp/gcs_runner_ran.txt', date('c') . PHP_EOL, FILE_APPEND);

        /* ------------------------------------------------------------
         * Calendar fetch
         * ---------------------------------------------------------- */
        $icsUrl = trim((string)($this->cfg['calendar']['ics_url'] ?? ''));
        if ($icsUrl === '') {
            GcsLogger::instance()->warn('No ICS URL configured');
            return $this->emptyResult();
        }

        $ics = (new IcsFetcher())->fetch($icsUrl);
        if ($ics === '') {
            return $this->emptyResult();
        }

        /* ------------------------------------------------------------
         * Horizon (analysis bound only)
         * ---------------------------------------------------------- */
        $now = new DateTime('now');
        $horizonEnd = FPPSemantics::getSchedulerGuardDate();

        /* ------------------------------------------------------------
         * Parse ICS
         * ---------------------------------------------------------- */
        $parser = new IcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        file_put_contents(
            '/tmp/gcs_parsed_events_debug.json',
            json_encode($events, JSON_PRETTY_PRINT)
        );

        if (empty($events)) {
            return $this->emptyResult();
        }

        /* ------------------------------------------------------------
         * Group by UID
         * ---------------------------------------------------------- */
        $byUid = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            $uid = (string)($ev['uid'] ?? '');
            if ($uid !== '') {
                $byUid[$uid][] = $ev;
            }
        }

        $seriesOut = [];
        $trace = [];

        /* ------------------------------------------------------------
         * Per-UID processing (analysis only; no intent emission)
         * ---------------------------------------------------------- */
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
            if (!empty($refEv['isAllDay'])) continue;

            $summary = (string)($refEv['summary'] ?? '');
            $resolved = TargetResolver::resolve($summary);
            if (!$resolved) {
                $trace[] = [
                    'uid' => $uid,
                    'summary' => $summary,
                    'note' => 'unresolved_target',
                ];
                continue;
            }

            // Expand occurrences (bounded). This is analysis-only now.
            $occurrences = self::expandEventOccurrences(
                $base,
                $overrides,
                $now,
                $horizonEnd
            );

            $trace[] = [
                'uid' => $uid,
                'summary' => $summary,
                'rrule' => is_array($base) ? ($base['rrule'] ?? null) : null,
                'override_count' => count($overrides),
                'occurrence_count' => count($occurrences),
            ];

            // Parse a stable YAML blob for base (prefer base description, else empty)
            $baseYaml = [];
            if (is_array($base)) {
                $desc = self::extractDescriptionFromEvent($base);
                $yaml = YamlMetadata::parse($desc, [
                    'uid'     => $uid,
                    'summary' => $summary,
                    'start'   => (string)($base['start'] ?? ''),
                ]);
                if (is_array($yaml)) {
                    ksort($yaml);
                    $baseYaml = $yaml;
                }
            }

            // Extract override occurrences (only those occurrences that are overrides)
            $overrideOccs = [];
            foreach ($occurrences as $occ) {
                if (!is_array($occ) || empty($occ['isOverride'])) {
                    continue;
                }

                $rid = (string)($occ['start'] ?? '');
                $sourceEv = (isset($overrides[$rid]) && is_array($overrides[$rid])) ? $overrides[$rid] : null;

                $yaml = [];
                if ($sourceEv) {
                    $desc = self::extractDescriptionFromEvent($sourceEv);
                    $parsed = YamlMetadata::parse($desc, [
                        'uid'     => $uid,
                        'summary' => $summary,
                        'start'   => $rid,
                    ]);
                    if (is_array($parsed)) {
                        ksort($parsed);
                        $yaml = $parsed;
                    }
                }

                $overrideOccs[] = [
                    'start' => $rid,
                    'end'   => (string)($occ['end'] ?? ''),
                    'yaml'  => $yaml,
                ];
            }

            // IMPORTANT: do not gate series existence on occurrences.
            // Planner will create a base schedule from DTSTART/RRULE even if occurrence_count == 0.
            $seriesOut[] = [
                'uid'          => $uid,
                'summary'      => $summary,
                'resolved'     => $resolved,
                'base'         => $base,
                'overrides'    => $overrides,
                'occurrences'  => $occurrences,
                'overrideOccs' => $overrideOccs,
                'yamlBase'     => $baseYaml,
                'horizon'      => [
                    'start' => $now->format('Y-m-d H:i:s'),
                    'end'   => $horizonEnd->format('Y-m-d H:i:s'),
                ],
            ];
        }

        file_put_contents(
            '/tmp/gcs_runner_trace.json',
            json_encode($trace, JSON_PRETTY_PRINT)
        );

        return [
            'ok'     => true,
            'series' => $seriesOut,
            'errors' => [],
        ];
    }

    private function emptyResult(): array
    {
        return ['ok' => true, 'series' => [], 'errors' => []];
    }

    private static function extractDescriptionFromEvent(?array $ev): ?string
    {
        if (!$ev) return null;
        foreach (['description','DESCRIPTION','desc','body'] as $k) {
            if (!empty($ev[$k])) return trim((string)$ev[$k]);
        }
        return null;
    }

    /**
     * Expand recurring and non-recurring events into concrete
     * occurrences intersecting the horizon.
     *
     * Returns items shaped:
     *   ['start' => 'Y-m-d H:i:s', 'end' => 'Y-m-d H:i:s', 'isOverride' => bool, 'source' => array]
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
            try {
                $s = new DateTime((string)$ov['start']);
                $e = new DateTime((string)$ov['end']);
            } catch (\Throwable $e) {
                continue;
            }

            if ($s >= $horizonStart && $s <= $horizonEnd) {
                $overrideKeys[$rid] = true;
                $out[] = [
                    'start'      => $s->format('Y-m-d H:i:s'),
                    'end'        => $e->format('Y-m-d H:i:s'),
                    'isOverride' => true,
                    'source'     => $ov,
                ];
            }
        }

        if (!$base) {
            return $out;
        }

        $start = new DateTime((string)$base['start']);
        $end   = new DateTime((string)$base['end']);
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
                        'source'     => $base,
                    ];
                }
            }
            return $out;
        }

        // Recurring (DAILY/WEEKLY)
        $rrule = $base['rrule'];
        $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
        $interval = max(1, (int)($rrule['INTERVAL'] ?? 1));

        $until = !empty($rrule['UNTIL']) ? self::parseRruleUntil((string)$rrule['UNTIL']) : null;
        $countLimit = isset($rrule['COUNT']) ? max(1, (int)$rrule['COUNT']) : null;

        $exDates = [];
        if (!empty($base['exDates']) && is_array($base['exDates'])) {
            foreach ($base['exDates'] as $ex) {
                $exDates[(string)$ex] = true;
            }
        }

        $addOccurrence = function(DateTime $s) use (
            &$out,
            $duration,
            $base,
            $horizonStart,
            $horizonEnd,
            $until,
            &$countLimit,
            &$overrideKeys,
            &$exDates
        ): bool {
            // NOTE: we still generate occurrences only within horizon (analysis bound)
            if ($s < $horizonStart || $s > $horizonEnd) return true;
            if ($until && $s > $until) return false;

            $rid = $s->format('Y-m-d H:i:s');
            if (!empty($overrideKeys[$rid]) || !empty($exDates[$rid])) return true;

            $out[] = [
                'start'      => $rid,
                'end'        => (clone $s)->modify("+{$duration} seconds")->format('Y-m-d H:i:s'),
                'isOverride' => false,
                'source'     => $base,
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
        } catch (\Throwable) {}
        return null;
    }
}
