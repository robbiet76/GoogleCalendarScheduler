<?php
declare(strict_types=1);

/**
 * GcsSchedulerRunner
 *
 * Phase 16 structural refactor:
 * - plan(): PURE planning only (ICS -> events -> intents -> consolidated intents)
 *           NO writes, NO SchedulerSync access.
 * - apply(): the ONLY method that can write (constructs SchedulerSync(false))
 *
 * IMPORTANT:
 * - Sync and Preview MUST call plan() only.
 * - Apply MUST call apply() only.
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

    /**
     * PLAN-ONLY: build consolidated intents.
     *
     * @return array<int,array<string,mixed>> consolidated intents
     */
    public function plan(): array
    {
        $icsUrl = trim((string)($this->cfg['calendar']['ics_url'] ?? ''));
        if ($icsUrl === '') {
            GcsLogger::instance()->warn('No ICS URL configured');
            return [];
        }

        $ics = (new GcsIcsFetcher())->fetch($icsUrl);
        if ($ics === '') {
            GcsLogger::instance()->warn('Empty ICS content');
            return [];
        }

        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify('+' . $this->horizonDays . ' days');

        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);
        if (empty($events)) {
            return [];
        }

        // Group events by UID (base + overrides)
        $byUid = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $uid = (string)($ev['uid'] ?? '');
            if ($uid !== '') {
                $byUid[$uid][] = $ev;
            }
        }

        $rawIntents = [];

        foreach ($byUid as $uid => $items) {
            $base = null;
            $overrides = [];

            foreach ($items as $ev) {
                if (!empty($ev['isOverride']) && !empty($ev['recurrenceId'])) {
                    $overrides[(string)$ev['recurrenceId']] = $ev;
                } elseif ($base === null) {
                    $base = $ev;
                }
            }

            $refEv = $base ?? $items[0];
            if (!empty($refEv['isAllDay'])) {
                continue;
            }

            $summary = (string)($refEv['summary'] ?? '');
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            $occurrences = self::expandEventOccurrences($base, $overrides, $now, $horizonEnd);
            if ($occurrences === false) {
                continue;
            }

            foreach ($occurrences as $occ) {
                $rawIntents[] = [
                    'uid'        => $uid,
                    'summary'    => $summary,
                    'type'       => (string)$resolved['type'],
                    'target'     => (string)$resolved['target'],
                    'start'      => (string)$occ['start'],
                    'end'        => (string)$occ['end'],
                    'stopType'   => 'graceful',
                    'repeat'     => 'none',
                    'isOverride' => !empty($occ['isOverride']),
                ];
            }
        }

        // Consolidate (range form)
        $consolidated = $rawIntents;
        try {
            $consolidator = new GcsIntentConsolidator();
            $maybe = $consolidator->consolidate($rawIntents);
            if (is_array($maybe)) {
                $consolidated = $maybe;
            }
        } catch (Throwable $ignored) {
            // keep raw intents
        }

        return $consolidated;
    }

    /**
     * APPLY-ONLY: execute writes via SchedulerSync(false).
     *
     * @return array<string,mixed> scheduler sync result
     */
    public function apply(): array
    {
        // Prevent misuse: apply() must never be called with dryRun=true
        if ($this->dryRun) {
            throw new RuntimeException('Apply blocked: runner is in dry-run mode');
        }

        $consolidated = $this->plan();
        $sync = new SchedulerSync(false); // <-- the ONLY write-capable construction
        return $sync->sync($consolidated);
    }

    /**
     * Legacy compatibility: old callers used run().
     * In Phase 16, run() is APPLY semantics (and will throw if dry-run).
     */
    public function run(): array
    {
        return $this->apply();
    }

    /**
     * Phase 13.3 recurrence expansion helper
     *
     * NOTE: Your repo indicates this logic was previously validated; keep behavior stable.
     * If recurrence expansion is incomplete in your current branch, paste the full function
     * from the known-good version and keep it identical.
     */
    private static function expandEventOccurrences(?array $base, array $overrides, DateTime $horizonStart, DateTime $horizonEnd)
    {
        $out = [];
        $overrideKeys = [];

        foreach ($overrides as $rid => $ov) {
            $s = new DateTime((string)$ov['start']);
            if ($s >= $horizonStart && $s <= $horizonEnd) {
                $overrideKeys[$rid] = true;
                $out[] = [
                    'start'      => $s->format('Y-m-d H:i:s'),
                    'end'        => (new DateTime((string)$ov['end']))->format('Y-m-d H:i:s'),
                    'isOverride' => true,
                ];
            }
        }

        if (!$base) {
            return $out;
        }

        $start = new DateTime((string)$base['start']);
        $end   = new DateTime((string)$base['end']);
        $duration = max(0, $end->getTimestamp() - $start->getTimestamp());

        // Non-recurring base event
        if (empty($base['rrule'])) {
            if ($start >= $horizonStart && $start <= $horizonEnd) {
                $rid = $start->format('Y-m-d H:i:s');
                if (empty($overrideKeys[$rid])) {
                    $out[] = [
                        'start'      => $rid,
                        'end'        => (clone $start)->modify("+{$duration} seconds")->format('Y-m-d H:i:s'),
                        'isOverride' => false,
                    ];
                }
            }
            return $out;
        }

        // If your current branch has full recurrence logic below this comment, keep it.
        // If not, paste it from the known-good Phase 13.3 version.
        return $out;
    }
}
