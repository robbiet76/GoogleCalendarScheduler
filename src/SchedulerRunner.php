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
            $start = new DateTime((string)$event['start']);
            $end   = new DateTime((string)$event['end']);

            // Defaults (Phase 8 behavior)
            $intent = [
                'uid'      => $event['uid'] ?? null,
                'type'     => null,    // determined below
                'target'   => null,    // for playlist/sequence
                'start'    => $start->format('Y-m-d H:i:s'),
                'end'      => $end->format('Y-m-d H:i:s'),
                'stopType' => 'graceful',
                'repeat'   => 'none',
                'enabled'  => true,

                // Phase 10 command fields (only used when type=command)
                'command'          => null,
                'args'             => [],
                'multisyncCommand' => false,
            ];

            // Parse YAML first (it may override type)
            $yaml = YamlMetadata::parse($event['description'] ?? null);
            if ($yaml) {
                // Apply safe overrides (never override target from YAML)
                foreach ($yaml as $k => $v) {
                    if ($k === 'target') {
                        continue;
                    }
                    $intent[$k] = $v;
                }
            }

            $yamlType = isset($intent['type']) ? (string)$intent['type'] : '';

            // --------------------------------------------------------
            // Resolve type/target
            // --------------------------------------------------------

            // If YAML forces command, we do NOT resolve by summary
            if ($yamlType === 'command') {
                $cmd = isset($intent['command']) ? trim((string)$intent['command']) : '';
                if ($cmd === '') {
                    GcsLog::error('Command event missing required YAML "command" field', [
                        'summary' => $summary,
                        'uid' => $intent['uid'] ?? null,
                    ]);
                    continue;
                }

                // Ensure args is an array; preserve nulls/strings exactly
                if (!isset($intent['args']) || !is_array($intent['args'])) {
                    $intent['args'] = [];
                }

                // Normalize multisyncCommand
                $intent['multisyncCommand'] = !empty($intent['multisyncCommand']);

                $intent['type'] = 'command';
                $intent['target'] = ''; // unused for commands
                $intent['command'] = $cmd;

            } else {
                // Normal path: resolve from summary (playlist first, then sequence)
                $resolved = GcsTargetResolver::resolve($summary);
                if (!$resolved) {
                    // If YAML tried to force playlist/sequence but we can't resolve, log and skip.
                    if ($yamlType === 'playlist' || $yamlType === 'sequence') {
                        GcsLog::error('YAML type override specified but target not found', [
                            'type' => $yamlType,
                            'summary' => $summary,
                            'uid' => $intent['uid'] ?? null,
                        ]);
                    }
                    continue;
                }

                // If YAML overrides to sequence/playlist, apply it if valid
                if ($yamlType === 'playlist' || $yamlType === 'sequence') {
                    $override = $this->applyTypeOverride($yamlType, $summary);
                    if ($override) {
                        $resolved = $override;
                        GcsLog::info('Applied YAML type override', [
                            'type' => $resolved['type'],
                            'target' => $resolved['target'],
                        ]);
                    } else {
                        GcsLog::error('Invalid YAML type override; keeping resolver result', [
                            'type' => $yamlType,
                            'summary' => $summary,
                        ]);
                    }
                }

                $intent['type']   = $resolved['type'];
                $intent['target'] = $resolved['target'];
            }

            // Fill defaults if YAML omitted them
            if (!isset($intent['repeat'])) {
                $intent['repeat'] = 'none';
            }
            if (!isset($intent['stopType'])) {
                $intent['stopType'] = 'graceful';
            }
            if (!isset($intent['enabled'])) {
                $intent['enabled'] = true;
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
        // Sync (diff + apply)
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
            'target'      => $t['target'] ?? '',
            'start'       => new DateTime($t['start']),
            'end'         => new DateTime($t['end']),
            'stopType'    => $t['stopType'] ?? 'graceful',
            'repeat'      => $t['repeat'] ?? 'none',
            'enabled'     => array_key_exists('enabled', $t) ? (bool)$t['enabled'] : true,

            // Command fields (only relevant for type=command)
            'command'          => $t['command'] ?? null,
            'args'             => (isset($t['args']) && is_array($t['args'])) ? $t['args'] : [],
            'multisyncCommand' => !empty($t['multisyncCommand']),

            'weekdayMask' => IntentConsolidator::shortDaysToWeekdayMask(
                $ri['range']['days']
            ),
            'startDate'   => $ri['range']['start'],
            'endDate'     => $ri['range']['end'],
        ];
    }

    /**
     * Apply a YAML type override by validating that the summary exists as that type.
     * Returns ['type'=>..., 'target'=>...] or null if invalid.
     */
    private function applyTypeOverride(string $type, string $summary): ?array
    {
        $base = trim($summary);
        if ($base === '') {
            return null;
        }

        if ($type === 'playlist') {
            // Use resolver's existence check implicitly by asking it to resolve;
            // but force it by checking playlist path the same way TargetResolver does.
            $dirBased  = "/home/fpp/media/playlists/$base/playlist.json";
            $fileBased = "/home/fpp/media/playlists/$base.json";
            if (is_file($dirBased) || is_file($fileBased)) {
                return ['type' => 'playlist', 'target' => $base];
            }
            return null;
        }

        if ($type === 'sequence') {
            $seq = (substr($base, -5) === '.fseq') ? $base : $base . '.fseq';
            if (is_file("/home/fpp/media/sequences/$seq")) {
                return ['type' => 'sequence', 'target' => $seq];
            }
            return null;
        }

        return null;
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
