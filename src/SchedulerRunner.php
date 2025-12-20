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

    public function run(): array
    {
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify("+{$this->horizonDays} days");

        // ------------------------------------------------------------
        // Fetch + parse ICS
        // ------------------------------------------------------------
        $fetcher = new GcsIcsFetcher();
        $ics = $fetcher->fetch($this->cfg['calendar']['ics_url'] ?? '');
        if ($ics === '') {
            return (new GcsSchedulerSync($this->cfg, $this->horizonDays, $this->dryRun))->sync([]);
        }

        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLog::info('Parser returned', [
            'eventCount' => count($events),
        ]);

        if (empty($events)) {
            return (new GcsSchedulerSync($this->cfg, $this->horizonDays, $this->dryRun))->sync([]);
        }

        // ------------------------------------------------------------
        // Build intents
        // ------------------------------------------------------------
        $intents = [];

        foreach ($events as $event) {
            $summary = trim((string)($event['summary'] ?? ''));
            $uid = $event['uid'] ?? null;

            if (!isset($event['start']) || !isset($event['end'])) {
                GcsLog::warn('Skipping event missing start/end', [
                    'uid' => $uid,
                    'summary' => $summary,
                ]);
                continue;
            }

            $start = new DateTime((string)$event['start']);
            $end   = new DateTime((string)$event['end']);

            $intent = [
                'uid'      => $uid,
                'type'     => null,
                'target'   => null,
                'start'    => $start->format('Y-m-d H:i:s'),
                'end'      => $end->format('Y-m-d H:i:s'),
                'stopType' => 'graceful',
                'repeat'   => 'none',
                'enabled'  => true,

                // Command fields
                'command'          => null,
                'args'             => [],
                'multisyncCommand' => false,
            ];

            // YAML (top-level; no fpp: wrapper)
            $yaml = GcsYamlMetadata::parse($event['description'] ?? null);
            if (is_array($yaml)) {
                foreach ($yaml as $k => $v) {
                    if ($k !== 'target') {
                        $intent[$k] = $v;
                    }
                }
            }

            $yamlType = isset($intent['type']) ? trim((string)$intent['type']) : '';

            // Explicit command
            if ($yamlType === 'command') {
                $cmdFromYaml = isset($intent['command']) ? trim((string)$intent['command']) : '';
                $cmd = ($cmdFromYaml !== '') ? $cmdFromYaml : $summary;

                if ($cmd === '') {
                    GcsLog::warn('Command event missing command name', [
                        'uid' => $uid,
                    ]);
                    continue;
                }

                if ($cmdFromYaml === '') {
                    GcsLog::info('Command name fell back to event summary', [
                        'uid' => $uid,
                        'command' => $cmd,
                    ]);
                }

                $intent['type'] = 'command';
                $intent['target'] = '';
                $intent['command'] = $cmd;
                $intent['args'] = is_array($intent['args']) ? $intent['args'] : [];
                $intent['multisyncCommand'] = !empty($intent['multisyncCommand']);

                $intents[] = $intent;
                continue;
            }

            // Playlist / sequence
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            $intent['type']   = $resolved['type'];
            $intent['target'] = $resolved['target'];

            $intents[] = $intent;
        }

        // ------------------------------------------------------------
        // Consolidate + map to FPP schedule entries
        // ------------------------------------------------------------
        $consolidator = new GcsIntentConsolidator();
        $ranges = $consolidator->consolidate($intents);

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
        // Diff + apply using mapped desired entries
        // ------------------------------------------------------------
        return (new GcsSchedulerSync($this->cfg, $this->horizonDays, $this->dryRun))->sync($mapped);
    }

    private function hydrateRangeIntent(array $ri): array
    {
        $t = $ri['template'];

        return [
            'uid'         => $t['uid'] ?? null,
            'type'        => $t['type'],
            'target'      => $t['target'] ?? '',
            'start'       => new DateTime($t['start']),
            'end'         => new DateTime($t['end']),
            'stopType'    => $t['stopType'] ?? 'graceful',
            'repeat'      => $t['repeat'] ?? 'none',
            'enabled'     => array_key_exists('enabled', $t) ? (bool)$t['enabled'] : true,

            'command'          => $t['command'] ?? null,
            'args'             => (isset($t['args']) && is_array($t['args'])) ? $t['args'] : [],
            'multisyncCommand' => !empty($t['multisyncCommand']),

            'weekdayMask' => GcsIntentConsolidator::shortDaysToWeekdayMask(
                $ri['range']['days']
            ),
            'startDate'   => $ri['range']['start'],
            'endDate'     => $ri['range']['end'],
        ];
    }
}
