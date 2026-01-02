<?php
declare(strict_types=1);

/**
 * SchedulerRunner
 *
 * Pure calendar ingestion and intent generation engine.
 *
 * Responsibilities:
 * - Fetch and parse ICS calendar data
 * - Expand recurring events within a bounded window
 * - Resolve scheduler targets from event summaries
 * - Apply YAML metadata overrides
 * - Generate scheduler intents suitable for consolidation
 *
 * Guarantees:
 * - No scheduler writes
 * - No scheduler state mutation
 * - Deterministic output for a given calendar and guard window
 *
 * Guard window:
 * - Based on FPP system time
 * - End boundary is fixed to Dec 31 of (currentYear + 2)
 *
 * NOTE:
 * Final scheduling policy (start-date validity + end-date capping + max entries)
 * is enforced in SchedulerPlanner. This runner only bounds occurrence expansion.
 */
final class SchedulerRunner
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * Execute calendar ingestion and intent generation.
     *
     * @return array{
     *   ok: bool,
     *   intents: array<int,array<string,mixed>>,
     *   intents_seen: int,
     *   errors: array<int,string>
     * }
     */
    public function run(): array
    {
        file_put_contents('/tmp/gcs_runner_ran.txt', date('c') . PHP_EOL, FILE_APPEND);

        // ------------------------------------------------------------
        // Calendar fetch & parse
        // ------------------------------------------------------------
        $icsUrl = trim((string)($this->cfg['calendar']['ics_url'] ?? ''));
        if ($icsUrl === '') {
            GcsLogger::instance()->warn('No ICS URL configured');
            return $this->emptyResult();
        }

        $ics = (new IcsFetcher())->fetch($icsUrl);
        if ($ics === '') {
            return $this->emptyResult();
        }

        // Horizon start is "now" (FPP system time)
        $now = new DateTime('now');

        // Fixed, calendar-aligned horizon end:
        // Dec 31 of (currentYear + 2) at 23:59:59 local time
        $currentYear = (int)$now->format('Y');
        $guardYear   = $currentYear + 2;

        $horizonEnd = new DateTime(sprintf('%04d-12-31 23:59:59', $guardYear));

        $parser = new IcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        // Debug dump of parsed events
        file_put_contents(
            '/tmp/gcs_parsed_events_debug.json',
            json_encode($events, JSON_PRETTY_PRINT)
        );

        if (empty($events)) {
            return $this->emptyResult();
        }

        // ------------------------------------------------------------
        // Group events by UID (base event + overrides)
        // ------------------------------------------------------------
        $byUid = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            $uid = (string)($ev['uid'] ?? '');
            if ($uid !== '') {
                $byUid[$uid][] = $ev;
            }
        }

        $intentsOut = [];

        // ------------------------------------------------------------
        // Per-UID intent generation
        // ------------------------------------------------------------
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

            // Skip all-day events (unsupported by scheduler)
            if (!empty($refEv['isAllDay'])) {
                continue;
            }

            // Resolve scheduler target from summary
            $summary = (string)($refEv['summary'] ?? '');
            $resolved = TargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            // --------------------------------------------------------
            // Expand occurrences within horizon
            // --------------------------------------------------------
            $occurrences = self::expandEventOccurrences(
                $base,
                $overrides,
                $now,
                $horizonEnd
            );

            if (empty($occurrences)) {
                continue;
            }

            // --------------------------------------------------------
            // Determine if a single intent can safely represent all
            // occurrences (no overrides, no time variance, no YAML variance)
            // --------------------------------------------------------
            $hasOverride = false;
            $timeKey = null;
            $timesVary = false;

            $yamlSig = null;
            $yamlVaries = false;
            $occYaml = [];

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

                $rid = (string)($occ['start'] ?? '');
                $sourceEv = $base;

                if (!empty($occ['isOverride']) && $rid !== '' && isset($overrides[$rid])) {
                    $sourceEv = $overrides[$rid];
                }

                $desc = self::extractDescriptionFromEvent($sourceEv);
                $yaml = YamlMetadata::parse($desc, [
                    'uid'     => $uid,
                    'summary' => $summary,
                    'start'   => $occ['start'],
                ]);

                if (is_array($yaml)) {
                    ksort($yaml);
                }

                $sig = json_encode($yaml);
                if ($yamlSig === null) {
                    $yamlSig = $sig;
                } elseif ($yamlSig !== $sig) {
                    $yamlVaries = true;
                }

                $occYaml[$rid] = $yaml;
            }

            $canEmitSingle = (!$hasOverride && !$timesVary && !$yamlVaries);

            // --------------------------------------------------------
            // Single-intent path (one scheduler entry)
            // --------------------------------------------------------
            if ($canEmitSingle) {
                $first = $occurrences[0];
                if (!is_array($first) || empty($first['start']) || empty($first['end'])) {
                    continue;
                }

                $occStart = new DateTime((string)$first['start']);
                $occEnd   = new DateTime((string)$first['end']);

                // Determine series date range
                $seriesStartDate = $occStart->format('Y-m-d');
                if ($base && !empty($base['start'])) {
                    $tmp = substr((string)$base['start'], 0, 10);
                    if (self::isValidYmd($tmp)) {
                        $seriesStartDate = $tmp;
                    }
                }

                $lastOccDate = $occStart->format('Y-m-d');
                foreach ($occurrences as $occ) {
                    if (!is_array($occ) || empty($occ['start'])) continue;
                    $d = substr((string)$occ['start'], 0, 10);
                    if (self::isValidYmd($d) && $d > $lastOccDate) {
                        $lastOccDate = $d;
                    }
                }

                $seriesEndDate = $lastOccDate;

                // Prefer RRULE UNTIL if present
                $rrule = ($base && isset($base['rrule']) && is_array($base['rrule'])) ? $base['rrule'] : null;
                if (is_array($rrule) && !empty($rrule['UNTIL'])) {
                    $until = self::parseRruleUntil((string)$rrule['UNTIL']);
                    if ($until instanceof DateTime) {
                        try {
                            $until->setTimezone(new DateTimeZone(date_default_timezone_get()));
                        } catch (Throwable $ignored) {}
                        $untilDate = $until->format('Y-m-d');
                        if (self::isValidYmd($untilDate)) {
                            $seriesEndDate = $untilDate;
                        }
                    }
                }

                // Determine days mask
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
                    $daysShort = self::dowToShortDay((int)$occStart->format('w'));
                }

                $rid0 = (string)$first['start'];
                $yaml0 = $occYaml[$rid0] ?? [];

                $eff = self::applyYamlToTemplate([
                    'stopType' => 'graceful',
                    'repeat'   => 'immediate',
                ], is_array($yaml0) ? $yaml0 : []);

                $intentsOut[] = [
                    'uid'      => $uid,
                    'template' => [
                        'uid'        => $uid,
                        'summary'    => $summary,
                        'type'       => (string)$resolved['type'],
                        'target'     => (string)$resolved['target'],
                        'start'      => $occStart->format('Y-m-d H:i:s'),
                        'end'        => $occEnd->format('Y-m-d H:i:s'),
                        'stopType'   => $eff['stopType'],
                        'repeat'     => $eff['repeat'],
                        'isOverride' => false,
                    ],
                    'range'    => [
                        'start' => $seriesStartDate,
                        'end'   => $seriesEndDate,
                        'days'  => $daysShort,
                    ],
                ];

                continue;
            }

            // --------------------------------------------------------
            // Fallback: per-occurrence intents (lossless)
            // --------------------------------------------------------
            $rawIntents = [];
            foreach ($occurrences as $occ) {
                if (!is_array($occ)) continue;

                $yaml = $occYaml[$occ['start']] ?? [];

                $eff = self::applyYamlToTemplate([
                    'stopType' => 'graceful',
                    'repeat'   => 'immediate',
                ], is_array($yaml) ? $yaml : []);

                $rawIntents[] = [
                    'uid'        => $uid,
                    'summary'    => $summary,
                    'type'       => $resolved['type'],
                    'target'     => $resolved['target'],
                    'start'      => $occ['start'],
                    'end'        => $occ['end'],
                    'stopType'   => $eff['stopType'],
                    'repeat'     => $eff['repeat'],
                    'isOverride' => !empty($occ['isOverride']),
                ];
            }

            try {
                $consolidator = new IntentConsolidator();
                $maybe = $consolidator->consolidate($rawIntents);
                if (is_array($maybe)) {
                    foreach ($maybe as $row) {
                        if (is_array($row)) {
                            $intentsOut[] = $row;
                        }
                    }
                }
            } catch (Throwable $ignored) {
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
     * Apply YAML metadata onto a defaults template.
     *
     * Supported YAML keys:
     * - stopType: graceful | graceful_loop | hard
     * - repeat: none | immediate | <minutes as int>
     */
    private static function applyYamlToTemplate(array $defaults, array $yaml): array
    {
        $out = $defaults;

        if (isset($yaml['stopType']) && is_string($yaml['stopType']) && trim($yaml['stopType']) !== '') {
            $out['stopType'] = strtolower(trim($yaml['stopType']));
        }

        if (isset($yaml['repeat'])) {
            if (is_string($yaml['repeat']) && trim($yaml['repeat']) !== '') {
                $out['repeat'] = strtolower(trim($yaml['repeat']));
            } elseif (is_int($yaml['repeat'])) {
                $out['repeat'] = $yaml['repeat'];
            } elseif (is_string($yaml['repeat']) && ctype_digit(trim($yaml['repeat']))) {
                $out['repeat'] = (int)trim($yaml['repeat']);
            }
        }

        return $out;
    }

    private static function extractDescriptionFromEvent(?array $ev): ?string
    {
        if (!$ev) return null;

        foreach (['description', 'DESCRIPTION', 'desc', 'body'] as $k) {
            if (isset($ev[$k]) && is_string($ev[$k]) && trim($ev[$k]) !== '') {
                return trim($ev[$k]);
            }
        }

        return null;
    }

    /**
     * Expand recurring and non-recurring events into concrete
     * occurrences intersecting the horizon.
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
                    'source'     => $ov,
                ];
            }
        }

        if (!$base) {
            return $out;
        }

        $start = new DateTime($base['start']);
        $end   = new DateTime($base['end']);
        $duration = max(0, $end->getTimestamp() - $start->getTimestamp());

        // Non-recurring events
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

        // Recurring events (DAILY / WEEKLY)
        $rrule = $base['rrule'];
        $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
        $interval = max(1, (int)($rrule['INTERVAL'] ?? 1));

        $until = !empty($rrule['UNTIL']) ? self::parseRruleUntil((string)$rrule['UNTIL']) : null;
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
            $base,
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
     * Convert RRULE BYDAY (e.g. "SU,MO,TU") into compact form ("SuMoTu").
     * Returns empty string if BYDAY is missing or invalid.
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
            $tok = preg_replace('/^[+-]?\d+/', '', trim($tok));
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
