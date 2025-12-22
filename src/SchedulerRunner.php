<?php

<<<<<<< HEAD
final class GcsSchedulerRunner
=======
final class SchedulerRunner
>>>>>>> master
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
<<<<<<< HEAD
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify("+{$this->horizonDays} days");

        // ------------------------------------------------------------
        // Fetch + parse ICS
        // ------------------------------------------------------------
        $fetcher = new GcsIcsFetcher();
        $ics = $fetcher->fetch($this->cfg['calendar']['ics_url'] ?? '');
        if ($ics === '') {
            GcsLog::warn('ICS fetch returned empty string');
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
        $intentTypeCounts = [
            'command'  => 0,
            'playlist' => 0,
            'sequence' => 0,
            'unknown'  => 0,
        ];

        foreach ($events as $event) {
            $summary = trim((string)($event['summary'] ?? ''));
            $uid = $event['uid'] ?? null;

            if (!isset($event['start']) || !isset($event['end'])) {
                GcsLog::warn('Skipping event missing start/end', [
                    'uid' => $uid,
                    'summary' => $summary,
=======
        $icsUrl = trim($this->cfg['calendar']['ics_url'] ?? '');
        if ($icsUrl === '') {
            GcsLogger::instance()->warn('No ICS URL configured');
            return $this->emptyResult();
        }

        // 1. Fetch ICS
        $ics = (new IcsFetcher())->fetch($icsUrl);
        if ($ics === '') {
            return $this->emptyResult();
        }

        // 2. Parse ICS
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify('+' . $this->horizonDays . ' days');

        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLogger::instance()->info('Parser returned', [
            'eventCount' => count($events)
        ]);

        // 3. Group by UID
        $groups = [];
        foreach ($events as $ev) {
            $uid = $ev['uid'] ?? null;
            if (!$uid) {
                continue;
            }

            $groups[$uid] ??= [
                'base' => null,
                'overrides' => [],
            ];

            if (!empty($ev['isOverride'])) {
                $groups[$uid]['overrides'][$ev['recurrenceId']] = $ev;
            } else {
                $groups[$uid]['base'] = $ev;
            }
        }

        $rawIntents = [];

        // 4. Process each UID group
        foreach ($groups as $uid => $group) {
            $base = $group['base'];
            $overrides = $group['overrides'];

            if (!$base) {
                continue;
            }

            // Resolve target
            $resolved = GcsTargetResolver::resolve($base['summary'] ?? '');
            if (!$resolved) {
                GcsLogger::instance()->warn('Unresolved target', [
                    'uid' => $uid,
                    'summary' => $base['summary'] ?? ''
>>>>>>> master
                ]);
                continue;
            }

<<<<<<< HEAD
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

            $yamlType = isset($intent['type']) ? strtolower(trim((string)$intent['type'])) : '';

            // --------------------------------------------------------
            // Explicit command
            // --------------------------------------------------------
            if ($yamlType === 'command') {
                $cmdFromYaml = isset($intent['command']) ? trim((string)$intent['command']) : '';
                $cmd = ($cmdFromYaml !== '') ? $cmdFromYaml : $summary;

                if ($cmd === '') {
                    GcsLog::warn('Command event missing command name', [
                        'uid' => $uid,
                        'summary' => $summary,
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
                $intentTypeCounts['command']++;
                continue;
            }

            // --------------------------------------------------------
            // Playlist / sequence
            // --------------------------------------------------------
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                $intentTypeCounts['unknown']++;
                continue;
            }

            $intent['type']   = $resolved['type'];
            $intent['target'] = $resolved['target'];

            if ($intent['type'] === 'playlist') {
                $intentTypeCounts['playlist']++;
            } elseif ($intent['type'] === 'sequence') {
                $intentTypeCounts['sequence']++;
            } else {
                $intentTypeCounts['unknown']++;
            }

            $intents[] = $intent;
        }

        GcsLog::info('Intent build summary', [
            'intentCount' => count($intents),
            'byType' => $intentTypeCounts,
        ]);

        // ------------------------------------------------------------
        // Consolidate + map to desired scheduler entries
        // ------------------------------------------------------------
        $consolidator = new GcsIntentConsolidator();
        $ranges = $consolidator->consolidate($intents);

        GcsLog::info('Intent consolidation', [
            'inputIntents' => count($intents),
            'rangeCount' => count($ranges),
        ]);

        $mapped = [];
        $mappedNulls = 0;

        foreach ($ranges as $ri) {
            $entry = GcsFppScheduleMapper::mapRangeIntentToSchedule(
                $this->hydrateRangeIntent($ri)
            );

            if ($entry) {
                $mapped[] = $entry;
                GcsLog::info('Mapped FPP schedule (dry-run)', $entry);
            } else {
                $mappedNulls++;
            }
        }

        GcsLog::info('Mapping summary', [
            'mappedCount' => count($mapped),
            'nullMappings' => $mappedNulls,
        ]);

        // ------------------------------------------------------------
        // Diff + apply using desired entries
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

            // Command fields
            'command'          => $t['command'] ?? null,
            'args'             => (isset($t['args']) && is_array($t['args'])) ? $t['args'] : [],
            'multisyncCommand' => !empty($t['multisyncCommand']),

            'weekdayMask' => GcsIntentConsolidator::shortDaysToWeekdayMask(
                $ri['range']['days']
            ),
            'startDate'   => $ri['range']['start'],
            'endDate'     => $ri['range']['end'],
=======
            $hasRrule = !empty($base['rrule']);
            $hasExdates = !empty($base['exDates']);
            $hasOverrides = !empty($overrides);

            $collapsible =
                $hasRrule &&
                !$hasExdates &&
                !$hasOverrides;

            GcsLogger::instance()->info('Event classified', [
                'uid' => $uid,
                'collapsible' => $collapsible,
                'hasRrule' => $hasRrule,
                'exdateCount' => count($base['exDates'] ?? []),
                'overrideCount' => count($overrides),
            ]);

            if ($collapsible) {
                // Single intent (later consolidated to range)
                $rawIntents[] = $this->buildIntent(
                    $uid,
                    $base,
                    $resolved,
                    new DateTime($base['start']),
                    new DateTime($base['end'])
                );
                continue;
            }

            // 5. Expand RRULE if present
            $occurrences = $hasRrule
                ? $this->expandRrule($base, $now, $horizonEnd)
                : [new DateTime($base['start'])];

            // Apply EXDATE filtering
            $exdateSet = [];
            foreach ($base['exDates'] ?? [] as $ex) {
                $exdateSet[$ex] = true;
            }

            foreach ($occurrences as $occStart) {
                $key = $occStart->format('Y-m-d H:i:s');
                if (isset($exdateSet[$key])) {
                    continue;
                }

                // Override?
                if (isset($overrides[$key])) {
                    $ov = $overrides[$key];
                    $rawIntents[] = $this->buildIntent(
                        $uid,
                        $ov,
                        $resolved,
                        new DateTime($ov['start']),
                        new DateTime($ov['end'])
                    );
                    continue;
                }

                // Normal expanded instance
                $dur = strtotime($base['end']) - strtotime($base['start']);
                $occEnd = (clone $occStart)->modify('+' . $dur . ' seconds');

                $rawIntents[] = $this->buildIntent(
                    $uid,
                    $base,
                    $resolved,
                    $occStart,
                    $occEnd
                );
            }
        }

        // 6. Consolidate
        $consolidator = new GcsIntentConsolidator();
        $consolidated = $consolidator->consolidate($rawIntents);

        // 7. Sync (dry-run safe)
        $sync = new SchedulerSync($this->dryRun);
        return $sync->sync($consolidated);
    }

    /* ---------------- helpers ---------------- */

    private function buildIntent(
        string $uid,
        array $event,
        array $resolved,
        DateTime $start,
        DateTime $end
    ): array {
        return [
            'uid'     => $uid,
            'summary' => $event['summary'] ?? '',
            'type'    => $resolved['type'],
            'target'  => $resolved['target'],
            'start'   => $start->format('Y-m-d H:i:s'),
            'end'     => $end->format('Y-m-d H:i:s'),
            'stopType'=> 'graceful',
            'repeat'  => 'none',
        ];
    }

    private function expandRrule(array $base, DateTime $from, DateTime $to): array
    {
        // Minimal RRULE expansion (already proven in earlier phases)
        // Assumes DTSTART already normalized

        $out = [];
        $start = new DateTime($base['start']);

        $rrule = $base['rrule'] ?? [];
        $freq = strtoupper($rrule['FREQ'] ?? '');

        if ($freq !== 'DAILY' && $freq !== 'WEEKLY') {
            return [$start];
        }

        $interval = intval($rrule['INTERVAL'] ?? 1);
        $cursor = clone $start;

        while ($cursor <= $to) {
            if ($cursor >= $from) {
                $out[] = clone $cursor;
            }
            $cursor->modify($freq === 'DAILY'
                ? "+{$interval} days"
                : "+{$interval} weeks"
            );
        }

        return $out;
    }

    private function emptyResult(): array
    {
        return [
            'adds' => 0,
            'updates' => 0,
            'deletes' => 0,
            'dryRun' => $this->dryRun,
            'intents_seen' => 0,
>>>>>>> master
        ];
    }
}
