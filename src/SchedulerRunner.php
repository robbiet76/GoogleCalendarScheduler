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
        // Parse ICS
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
        // Expand to per-occurrence intents (lossless overrides)
        // ------------------------------------------------------------
        $intents = [];

        foreach ($baseEvents as $event) {
            $summary = (string)($event['summary'] ?? '');
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            $uid = $event['uid'] ?? null;
            $baseOcc = $this->expandOccurrences($event, $now, $horizonEnd);

            $finalOcc = [];
            $overrideOcc = [];

            foreach ($baseOcc as $occ) {
                $key = ($uid ? ($uid . '|' . $occ['start']) : null);

                if ($key && isset($overridesByKey[$key])) {
                    $ov = $overridesByKey[$key];
                    $ovStart = (string)($ov['start'] ?? '');
                    $ovEnd   = (string)($ov['end'] ?? '');

                    if ($ovStart === $occ['start'] && $ovEnd === $occ['end']) {
                        $finalOcc[] = $occ;
                    } else {
                        if ($ovStart && $ovEnd) {
                            $overrideOcc[] = [
                                'start' => $ovStart,
                                'end'   => $ovEnd,
                                'isOverride' => true,
                            ];
                        }
                    }
                } else {
                    $finalOcc[] = $occ;
                }
            }

            foreach ($finalOcc as $occ) {
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

            foreach ($overrideOcc as $occ) {
                $intents[] = [
                    'uid'        => $uid,
                    'summary'    => $summary,
                    'type'       => $resolved['type'],
                    'target'     => $resolved['target'],
                    'start'      => $occ['start'],
                    'end'        => $occ['end'],
                    'stopType'   => 'graceful',
                    'repeat'     => 'none',
                    'isOverride' => true,
                    'isAllDay'   => !empty($event['isAllDay']),
                ];
            }
        }

        // ------------------------------------------------------------
        // Consolidate intents into ranges
        // ------------------------------------------------------------
        $consolidator = new IntentConsolidator();
        $ranges = $consolidator->consolidate($intents);

        GcsLogger::instance()->info('Intent consolidation', [
            'inputIntents' => count($intents),
            'outputRanges' => count($ranges),
            'skipped'      => $consolidator->getSkippedCount(),
            'rangeCount'   => $consolidator->getRangeCount(),
        ]);

        // ------------------------------------------------------------
        // Phase 7.1A: Map ranges → FPP schedule entries (log only)
        // ------------------------------------------------------------
        $mapped = [];

        foreach ($ranges as $ri) {
            $entry = GcsFppScheduleMapper::mapRangeIntentToSchedule($this->hydrateRangeIntent($ri));
            if ($entry) {
                $mapped[] = $entry;
                GcsLogger::instance()->info('Mapped FPP schedule (dry-run)', $entry);
            }
        }

        // ------------------------------------------------------------
        // Phase 8 (NEW): Diff desired vs existing + dry-run log actions
        // ------------------------------------------------------------
        try {
            $existing = SchedulerState::loadExisting(); // ExistingScheduleEntry[]
            $desiredComparable = [];

            foreach ($mapped as $entry) {
                $ce = $this->mappedEntryToComparable($entry);
                if ($ce) {
                    $desiredComparable[] = $ce;
                }
            }

            $diff = SchedulerDiff::compute($desiredComparable, $existing);

            // Extra summary log (helpful when tailing)
            GcsLogger::instance()->info('Phase 8 diff summary (dry-run)', [
                'create' => count($diff->create),
                'update' => count($diff->update),
                'delete' => count($diff->delete),
                'noop'   => count($diff->noop),
            ]);

            SchedulerApply::dryRun($diff);
        } catch (Throwable $t) {
            // Never break the runner due to Phase 8 wiring problems
            GcsLogger::instance()->warn('Phase 8 diff wiring failed (continuing)', [
                'error' => $t->getMessage(),
            ]);
        }

        // ------------------------------------------------------------
        // Still dry-run only (existing behavior)
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
            'weekdayMask' => GcsIntentConsolidator::shortDaysToWeekdayMask($ri['range']['days']),
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

    /**
     * Convert a mapped FPP schedule entry (array) into a ComparableScheduleEntry.
     * This keeps Phase 8 independent from mapper internals while still matching
     * existing scheduler entries by UID.
     */
    private function mappedEntryToComparable(array $entry): ?ComparableScheduleEntry
    {
        $playlist = (string)($entry['playlist'] ?? '');
        $uid = $this->extractUidFromPlaylist($playlist);
        if (!$uid) {
            return null;
        }

        $startDate = (string)($entry['startDate'] ?? '');
        $endDate   = (string)($entry['endDate'] ?? '');
        $startTime = (string)($entry['startTime'] ?? '00:00:00');
        $endTime   = (string)($entry[']()
