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

            // ------------------------------------------------------------
            // Apply YAML metadata (behavior-only)
            // Track whether YAML explicitly set "type"
            // ------------------------------------------------------------
            $yaml = YamlMetadata::parse($event['description'] ?? null);
            $yamlType = null;

            if ($yaml) {
                foreach ($yaml as $k => $v) {
                    // Never allow YAML to override target
                    if ($k === 'target') {
                        continue;
                    }

                    $intent[$k] = $v;

                    if ($k === 'type' && is_string($v)) {
                        $yamlType = strtolower(trim($v));
                    }
                }
            }

            // ------------------------------------------------------------
            // YAML type override (ONLY if explicitly provided)
            // ------------------------------------------------------------
            if ($yamlType !== null) {
                if ($yamlType === 'playlist') {
                    // Resolver already prefers playlist.
                    // If resolver picked sequence, ensure playlist exists.
                    if ($resolved['type'] !== 'playlist') {
                        $name = trim($summary);
                        if ($name !== '' && $this->playlistExists($name)) {
                            $intent['type'] = 'playlist';
                            $intent['target'] = $name;
                            GcsLog::info('Applied YAML type override', [
                                'type' => 'playlist',
                                'target' => $name,
                            ]);
                        } else {
                            GcsLog::info('Ignored YAML type override (playlist not found)', [
                                'summary' => $summary,
                            ]);
                            $intent['type'] = $resolved['type'];
                            $intent['target'] = $resolved['target'];
                        }
                    }
                } elseif ($yamlType === 'sequence') {
                    $name = trim($summary);
                    $seq = (substr($name, -5) === '.fseq') ? $name : ($name . '.fseq');

                    if ($seq !== '' && $this->sequenceExists($seq)) {
                        $intent['type'] = 'sequence';
                        $intent['target'] = $seq;
                        GcsLog::info('Applied YAML type override', [
                            'type' => 'sequence',
                            'target' => $seq,
                        ]);
                    } else {
                        GcsLog::info('Ignored YAML type override (sequence not found)', [
                            'summary' => $summary,
                            'expected' => $seq,
                        ]);
                        $intent['type'] = $resolved['type'];
                        $intent['target'] = $resolved['target'];
                    }
                } elseif ($yamlType === 'command') {
                    GcsLog::info('Ignored YAML type override (command not supported yet)', [
                        'summary' => $summary,
                    ]);
                    $intent['type'] = $resolved['type'];
                    $intent['target'] = $resolved['target'];
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
            'enabled'     => $t['enabled'] ?? true,
            'weekdayMask' => IntentConsolidator::shortDaysToWeekdayMask(
                $ri['range']['days']
            ),
            'startDate'   => $ri['range']['start'],
            'endDate'     => $ri['range']['end'],
        ];
    }

    private function playlistExists(string $name): bool
    {
        $dirBased  = "/home/fpp/media/playlists/$name/playlist.json";
        $fileBased = "/home/fpp/media/playlists/$name.json";

        return is_file($dirBased) || is_file($fileBased);
    }

    private function sequenceExists(string $name): bool
    {
        return is_file("/home/fpp/media/sequences/$name");
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
