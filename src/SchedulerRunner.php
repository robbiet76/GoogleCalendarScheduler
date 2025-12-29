<?php
declare(strict_types=1);

final class GcsSchedulerRunner
{
    private array $cfg;
    private int $horizonDays;

    public function __construct(array $cfg, int $horizonDays)
    {
        $this->cfg = $cfg;
        $this->horizonDays = $horizonDays;
    }

    /**
     * Pure calendar ingestion + intent generation.
     *
     * @return array{ok:bool,intents:array<int,array<string,mixed>>,intents_seen:int,errors:array<int,string>}
     */
    public function run(): array
    {
        $icsUrl = trim((string)($this->cfg['calendar']['ics_url'] ?? ''));
        if ($icsUrl === '') {
            GcsLogger::instance()->warn('No ICS URL configured');
            return $this->emptyResult();
        }

        $ics = (new GcsIcsFetcher())->fetch($icsUrl);
        if ($ics === '') {
            return $this->emptyResult();
        }

        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify('+' . $this->horizonDays . ' days');

        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);
        if (empty($events)) {
            return $this->emptyResult();
        }

        // Group events by UID (base + overrides)
        $byUid = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            $uid = (string)($ev['uid'] ?? '');
            if ($uid !== '') {
                $byUid[$uid][] = $ev;
            }
        }

        $intentsOut = [];

        foreach ($byUid as $uid => $items) {
            $base = null;
            $overrides = [];

            foreach ($items as $ev) {
                if (!is_array($ev)) continue;

                if (!empty($ev['isOverride']) && !empty($ev['recurrenceId'])) {
                    $overrides[(string)$ev['recurrenceId']] = $ev;
                } elseif ($base === null) {
                    $base = $ev;
                }
            }

            $refEv = $base ?? $items[0];
            if (!is_array($refEv)) continue;

            if (!empty($refEv['isAllDay'])) {
                continue;
            }

            $summary = (string)($refEv['summary'] ?? '');
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            $occurrences = self::expandEventOccurrences(
                $base,
                $overrides,
                $now,
                $horizonEnd
            );

            if (empty($occurrences)) {
                continue;
            }

            // ------------------------------------------------------------
            // Phase 21: YAML-aware per-occurrence execution options
            // ------------------------------------------------------------
            $hasOverride   = false;
            $timesVary    = false;
            $optionsVary  = false;

            $timeKey = null;
            $optSig  = null;

            $occEff = [];

            foreach ($occurrences as $occ) {
                if (!is_array($occ)) continue;

                if (!empty($occ['isOverride'])) {
                    $hasOverride = true;
                }

                $s = new DateTime($occ['start']);
                $e = new DateTime($occ['end']);

                $k = $s->format('H:i:s') . '|' . $e->format('H:i:s');
                if ($timeKey === null) {
                    $timeKey = $k;
                } elseif ($timeKey !== $k) {
                    $timesVary = true;
                }

                $desc = self::extractDescriptionFromEvent($occ['source'] ?? null);
                $yaml = GcsYamlMetadata::parse($desc, [
                    'uid'   => $uid,
                    'start' => $s->format('Y-m-d H:i:s'),
                ]);

                $eff = self::applyYamlToExecution($resolved, $yaml);
                $sig = self::executionSignature($eff);

                if ($optSig === null) {
                    $optSig = $sig;
                } elseif ($optSig !== $sig) {
                    $optionsVary = true;
                }

                $occEff[] = [
                    'start'      => $s->format('Y-m-d H:i:s'),
                    'end'        => $e->format('Y-m-d H:i:s'),
                    'isOverride' => !empty($occ['isOverride']),
                    'eff'        => $eff,
                ];
            }

            if (empty($occEff)) continue;

            $canEmitSingle = (!$hasOverride && !$timesVary && !$optionsVary);

            if ($canEmitSingle) {
                $first = $occEff[0];
                $eff0  = $first['eff'];

                $occStart = new DateTime($first['start']);
                $occEnd   = new DateTime($first['end']);

                $seriesStartDate = substr((string)$refEv['start'], 0, 10);
                $seriesEndDate   = substr((string)$refEv['end'], 0, 10);

                $intentsOut[] = [
                    'uid' => $uid,
                    'template' => [
                        'uid'              => $uid,
                        'summary'          => $summary,
                        'type'             => $eff0['type'],
                        'target'           => $eff0['target'],
                        'start'            => $occStart->format('Y-m-d H:i:s'),
                        'end'              => $occEnd->format('Y-m-d H:i:s'),
                        'stopType'         => $eff0['stopType'],
                        'repeat'           => $eff0['repeat'],
                        'args'             => $eff0['args'],
                        'multisyncCommand' => $eff0['multisyncCommand'],
                        'enabled'          => $eff0['enabled'],
                        'isOverride'       => false,
                    ],
                    'range' => [
                        'start' => $seriesStartDate,
                        'end'   => $seriesEndDate,
                        'days'  => '',
                    ],
                ];

                continue;
            }

            foreach ($occEff as $oe) {
                $eff = $oe['eff'];

                $intentsOut[] = [
                    'uid'              => $uid,
                    'summary'          => $summary,
                    'type'             => $eff['type'],
                    'target'           => $eff['target'],
                    'start'            => $oe['start'],
                    'end'              => $oe['end'],
                    'stopType'         => $eff['stopType'],
                    'repeat'           => $eff['repeat'],
                    'args'             => $eff['args'],
                    'multisyncCommand' => $eff['multisyncCommand'],
                    'enabled'          => $eff['enabled'],
                    'isOverride'       => $oe['isOverride'],
                ];
            }
        }

        return [
            'ok'           => true,
            'intents'      => $intentsOut,
            'intents_seen' => count($intentsOut),
            'errors'       => [],
        ];
    }

    private function emptyResult(): array
    {
        return [
            'ok'           => true,
            'intents'      => [],
            'intents_seen' => 0,
            'errors'       => [],
        ];
    }

    /**
     * Recurrence expansion helper.
     *
     * Generates concrete occurrences intersecting the horizon.
     *
     * NOTE (Phase 21):
     * - Each occurrence includes a 'source' VEVENT array (base or override) so YAML/description can be applied per occurrence.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function expandEventOccurrences(
        ?array $base,
        array $overrides,
        DateTime $horizonStart,
        DateTime $horizonEnd
    ): array {
        $out = [];
        $overrideKeys = [];

        // Include overrides first
        foreach ($overrides as $rid => $ov) {
            $s = new DateTime($ov['start']);
            if ($s >= $horizonStart && $s <= $horizonEnd) {
                $overrideKeys[$rid] = true;
                $out[] = [
                    'start'      => $s->format('Y-m-d H:i:s'),
                    'end'        => (new DateTime($ov['end']))->format('Y-m-d H:i:s'),
                    'isOverride' => true,
                    'source'     => $ov,
                ];
            }
        }

        if (!$base) {
            return $out;
        }

        $start = new DateTime($base['start']);
        $end   = new DateTime($base['end']);
        $duration = max(0, $end->getTimestamp() - $start->getTimestamp());

        // Non-recurring
        if (empty($base['rrule'])) {
            if ($start >= $horizonStart && $start <= $horizonEnd) {
                $rid = $start->format('Y-m-d H:i:s');
                if (empty($overrideKeys[$rid])) {
                    $out[] = [
                        'start'      => $rid,
                        'end'        => (clone $start)->modify("+{$duration} seconds")->format('Y-m-d H:i:s'),
                        'isOverride' => false,
                        'source'     => $base,
                    ];
                }
            }
            return $out;
        }

        $rrule = $base['rrule'];
        $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
        $interval = max(1, (int)($rrule['INTERVAL'] ?? 1));

        $until = null;
        if (!empty($rrule['UNTIL'])) {
            $until = self::parseRruleUntil((string)$rrule['UNTIL']);
        }

        $countLimit = isset($rrule['COUNT']) ? max(1, (int)$rrule['COUNT']) : null;

        $exDates = [];
        if (!empty($base['exDates'])) {
            foreach ($base['exDates'] as $ex) {
                $exDates[$ex] = true;
            }
        }

        $addOccurrence = function(DateTime $s) use (
            &$out,
            $duration,
            $horizonStart,
            $horizonEnd,
            $until,
            &$countLimit,
            &$overrideKeys,
            &$exDates,
            $base
        ): bool {
            if ($s < $horizonStart || $s > $horizonEnd) return true;
            if ($until && $s > $until) return false;

            $rid = $s->format('Y-m-d H:i:s');
            if (!empty($overrideKeys[$rid]) || !empty($exDates[$rid])) return true;

            $out[] = [
                'start'      => $rid,
                'end'        => (clone $s)->modify("+{$duration} seconds")->format('Y-m-d H:i:s'),
                'isOverride' => false,
                'source'     => $base,
            ];

            if ($countLimit !== null && --$countLimit <= 0) {
                return false;
            }
            return true;
        };

        $h = (int)$start->format('H');
        $i = (int)$start->format('i');
        $s0 = (int)$start->format('s');

        if ($freq === 'DAILY') {
            $cursor = clone $start;
            while ($cursor < $horizonStart) {
                $cursor->modify("+{$interval} days");
            }
            while ($cursor <= $horizonEnd) {
                $cursor->setTime($h, $i, $s0);
                if (!$addOccurrence($cursor)) break;
                $cursor->modify("+{$interval} days");
            }
            return $out;
        }

        if ($freq === 'WEEKLY') {
            $byday = [];
            if (!empty($rrule['BYDAY'])) {
                foreach (explode(',', strtoupper((string)$rrule['BYDAY'])) as $tok) {
                    $d = self::byDayToDow($tok);
                    if ($d !== null) $byday[] = $d;
                }
            }
            if (empty($byday)) {
                $byday[] = (int)$start->format('w');
            }

            $weekCursor = clone $start;
            while ($weekCursor < $horizonStart) {
                $weekCursor->modify("+{$interval} weeks");
            }

            while ($weekCursor <= $horizonEnd) {
                foreach ($byday as $dow) {
                    $occ = (clone $weekCursor)->modify("sunday this week +{$dow} days");
                    $occ->setTime($h, $i, $s0);
                    if ($occ < $start) continue;
                    if (!$addOccurrence($occ)) return $out;
                }
                $weekCursor->modify("+{$interval} weeks");
            }
            return $out;
        }

        return $out;
    }

    private static function byDayToDow(string $tok): ?int
    {
        $tok = preg_replace('/^[+-]?\d+/', '', $tok);
        return match ($tok) {
            'SU' => 0,
            'MO' => 1,
            'TU' => 2,
            'WE' => 3,
            'TH' => 4,
            'FR' => 5,
            'SA' => 6,
            default => null,
        };
    }

    private static function parseRruleUntil(string $raw): ?DateTime
    {
        try {
            if (preg_match('/^\d{8}$/', $raw)) {
                return (new DateTime($raw))->setTime(23, 59, 59);
            }
            if (preg_match('/^\d{8}T\d{6}Z$/', $raw)) {
                return new DateTime($raw, new DateTimeZone('UTC'));
            }
            if (preg_match('/^\d{8}T\d{6}$/', $raw)) {
                return new DateTime($raw);
            }
        } catch (Throwable) {}
        return null;
    }

    private static function isValidYmd(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $s);
        return ($dt instanceof DateTime) && ($dt->format('Y-m-d') === $s);
    }

    private static function dowToShortDay(int $dow): string
    {
        return match ($dow) {
            0 => 'Su',
            1 => 'Mo',
            2 => 'Tu',
            3 => 'We',
            4 => 'Th',
            5 => 'Fr',
            6 => 'Sa',
            default => '',
        };
    }

    /**
     * Convert RRULE BYDAY like "SU,MO,TU,WE,TH,FR,SA" into "SuMoTuWeThFrSa".
     * Returns empty string if BYDAY missing/invalid.
     */
    private static function shortDaysFromByDay(string $bydayRaw): string
    {
        $bydayRaw = strtoupper(trim($bydayRaw));
        if ($bydayRaw === '') return '';

        $tokens = explode(',', $bydayRaw);
        $present = [
            'SU' => false,
            'MO' => false,
            'TU' => false,
            'WE' => false,
            'TH' => false,
            'FR' => false,
            'SA' => false,
        ];

        foreach ($tokens as $tok) {
            $tok = preg_replace('/^[+-]?\d+/', '', trim($tok)); // remove ordinal like 1MO
            if (isset($present[$tok])) {
                $present[$tok] = true;
            }
        }

        $out = '';
        if ($present['SU']) $out .= 'Su';
        if ($present['MO']) $out .= 'Mo';
        if ($present['TU']) $out .= 'Tu';
        if ($present['WE']) $out .= 'We';
        if ($present['TH']) $out .= 'Th';
        if ($present['FR']) $out .= 'Fr';
        if ($present['SA']) $out .= 'Sa';

        return $out;
    }

    /* -----------------------------------------------------------------
     * Phase 21 helpers: YAML application + signature
     * ----------------------------------------------------------------- */

    /**
     * Extract description text from a VEVENT record (best effort).
     *
     * This runner intentionally does not assume any specific key name; it checks common ones.
     */
    private static function extractDescriptionFromEvent(?array $ev): ?string
    {
        if (!$ev) return null;

        foreach (['description', 'DESCRIPTION', 'desc', 'body'] as $k) {
            if (isset($ev[$k]) && is_string($ev[$k]) && trim($ev[$k]) !== '') {
                return (string)$ev[$k];
            }
        }

        return null;
    }

    /**
     * Default execution options derived from title resolution (no YAML).
     *
     * @param array{type:string,target:string} $resolved
     * @return array<string,mixed>
     */
    private static function defaultExecutionFromResolved(array $resolved): array
    {
        return [
            'type'             => (string)$resolved['type'],
            'target'           => (string)$resolved['target'],
            'stopType'         => 0,   // default = graceful (FPP enum 0)
            'repeat'           => 0,   // default = none
            'args'             => [],
            'multisyncCommand' => false,
            'enabled'          => 1,
        ];
    }

    /**
     * Apply YAML metadata to the resolved execution target/options.
     *
     * YAML keys supported (canonical, as produced by GcsYamlMetadata):
     * - enabled (bool)
     * - type (string: playlist|sequence|command)  [note: SchedulerSync currently supports playlist|command]
     * - command (string)                          [required if type=command]
     * - args (array)
     * - multisyncCommand (bool)
     * - stopType (string|int)                     [graceful|graceful_loop|hard OR numeric]
     * - repeat (string|int)                       [none|immediate|N OR numeric]
     *
     * @param array{type:string,target:string} $resolved
     * @param array<string,mixed> $yaml
     * @return array<string,mixed>
     */
    private static function applyYamlToExecution(array $resolved, array $yaml): array
    {
        $eff = self::defaultExecutionFromResolved($resolved);

        // enabled
        if (isset($yaml['enabled']) && is_bool($yaml['enabled'])) {
            $eff['enabled'] = $yaml['enabled'] ? 1 : 0;
        }

        // args
        if (isset($yaml['args']) && is_array($yaml['args'])) {
            $eff['args'] = array_values($yaml['args']);
        }

        // multisyncCommand
        if (isset($yaml['multisyncCommand']) && is_bool($yaml['multisyncCommand'])) {
            $eff['multisyncCommand'] = $yaml['multisyncCommand'];
        }

        // stopType normalization (string -> FPP enum int)
        if (isset($yaml['stopType'])) {
            $eff['stopType'] = self::normalizeStopType($yaml['stopType']);
        }

        // repeat normalization (string/int -> int)
        if (isset($yaml['repeat'])) {
            $eff['repeat'] = self::normalizeRepeat($yaml['repeat']);
        }

        // type + target override (command only needs extra support)
        if (isset($yaml['type']) && is_string($yaml['type'])) {
            $t = strtolower(trim($yaml['type']));
            if ($t === 'command') {
                // For commands, target is the command name
                $cmd = (isset($yaml['command']) && is_string($yaml['command'])) ? trim($yaml['command']) : '';
                if ($cmd !== '') {
                    $eff['type'] = 'command';
                    $eff['target'] = $cmd;
                } else {
                    // If command is missing, keep resolved target; diff layer will likely treat it as playlist/sequence.
                    // The YAML parser will already warn for missing keys only if implemented elsewhere; keep safe here.
                    $eff['type'] = 'command';
                    $eff['target'] = ''; // force downstream to reject (missing target) if this reaches SchedulerSync
                }
            } elseif ($t === 'playlist') {
                $eff['type'] = 'playlist';
                // keep resolved target (playlist name) unless user also set something else
            } elseif ($t === 'sequence') {
                // SchedulerSync currently only accepts playlist|command; keep value so we can detect divergence/splitting,
                // but note that SchedulerSync may reject it later.
                $eff['type'] = 'sequence';
                // keep resolved target (sequence name)
            }
        }

        // If YAML provided command without explicit type, treat it as command intent (power-user convenience)
        if (!isset($yaml['type']) && isset($yaml['command']) && is_string($yaml['command'])) {
            $cmd = trim($yaml['command']);
            if ($cmd !== '') {
                $eff['type'] = 'command';
                $eff['target'] = $cmd;
            }
        }

        return $eff;
    }

    /**
     * Build a stable signature for execution options.
     * If this differs across occurrences, we must split into separate scheduler entries.
     *
     * @param array<string,mixed> $eff
     */
    private static function executionSignature(array $eff): string
    {
        $type = (string)($eff['type'] ?? '');
        $target = (string)($eff['target'] ?? '');
        $stopType = (int)($eff['stopType'] ?? 0);
        $repeat = (int)($eff['repeat'] ?? 0);
        $ms = !empty($eff['multisyncCommand']) ? '1' : '0';
        $en = (int)($eff['enabled'] ?? 1);

        $args = (isset($eff['args']) && is_array($eff['args'])) ? $eff['args'] : [];
        // Normalize args to strings for stable compare
        $argsNorm = array_map(static function($v): string {
            if (is_bool($v)) return $v ? 'true' : 'false';
            if (is_int($v) || is_float($v)) return (string)$v;
            if (is_string($v)) return $v;
            return json_encode($v);
        }, $args);

        return json_encode([
            'type' => $type,
            'target' => $target,
            'stopType' => $stopType,
            'repeat' => $repeat,
            'multisync' => $ms,
            'enabled' => $en,
            'args' => $argsNorm,
        ]);
    }

    /**
     * Normalize stopType YAML value to FPP stopType enum int.
     *
     * Supported strings:
     * - graceful        => 0
     * - graceful_loop   => 1
     * - hard            => 2
     *
     * If already numeric, uses that int.
     */
    private static function normalizeStopType($v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int)$v;

        if (is_string($v)) {
            $s = strtolower(trim($v));
            return match ($s) {
                'graceful' => 0,
                'graceful_loop', 'graceful-loop', 'gracefulloop' => 1,
                'hard' => 2,
                default => 0,
            };
        }

        return 0;
    }

    /**
     * Normalize repeat YAML value to FPP repeat int.
     *
     * Supported:
     * - none      => 0
     * - immediate => 1
     * - N         => (int)N
     *
     * If already numeric, uses that int.
     */
    private static function normalizeRepeat($v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int)$v;

        if (is_string($v)) {
            $s = strtolower(trim($v));
            if ($s === 'none') return 0;
            if ($s === 'immediate') return 1;
        }

        return 0;
    }
}
