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
     * Execute calendar → scheduler pipeline.
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
        // Parse ICS
        // ------------------------------------------------------------
        $parser = new IcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLog::info('Parser returned', [
            'eventCount' => count($events),
        ]);

        if (!$events) {
            $sync = new SchedulerSync($this->dryRun);
            return $sync->sync([]);
        }

        // ------------------------------------------------------------
        // Split base events vs overrides
        // ------------------------------------------------------------
        $baseEvents = [];
        $overridesByKey = [];

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

            // ✅ FIX: correct resolver class name
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            $uid = $event['uid'] ?? null;
            $occurrences = $this->expandOccurrences($event, $now, $horizonEnd);

            foreach ($occurrences as $occ) {
                $intents[] = [
                    'uid'        => $uid,
                    'summary'    => $summary,
                    'type'       => $resolved['type'],
                    'target'     => $resolved['target'],
                    'start'      => $occ['start'],
                    'end'        => $occ['end'],
                    'stopType'   => 'graceful',
                    'repeat'     => 'none',
                    'isOverride' => false,
                    'isAllDay'   => !empty($event['isAllDay']),
                ];
            }
        }

        // ------------------------------------------------------------
        // Consolidate intents into ranges
        // ------------------------------------------------------------
        $consolidator = new IntentConsolidator();
        $ranges = $consolidator->consolidate($intents);

        GcsLog::info('Intent consolidation', [
            'inputIntents' => count($intents),
            'outputRanges' => count($ranges),
            'skipped'      => $consolidator->getSkippedCount(),
            'rangeCount'   => $consolidator->getRangeCount(),
        ]);

        // ------------------------------------------------------------
        // Map ranges → FPP schedule entries
        // ------------------------------------------------------------
        $mapped = [];

        foreach ($ranges as $ri) {
            $entry = FppScheduleMapper::mapRangeIntentToSchedule(
                $this->hydrateRangeIntent($ri)
            );
            if ($entry) {
                $mapped[] = $entry;
                GcsLog::info('Mapped FPP schedule (dry-run)', $entry);
            }
        }

        // ------------------------------------------------------------
        // Phase 8 sync
        // ------------------------------------------------------------
        $sync = new SchedulerSync($this->dryRun);
        return $sync->sync($mapped);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function hydrateRangeIntent(array $ri): array
    {
        $t = $ri['template'];

        return [
            'uid'         => $t['uid'] ?? null,
            'type'        => $t['type'],
            'target'      => $t['target'],
            'start'       => new DateTime($t['start']),
            'end'         => new DateTime($t['end']),
            'stopType'    => $t['stopType'] ?? 'graceful',
            'repeat'      => $t['repeat'] ?? 'none',
            'weekdayMask' => IntentConsolidator::shortDaysToWeekdayMask(
                $ri['range']['days']
            ),
            'startDate'   => $ri['range']['start'],
            'endDate'     => $ri['range']['end'],
        ];
    }

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

    private function expandOccurrences(array $event, DateTime $now, DateTime $horizonEnd): array
    {
        $start = new DateTime($event['start']);
        $end   = new DateTime($event['end']);
        $duration = $end->getTimestamp() - $start->getTimestamp();

        $out = [];

        $s = $start->format('Y-m-d H:i:s');
        $out[] = [
            'start' => $s,
            'end'   => (clone $start)->modify("+{$duration} seconds")->format('Y-m-d H:i:s'),
        ];

        return $out;
    }
}
