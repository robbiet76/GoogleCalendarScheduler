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

            /**
             * Phase 21: YAML-aware per-occurrence option resolution.
             *
             * Requirement:
             * - If YAML differs per occurrence (including overrides), we must split into separate scheduler entries,
             *   exactly like time overrides do.
             * - YAML changes must manifest as changes to the desired scheduler entry fields (stopType/repeat/args/etc.),
             *   so diff emits UPDATEs naturally.
             */
            $hasOverride = false;
            $timeKey = null;
            $timesVary = false;

            $optSig = null;
            $optionsVary = false;

            // Precompute per-occurrence effective execution options (YAML + defaults)
            $occEff = []; // array<int, array{start:string,end:string,isOverride:bool,eff:array<string,mixed>}>
            foreach ($occurrences as $occ) {
                if (!is_array($occ)) continue;

                if (!empty($occ['isOverride'])) {
                    $hasOverride = true;
                }

                $s = new DateTime((string)($occ['start'] ?? ''));
                $e = new DateTime((string)($occ['end'] ?? ''));

                $k = $s->format('H:i:s') . '|' . $e->format('H:i:s');
                if ($timeKey === null) {
                    $timeKey = $k;
                } elseif ($timeKey !== $k) {
                    $timesVary = true;
                }

                // YAML context (best effort)
                $context = [
                    'uid'     => (string)$uid,
                    'summary' => (string)$summary,
                    'start'   => $s->format('Y-m-d H:i:s'),
                ];

                // Description may live on the source VEVENT record (base or override)
                $sourceEv = (isset($occ['source']) && is_array($occ['source'])) ? $occ['source'] : null;
                $descText = self::extractDescriptionFromEvent($sourceEv);

                // Parse YAML metadata (returns [] if none / not detected)
                $yamlMeta = GcsYamlMetadata::parse($descText, $context);

                // Build effective execution options for this occurrence
                $eff = self::applyYamlToExecution(
                    $resolved,
                    $yamlMeta
                );

                // Compare execution signatures to decide if we can emit a single intent
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

            if (empty($occEff)) {
                continue;
            }

            // Determine whether we can safely emit ONE intent per UID.
            // If overrides exist OR occurrence times vary OR YAML-derived execution differs,
            // we fall back to per-occurrence intents and let GcsIntentConsolidator split into ranges losslessly.
            $canEmitSingle = (!$hasOverride && !$timesVary && !$optionsVary);

            if ($canEmitSingle) {
                // IMPORTANT (Phase 20): We emit ONE intent per UID (one scheduler entry), not one per occurrence.
                // That means we MUST explicitly provide a range (start/end/days). Consolidator cannot infer
                // "Everyday" or the correct endDate from a single occurrence.

                $first = $occEff[0];
                if (!is_array($first) || empty($first['start']) || empty($first['end'])) {
                    continue;
                }

                $occStart = new DateTime((string)$first['start']);
                $occEnd   = new DateTime((string)$first['end']);

                // Series DTSTART date (calendar series start)
                $seriesStartDate = $occStart->format('Y-m-d');
                if ($base && !empty($base['start'])) {
                    $tmp = substr((string)$base['start'], 0, 10);
                    if (self::isValidYmd($tmp)) {
                        $seriesStartDate = $tmp;
                    }
                }

                // Series end date:
                // Prefer RRULE UNTIL (true series boundary), else use last expanded occurrence date.
                $lastOccDate = $occStart->format('Y-m-d');
                foreach ($occEff as $oe) {
                    if (!is_array($oe) || empty($oe['start'])) continue;
                    $d = substr((string)$oe['start'], 0, 10);
                    if (self::isValidYmd($d) && $d > $lastOccDate) {
                        $lastOccDate = $d;
                    }
                }
                $seriesEndDate = $lastOccDate;

                $rrule = ($base && isset($base['rrule']) && is_array($base['rrule'])) ? $base['rrule'] : null;
                if (is_array($rrule) && !empty($rrule['UNTIL'])) {
                    $until = self::parseRruleUntil((string)$rrule['UNTIL']);
                    if ($until instanceof DateTime) {
                        // Normalize to local timezone before taking date
                        try {
                            $until->setTimezone(new DateTimeZone(date_default_timezone_get()));
                        } catch (Throwable $ignored) {}
                        $untilDate = $until->format('Y-m-d');
                        if (self::isValidYmd($untilDate)) {
                            $seriesEndDate = $untilDate;
                        }
                    }
                }

                // Days:
                // - DAILY => Everyday (SuMoTuWeThFrSa)
                // - WEEKLY => derive from BYDAY, else fallback to the start DOW only
                $daysShort = '';
                if (is_array($rrule)) {
                    $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
                    if ($freq === 'DAILY') {
                        $daysShort = 'SuMoTuWeThFrSa';
                    } elseif ($freq === 'WEEKLY') {
                        $daysShort = self::shortDaysFromByDay((string)($rrule['BYDAY'] ?? ''));
                    }
                }
                if ($daysShort === '') {
                    // Fallback: single day based on DTSTART
                    $dow = (int)$occStart->format('w'); // 0=Sun..6=Sat
                    $daysShort = self::dowToShortDay($dow);
                }

                // Execution options are identical across occurrences (by construction)
                $eff0 = (isset($first['eff']) && is_array($first['eff'])) ? $first['eff'] : self::defaultExecutionFromResolved($resolved);

                $template = [
                    'uid'              => $uid,
                    'summary'          => $summary,
                    'type'             => (string)($eff0['type'] ?? $resolved['type']),
                    'target'           => (string)($eff0['target'] ?? $resolved['target']),
                    'start'            => $occStart->format('Y-m-d H:i:s'),
                    'end'              => $occEnd->format('Y-m-d H:i:s'),
                    'stopType'         => (int)($eff0['stopType'] ?? 0),
                    'repeat'           => (int)($eff0['repeat'] ?? 0),
                    'args'             => (isset($eff0['args']) && is_array($eff0['args'])) ? $eff0['args'] : [],
                    'multisyncCommand' => (bool)($eff0['multisyncCommand'] ?? false),

                    // NOTE: SchedulerSync currently hardcodes enabled=1.
                    // We include it here so that once SchedulerSync honors it, YAML changes will naturally trigger UPDATEs.
                    'enabled'          => (int)($eff0['enabled'] ?? 1),

                    'isOverride'       => false,
                ];

                $intentsOut[] = [
                    'uid'      => $uid,
                    'template' => $template,
                    'range'    => [
                        'start' => $seriesStartDate,
                        'end'   => $seriesEndDate,
                        'days'  => $daysShort,
                    ],
                ];

                continue;
            }

            // Fallback: per-occurrence (lossless), then consolidator will merge into correct ranges/days.
            // YAML differences (and/or overrides/time variation) will naturally produce multiple consolidated entries.
            $rawIntents = [];
            foreach ($occEff as $oe) {
                if (!is_array($oe)) continue;

                $eff = (isset($oe['eff']) && is_array($oe['eff'])) ? $oe['eff'] : self::defaultExecutionFromResolved($resolved);

                $rawIntents[] = [
                    'uid'              => $uid,
                    'summary'          => $summary,
                    'type'             => (string)($eff['type'] ?? $resolved['type']),
                    'target'           => (string)($eff['target'] ?? $resolved['target']),
                    'start'            => (string)$oe['start'],
                    'end'              => (string)$oe['end'],
                    'stopType'         => (int)($eff['stopType'] ?? 0),
                    'repeat'           => (int)($eff['repeat'] ?? 0),
                    'args'             => (isset($eff['args']) && is_array($eff['args'])) ? $eff['args'] : [],
                    'multisyncCommand' => (bool)($eff['multisyncCommand'] ?? false),
                    'enabled'          => (int)($eff['enabled'] ?? 1),
                    'isOverride'       => !empty($oe['isOverride']),
                ];
            }

            try {
                $consolidator = new GcsIntentConsolidator();
                $maybe = $consolidator->consolidate($rawIntents);
                if (is_array($maybe) && !empty($maybe)) {
                    foreach ($maybe as $row) {
                        if (is_array($row)) {
                            $intentsOut[] = $row;
                        }
                    }
                }
            } catch (Throwable $ignored) {
                // If consolidation fails, still return the raw intents (best effort)
                foreach ($rawIntents as $ri) {
                    $intentsOut[] = $ri;
                }
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
