<?php

final class SchedulerRunner
{
    private array $cfg;
    private int $horizonDays;
    private bool $dryRun;

    public function __construct(array $cfg, int $horizonDays, bool $dryRun)
    {
        $this->cfg = $cfg;
        $this->horizonDays = $horizonDays;
        $this->dryRun = $dryRun;
    }

    /**
     * Execute calendar → scheduler pipeline (dry-run).
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify("+{$this->horizonDays} days");

        // ------------------------------------------------------------
        // Fetch ICS
        // ------------------------------------------------------------
        $fetcher = new IcsFetcher();
        $ics = $fetcher->fetch($this->cfg['calendar']['ics_url'] ?? '');
        if ($ics === '') {
            return $this->emptyResult();
        }

        // ------------------------------------------------------------
        // Parse ICS (recurrence metadata already handled)
        // ------------------------------------------------------------
        $parser = new IcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLogger::instance()->info('Parser returned', [
            'eventCount' => count($events),
        ]);

        if (!$events) {
            $sync = new SchedulerSync($this->dryRun);
            return $sync->sync([]);
        }

        // ------------------------------------------------------------
        // Split base events vs overrides (RECURRENCE-ID)
        // ------------------------------------------------------------
        $baseEvents = [];
        $overridesByKey = []; // uid|start

        foreach ($events as $e) {
            if (!empty($e['isOverride']) && !empty($e['uid']) && !empty($e['recurrenceId'])) {
                $overridesByKey[$e['uid'] . '|' . $e['recurrenceId']] = $e;
            } else {
                $baseEvents[] = $e;
            }
        }

        // ------------------------------------------------------------
        // Expand to per-occurrence intents
        // ------------------------------------------------------------
        $intents = [];

        foreach ($baseEvents as $event) {
            $summary = (string)($event['summary'] ?? '');
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            $occurrences = $this->expandOccurrences($event, $now, $horizonEnd);

            // Apply overrides (R5)
            $uid = $event['uid'] ?? null;
            if ($uid) {
                foreach ($occurrences as &$occ) {
                    $key = $uid . '|' . $occ['start'];
                    if (isset($overridesByKey[$key])) {
                        $ov = $overridesByKey[$key];
                        $occ['start'] = (string)$ov['start'];
                        $occ['end']   = (string)$ov['end'];
                        $occ['isOverride'] = true;
                    }
                }
                unset($occ);
            }

            foreach ($occurrences as $occ) {
                $intents[] = [
                    'uid'        => $event['uid'] ?? null,
                    'summary'    => $summary,
                    'type'       => $resolved['type'],
                    'target'     => $resolved['target'],
                    'start'      => $occ['start'],
                    'end'        => $occ['end'],
                    'stopType'   => 'graceful',
                    'repeat'     => 'none',
                    'isOverride' => !empty($occ['isOverride']),
                    'isAllDay'   => !empty($event['isAllDay']),
                ];
            }
        }

        // ------------------------------------------------------------
        // Phase 7: Consolidate per-occurrence intents
        // ------------------------------------------------------------
        $consolidator = new IntentConsolidator();
        $consolidated = $consolidator->consolidate($intents);

        GcsLogger::instance()->info('Intent consolidation', [
            'inputIntents' => count($intents),
            'outputRanges' => count($consolidated),
            'skipped'      => $consolidator->getSkippedCount(),
            'rangeCount'   => $consolidator->getRangeCount(),
        ]);

        // ------------------------------------------------------------
        // Dry-run sync (still no scheduler writes)
        // ------------------------------------------------------------
        $sync = new SchedulerSync($this->dryRun);
        return $sync->sync($consolidated);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function emptyResult(): array
    {
        return [
            'adds'         => 0,
            'updates'      => 0,
            'deletes'      => 0,
            'dryRun'       => $this->dryRun,
            'intents_seen' => 0,
        ];
    }

    /**
     * Expand an event into per-occurrence start/end pairs.
     *
     * @return array<int,array{start:string,end:string,isOverride?:bool}>
     */
    private function expandOccurrences(array $event, DateTime $now, DateTime $horizonEnd): array
    {
        $start = new DateTime($event['start']);
        $end   = new DateTime($event['end']);
        $duration = $end->getTimestamp() - $start->getTimestamp();

        $exSet = [];
        foreach (($event['exDates'] ?? []) as $ex) {
            $exSet[$ex] = true;
        }

        $rrule = $event['rrule'] ?? null;

        // Non-recurring
        if (!$rrule || empty($rrule['FREQ'])) {
            $s = $start->format('Y-m-d H:i:s');
            if (isset($exSet[$s])) {
                return [];
            }
            return [[
                'start' => $s,
                'end'   => (clone $start)->modify("+{$duration} seconds")->format('Y-m-d H:i:s'),
            ]];
        }

        $out = [];
        $count = isset($rrule['COUNT']) ? (int)$rrule['COUNT'] : null;
        $interval = isset($rrule['INTERVAL']) ? max(1, (int)$rrule['INTERVAL']) : 1;

        if ($rrule['FREQ'] === 'DAILY') {
            $i = 0;
            $cur = clone $start;

            while (true) {
                $i++;
                if ($count !== null && $i > $count) {
                    break;
                }
                if ($cur > $horizonEnd) {
                    break;
                }

                $s = $cur->format('Y-m-d H:i:s');
                if (!isset($exSet[$s])) {
                    $out[] = [
                        'start' => $s,
                        'end'   => (clone $cur)->modify("+{$duration} seconds")->format('Y-m-d H:i:s'),
                    ];
                }

                $cur->modify("+{$interval} day");
            }
        }

        return $out;
    }
}
