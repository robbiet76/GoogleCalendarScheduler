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
            return (new SchedulerSync($this->dryRun))->sync([]);
        }

        // ------------------------------------------------------------
        // Expand to intents
        // ------------------------------------------------------------
        $intents = [];

        foreach ($events as $event) {
            $summary = (string)($event['summary'] ?? '');

            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            $start = new DateTime($event['start']);
            $end   = new DateTime($event['end']);

            // Defaults (Phase 8 behavior)
            $intent = [
                'uid'      => $event['uid'] ?? null,
                'type'     => $resolved['type'],
                'target'   => $resolved['target'],
                'start'    => $start->format('Y-m-d H:i:s'),
                'end'      => $end->format('Y-m-d H:i:s'),
                'stopType' => 'graceful',
                'repeat'   => 'none',
                'enabled'  => true,
            ];

            // Apply YAML metadata (behavior-only)
            $yaml = YamlMetadata::parse($event['description'] ?? null);
            if ($yaml) {
                foreach ($yaml as $k => $v) {
                    // Never allow YAML to override target
                    if ($k === 'target') {
                        continue;
                    }
                    $intent[$k] = $v;
                }
            }

            $intents[] = $intent;
        }

        // ------------------------------------------------------------
        // Consolidate
        // ------------------------------------------------------------
        $consolidator = new IntentConsolidator();
        $ranges = $consolidator->consolidate($intents);

        // ------------------------------------------------------------
        // Map to FPP schedule entries
        // ------------------------------------------------------------
        $mapped = [];

        foreach ($ranges as $ri) {
            $entry = GcsFppScheduleMapper::mapRangeIntentToSchedule(
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
        return (new SchedulerSync($this->dryRun))->sync($mapped);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

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
}
