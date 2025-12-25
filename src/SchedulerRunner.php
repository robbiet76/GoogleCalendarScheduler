<?php

/**
 * GcsSchedulerRunner
 *
 * Top-level orchestrator:
 * - fetch ICS
 * - parse events within horizon
 * - resolve target/intent
 * - consolidate
 * - sync (dry-run safe)
 */
final class GcsSchedulerRunner
{
    private array $cfg;
    private int $horizonDays;
    private bool $dryRun;

    public function __construct(array $cfg, int $horizonDays, bool $dryRun)
    {
        $this->cfg = $cfg;
        $this->horizonDays = $horizonDays;
        $this->dryRun = (bool)$dryRun;
    }

    public function run(): array
    {
        $summary = [
            'component'        => 'SchedulerRunner',
            'phase'            => '14.2.1',
            'dryRun'           => $this->dryRun,
            'events_seen'      => 0,
            'events_expanded'  => 0,
            'intents_built'    => 0,
            'ranges_created'   => 0,
            'adds'             => 0,
            'updates'          => 0,
            'deletes'          => 0,
            'result'           => 'unknown',
            'reason'           => null,
        ];

        $icsUrl = trim((string)($this->cfg['calendar']['ics_url'] ?? ''));
        if ($icsUrl === '') {
            $summary['result'] = 'no-op';
            $summary['reason'] = 'missing_ics_url';
            GcsLogger::instance()->info('Scheduler run summary', $summary);
            return $this->emptyResult();
        }

        // Fetch ICS
        $ics = (new GcsIcsFetcher())->fetch($icsUrl);
        if ($ics === '') {
            $summary['result'] = 'no-op';
            $summary['reason'] = 'empty_ics';
            GcsLogger::instance()->info('Scheduler run summary', $summary);
            return $this->emptyResult();
        }

        // Parse
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify('+' . $this->horizonDays . ' days');

        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        $summary['events_seen'] = is_array($events) ? count($events) : 0;

        if (empty($events)) {
            $summary['result'] = 'no-op';
            $summary['reason'] = 'no_events_in_horizon';
            GcsLogger::instance()->info('Scheduler run summary', $summary);
            return $this->emptyResult();
        }

        // Build intents
        $rawIntents = [];

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }

            if (!empty($ev['isAllDay'])) {
                continue;
            }

            $summaryText = (string)($ev['summary'] ?? '');
            $resolved = GcsTargetResolver::resolve($summaryText);
            if (!$resolved) {
                continue;
            }

            $rawIntents[] = [
                'uid'     => (string)($ev['uid'] ?? ''),
                'summary' => $summaryText,
                'type'    => (string)$resolved['type'],
                'target'  => $resolved['target'],
                'start'   => (string)$ev['start'],
                'end'     => (string)$ev['end'],
                'stopType'=> 'graceful',
                'repeat'  => 'none',
            ];
        }

        $summary['intents_built'] = count($rawIntents);

        if (empty($rawIntents)) {
            $summary['result'] = 'no-op';
            $summary['reason'] = 'no_resolvable_targets';
            GcsLogger::instance()->info('Scheduler run summary', $summary);
            return $this->emptyResult();
        }

        // Consolidate
        $consolidated = $rawIntents;
        try {
            $consolidator = new GcsIntentConsolidator();
            $maybe = $consolidator->consolidate($rawIntents);
            if (is_array($maybe)) {
                $consolidated = $maybe;
                $summary['ranges_created'] = count($consolidated);
            }
        } catch (Throwable $ignored) {}

        // Sync
        $sync = new SchedulerSync($this->dryRun);
        $result = $sync->sync($consolidated);

        $summary['adds']    = (int)($result['adds']    ?? 0);
        $summary['updates'] = (int)($result['updates'] ?? 0);
        $summary['deletes'] = (int)($result['deletes'] ?? 0);

        if (
            $summary['adds'] === 0 &&
            $summary['updates'] === 0 &&
            $summary['deletes'] === 0
        ) {
            $summary['result'] = 'no-op';
            $summary['reason'] = $this->dryRun
                ? 'dry_run_preview_only'
                : 'no_changes_detected';
        } else {
            $summary['result'] = 'changes_detected';
        }

        GcsLogger::instance()->info('Scheduler run summary', $summary);

        return $result;
    }

    private function emptyResult(): array
    {
        return [
            'adds'    => 0,
            'updates' => 0,
            'deletes' => 0,
            'dryRun'  => $this->dryRun,
        ];
    }
}
