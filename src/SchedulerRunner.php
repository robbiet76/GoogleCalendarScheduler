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

        // Fetch ICS
        $fetcher = new IcsFetcher();
        $ics = $fetcher->fetch($this->cfg['calendar']['ics_url'] ?? '');
        if ($ics === '') {
            return (new SchedulerSync($this->dryRun))->sync([]);
        }

        // Parse ICS
        $parser = new IcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLog::info('Parser returned', [
            'eventCount' => count($events),
        ]);

        if (empty($events)) {
            return (new SchedulerSync($this->dryRun))->sync([]);
        }

        // Build intents
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

            // Base intent (Phase 11 defaults)
            $intent = [
                'uid'      => $uid,
                'type'     => null,
                'target'   => null,
                'start'    => $start->format('Y-m-d H:i:s'),
                'end'      => $end->format('Y-m-d H:i:s'),
                'stopType' => 'graceful',
                'repeat'   => 'none',
                'enabled'  => true,

                // Command fields (used only when type=command)
                'command'          => null,
                'args'             => [],
                'multisyncCommand' => false,
            ];

            // Parse YAML metadata (top-level, no fpp: wrapper)
            $yaml = YamlMetadata::parse($event['description'] ?? null);
            if (is_array($yaml)) {
                foreach ($yaml as $k => $v) {
                    // Never allow YAML to override target directly
                    if ($k === 'target') {
                        continue;
                    }
                    $intent[$k] = $v;
                }
            }

            $yamlType = isset($intent['type']) ? trim((string)$intent['type']) : '';

            // --------------------------------------------------------
            // Command events (explicit only)
            // --------------------------------------------------------
            if ($yamlType === 'command') {
                $cmdFromYaml = isset($intent['command']) ? trim((string)$intent['command']) : '';
                $cmd = ($cmdFromYaml !== '') ? $cmdFromYaml : $summary;

                if ($cmd === '') {
                    GcsLog::warn('Command event missing command name (YAML "command" or event summary)', [
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
                $intent['target'] = ''; // unused for commands
                $intent['command'] = $cmd;
                $intent['args'] = is_array($intent['args']) ? $intent['args'] : [];
                $intent['multisyncCommand'] = !empty($intent['multisyncCommand']);

                $intents[] = $intent;
                continue;
            }

            // --------------------------------------------------------
            // Playlist / sequence resolution (unchanged)
            // --------------------------------------------------------
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            // Allow YAML type override for playlist/sequence if valid
            if ($yamlType === 'playlist' || $yamlType === 'sequence') {
                $override = $this->applyTypeOverride($yamlType, $summary);
                if ($override) {
                    $resolved = $override;
                }
            }

            $intent['type']   = $resolved['type'];
            $intent['target'] = $resolved['target'];

            $intents[] = $intent;
        }

        // Consolidate intents
        $consolidator = new IntentConsolidator();
        $ranges = $consolidator->consolidate($intents);

        // Map to FPP scheduler entries
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

        // Diff + apply
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

            // Command fields
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
     * Validate a YAML type override for playlist/sequence.
     */
    private function applyTypeOverride(string $type, string $summary): ?array
    {
        $base = trim($summary);
        if ($base === '') {
            return null;
        }

        if ($type === 'playlist') {
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
}
