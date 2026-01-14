<?php
declare(strict_types=1);

/**
 * FPPSemantics
 *
 * Centralized Falcon Player (FPP) semantic knowledge.
 *
 * PURPOSE:
 * - Document and centralize values derived from FPP source / UI behavior
 * - Provide meaning for scheduler enums and sentinel values
 * - Act as a semantic boundary between PHP and FPP concepts
 *
 * NON-GOALS:
 * - No calendar policy
 * - No diff logic
 * - No external I/O
 *
 * This file should change only when FPP semantics or runtime contracts change.
 */
final class FPPSemantics
{
    /* =====================================================================
     * Canonical defaults (single source of truth)
     * ===================================================================== */

    public const DEFAULT_ENABLED          = true;
    public const DEFAULT_STOPTYPE         = 'graceful';
    public const DEFAULT_REPEAT_PLAYBACK  = 'immediate'; // playlists & sequences
    public const DEFAULT_REPEAT_COMMAND   = 'none';      // commands
    public const DEFAULT_ROUNDING_MINUTES = 30;

    /* =====================================================================
     * Entry types
     * ===================================================================== */

    public const TYPE_PLAYLIST = 'playlist';
    public const TYPE_SEQUENCE = 'sequence';
    public const TYPE_COMMAND  = 'command';

    /**
     * Normalize entry type.
     *
     * Playlist is the implicit default.
     */
    public static function normalizeType(?string $type): string
    {
        return match ($type) {
            self::TYPE_SEQUENCE => self::TYPE_SEQUENCE,
            self::TYPE_COMMAND  => self::TYPE_COMMAND,
            default             => self::TYPE_PLAYLIST,
        };
    }

    /**
     * Default repeat value by entry type.
     */
    public static function getDefaultRepeatForType(string $type): string
    {
        $type = self::normalizeType($type);

        return match ($type) {
            self::TYPE_COMMAND  => self::DEFAULT_REPEAT_COMMAND,
            self::TYPE_SEQUENCE,
            self::TYPE_PLAYLIST => self::DEFAULT_REPEAT_PLAYBACK,
        };
    }

    /* =====================================================================
     * Scheduler guard semantics
     * ===================================================================== */

    /**
     * Return the scheduler guard date used by FPP.
     *
     * FPP caps schedules at Dec 31 of (current year + 5).
     */
    public static function getSchedulerGuardDate(): DateTime
    {
        $currentYear = (int)date('Y');
        $guardYear   = $currentYear + 5;

        return new DateTime(sprintf('%04d-12-31', $guardYear));
    }

    /* =====================================================================
     * Runtime environment (exported by FPP)
     * ===================================================================== */

    /**
     * Cached FPP runtime environment data.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $environment = null;

    /**
     * Inject runtime environment data.
     *
     * @param array<string,mixed> $env
     */
    public static function setEnvironment(array $env): void
    {
        self::$environment = $env;
    }

    public static function hasEnvironment(): bool
    {
        return is_array(self::$environment);
    }

    public static function getLatitude(): ?float
    {
        return is_numeric(self::$environment['latitude'] ?? null)
            ? (float) self::$environment['latitude']
            : null;
    }

    public static function getLongitude(): ?float
    {
        return is_numeric(self::$environment['longitude'] ?? null)
            ? (float) self::$environment['longitude']
            : null;
    }

    public static function getTimezone(): ?string
    {
        return is_string(self::$environment['timezone'] ?? null)
            ? self::$environment['timezone']
            : null;
    }

    /* =====================================================================
     * Canonical DateTime construction
     * ===================================================================== */

    public static function combineDateTime(
        string $ymd,
        string $hms
    ): ?DateTime {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }

        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hms)) {
            return null;
        }

        $tz = self::getTimezone()
            ? new DateTimeZone(self::getTimezone())
            : null;

        $dt = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            "{$ymd} {$hms}",
            $tz ?: null
        );

        return ($dt instanceof DateTime) ? $dt : null;
    }

    /* =====================================================================
     * Enabled / disabled semantics
     * ===================================================================== */

    public static function normalizeEnabled(mixed $value): bool
    {
        return !($value === false || $value === 0 || $value === '0');
    }

    public static function isDefaultEnabled(bool $enabled): bool
    {
        return $enabled === self::DEFAULT_ENABLED;
    }

    /* =====================================================================
     * Scheduler stop types
     * ===================================================================== */

    public const STOP_TYPE_GRACEFUL      = 0;
    public const STOP_TYPE_HARD          = 1;
    public const STOP_TYPE_GRACEFUL_LOOP = 2;

    public static function stopTypeToString(int $v): string
    {
        return match ($v) {
            self::STOP_TYPE_HARD          => 'hard',
            self::STOP_TYPE_GRACEFUL_LOOP => 'graceful_loop',
            default                       => 'graceful',
        };
    }

    /**
     * Map YAML-friendly stopType values into the FPP scheduler enum.
     *
     * FPP ScheduleEntry.cpp:
     * 0 = Graceful
     * 1 = Hard
     * 2 = Graceful Loop
     */
    public static function stopTypeToEnum($v): int
    {
        if ($v === null) {
            return self::STOP_TYPE_GRACEFUL;
        }

        if (is_int($v)) {
            return max(
                self::STOP_TYPE_GRACEFUL,
                min(self::STOP_TYPE_GRACEFUL_LOOP, $v)
            );
        }

        if (is_string($v)) {
            $s = strtolower(trim($v));
            return match ($s) {
                'hard', 'hard_stop'     => self::STOP_TYPE_HARD,
                'graceful_loop'         => self::STOP_TYPE_GRACEFUL_LOOP,
                'graceful', '', 'none'  => self::STOP_TYPE_GRACEFUL,
                default                 => self::STOP_TYPE_GRACEFUL,
            };
        }

        return self::STOP_TYPE_GRACEFUL;
    }

    public static function getDefaultStopType(): string
    {
        return self::DEFAULT_STOPTYPE;
    }

    /* =====================================================================
     * Repeat semantics
     * ===================================================================== */

    public static function repeatToYaml(int $repeat): string|int
    {
        return match (true) {
            $repeat === 0  => 'none',
            $repeat === 1  => 'immediate',
            $repeat >= 100 => (int) ($repeat / 100),
            default        => 'none',
        };
    }

    /* =====================================================================
     * Day-of-week enum semantics
     * ===================================================================== */

    public static function dayEnumToByDay(int $enum): string
    {
        return match ($enum) {
            0  => 'SU',
            1  => 'MO',
            2  => 'TU',
            3  => 'WE',
            4  => 'TH',
            5  => 'FR',
            6  => 'SA',
            7  => '',
            8  => 'MO,TU,WE,TH,FR',
            9  => 'SU,SA',
            10 => 'MO,WE,FR',
            11 => 'TU,TH',
            12 => 'SU,MO,TU,WE,TH',
            13 => 'FR,SA',
            default => '',
        };
    }

    /* =====================================================================
     * Sentinel values
     * ===================================================================== */

    public const SENTINEL_YEAR = '0000';

    public static function isSentinelDate(string $ymd): bool
    {
        return str_starts_with($ymd, self::SENTINEL_YEAR . '-');
    }

    public static function isEndOfDayTime(string $time): bool
    {
        return $time === '24:00:00';
    }

    /* =====================================================================
     * Symbolic time semantics (delegated math)
     * ===================================================================== */

    public const SYMBOLIC_TIMES = [
        'Dawn',
        'SunRise',
        'SunSet',
        'Dusk',
    ];

    public static function isSymbolicTime(?string $value): bool
    {
        return is_string($value)
            && in_array($value, self::SYMBOLIC_TIMES, true);
    }

    /**
     * Resolve symbolic time using runtime environment.
     */
    public static function resolveSymbolicTime(
        string $date,
        string $symbolic,
        int $offsetMinutes
    ): ?array {
        if (!self::isSymbolicTime($symbolic)) {
            return null;
        }

        $lat = self::getLatitude();
        $lon = self::getLongitude();

        if ($lat === null || $lon === null) {
            return null;
        }

        $display = SunTimeEstimator::estimate(
            $date,
            $symbolic,
            $lat,
            $lon,
            $offsetMinutes,
            self::DEFAULT_ROUNDING_MINUTES
        );

        if (!$display) {
            return null;
        }

        $dt = self::combineDateTime($date, $display);
        if (!$dt) {
            return null;
        }

        return [
            'datetime' => $dt,
            'yaml' => [
                'symbolic'       => $symbolic,
                'offsetMinutes' => $offsetMinutes,
                'displayTime'   => $display,
            ],
        ];
    }

    /* =====================================================================
     * Date resolution (sentinel + holidays)
     *
     * IMPORTANT:
     * - resolveDate() is EXPORT/PLANNING oriented and returns a single hard Y-m-d.
     * - Identity construction must NOT depend on resolveDate(); use interpretDateToken()
     *   which preserves symbolic/hard duality for ManifestIdentity.
     *
     * IDENTITY-SAFETY CONTRACT:
     * - This method MUST NOT be used for identity construction.
     * - It is strictly forbidden to call resolveDate() from ManifestIdentity, SchedulerDiff, or SchedulerPlanner.
     * - Only use this for export/planning flows where a concrete Y-m-d is required.
     * ===================================================================== */
    public static function resolveDate(
        string $raw,
        ?string $fallbackDate,
        array &$warnings,
        string $context
    ): ?string {
        $raw = trim($raw);

        error_log(
            '[GCS DEBUG][FPPSemantics::resolveDate] context=' . $context .
            ' raw=' . ($raw !== '' ? $raw : '(empty)') .
            ' fallbackDate=' . ($fallbackDate ?? '(null)')
        );

        // Absolute date
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            if (self::isSentinelDate($raw)) {
                $year = (int)date('Y');
                return sprintf('%04d-%s', $year, substr($raw, 5));
            }
            return $raw;
        }

        // Holiday (resolved via FPP locale) - EXPORT/PLANNING ONLY
        if ($raw !== '') {
            $dt = self::resolveHolidayToDate($raw, $fallbackDate, $context);
            if ($dt instanceof DateTime) {
                error_log(
                    '[GCS DEBUG][FPPSemantics::resolveDate] holiday ' .
                    $raw . ' resolved to ' . $dt->format('Y-m-d')
                );
                return $dt->format('Y-m-d');
            }
        }

        error_log(
            '[GCS DEBUG][FPPSemantics::resolveDate] FAILED to resolve ' .
            $raw . ' in context=' . $context
        );
        $warnings[] = "Export: {$context} '{$raw}' invalid.";
        return null;
    }

    /**
     * IDENTITY-SAFETY CONTRACT:
     * ------------------------------------------------------------
     * This is the ONLY approved entry point for date handling in ManifestIdentity.
     * - SchedulerPlanner must pass raw date tokens here without prior resolution.
     * - No other file may attempt to infer symbolic or hard dates for identity.
     * - This method enforces the preservation of symbolic/hard duality for identity purposes.
     *
     * Interpret a date token for IDENTITY construction.
     *
     * This preserves symbolic/hard duality for ManifestIdentity:
     * - If raw is a holiday name (e.g. "Christmas"), return symbolic only.
     * - If raw is a hard date, return hard date AND (when possible) its symbolic holiday.
     * - Sentinel dates preserve their month/day intent and normalize the year for hard comparison.
     *
     * Returned structure:
     * [
     *   'raw'      => string,
     *   'hard'     => ?string,   // Y-m-d when present
     *   'symbolic' => ?string,   // Holiday name when present
     *   'source'   => 'holiday'|'date'|'sentinel',
     * ]
     *
     * NOTE: This function must NOT invent a hard date from a symbolic holiday.
     */
    public static function interpretDateToken(
        string $raw,
        ?string $fallbackDate,
        array &$warnings,
        string $context
    ): ?array {
        $raw = trim($raw);

        if ($raw === '') {
            $warnings[] = "Identity: {$context} empty date.";
            return null;
        }

        // Hard date input
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $hard = $raw;
            $source = 'date';

            if (self::isSentinelDate($raw)) {
                // Normalize sentinel year for comparison, preserving month/day intent.
                $year = (int)date('Y');
                $hard = sprintf('%04d-%s', $year, substr($raw, 5));
                $source = 'sentinel';
            }

            // If this hard date matches a known holiday, include symbolic too.
            // Canonical identity-safe lift: hard date -> symbolic holiday (if defined)
            $symbolic = HolidayResolver::dateToHoliday($hard);
            if (!is_string($symbolic) || trim($symbolic) === '') {
                $symbolic = null;
            }

            return [
                'raw'      => $raw,
                'hard'     => $hard,
                'symbolic' => $symbolic,
                'source'   => $source,
            ];
        }

        // Holiday token input (symbolic). Identity must preserve symbol and must NOT invent hard date.
        // (Export/planning may still call resolveDate() to compute a concrete Y-m-d.)
        return [
            'raw'      => $raw,
            'hard'     => null,
            'symbolic' => $raw,
            'source'   => 'holiday',
        ];
    }

    /**
     * Export/planning helper: resolve a holiday name to a concrete date using heuristics.
     * Identity code must NOT call this.
     */
    private static function resolveHolidayToDate(
        string $holiday,
        ?string $fallbackDate,
        string $context
    ): ?DateTime {
        $holiday = trim($holiday);
        if ($holiday === '') {
            return null;
        }

        $currentYear = (int)date('Y');
        $today = new DateTime('today');

        // Primary year hint:
        // - If we have a fallback (typically the other bound of a range), anchor to that year.
        // - Otherwise anchor to the current year, but apply a “season” heuristic for late-year holidays
        //   when running early in the year (prevents Thanksgiving/Christmas season drifting a year ahead).
        $yearHint = $fallbackDate
            ? (int)substr($fallbackDate, 0, 4)
            : $currentYear;

        error_log(
            '[GCS DEBUG][FPPSemantics::resolveHolidayToDate] context=' . $context .
            ' holiday=' . $holiday . ' yearHint=' . $yearHint .
            ' fallbackDate=' . ($fallbackDate ?? '(null)')
        );

        $dt = HolidayResolver::dateFromHoliday($holiday, $yearHint);

        // If no fallbackDate (standalone holiday), and the resolved date is “far in the future”,
        // prefer the previous year (typical holiday season behavior in Jan/Feb).
        if (!$fallbackDate && ($dt instanceof DateTime)) {
            $futureCutoff = (clone $today)->modify('+180 days');
            if ($dt > $futureCutoff) {
                $altYear = $yearHint - 1;
                error_log(
                    '[GCS DEBUG][FPPSemantics::resolveHolidayToDate] holiday ' . $holiday .
                    ' resolved far-future (' . $dt->format('Y-m-d') .
                    '), retrying with yearHint=' . $altYear
                );
                $alt = HolidayResolver::dateFromHoliday($holiday, $altYear);
                if ($alt instanceof DateTime) {
                    $dt = $alt;
                    $yearHint = $altYear;
                }
            }
        }

        // If we DO have a fallbackDate (range bound), ensure monotonicity:
        // if the resolved holiday is before the fallbackDate, roll forward one year.
        if ($fallbackDate && ($dt instanceof DateTime)) {
            $fb = DateTime::createFromFormat('Y-m-d', $fallbackDate);
            if ($fb instanceof DateTime && $dt < $fb) {
                $altYear = $yearHint + 1;
                error_log(
                    '[GCS DEBUG][FPPSemantics::resolveHolidayToDate] holiday ' . $holiday .
                    ' resolved before fallback (' . $dt->format('Y-m-d') .
                    ' < ' . $fb->format('Y-m-d') .
                    '), retrying with yearHint=' . $altYear
                );
                $alt = HolidayResolver::dateFromHoliday($holiday, $altYear);
                if ($alt instanceof DateTime) {
                    $dt = $alt;
                }
            }
        }

        return ($dt instanceof DateTime) ? $dt : null;
    }
    /* =====================================================================
     * Command payload semantics
     * ===================================================================== */

    /**
     * Build a canonical command payload from a scheduler entry.
     *
     * CONTRACT:
     * - Opaque: this method MUST NOT interpret command meaning
     * - Stable: same payload content MUST hash identically
     * - Non-invasive: MUST NOT invent defaults or remove values
     * - Centralized: ALL command payload construction flows through here
     *
     * @param array $entry
     * @return array<string,mixed>
     */
    public static function buildCommandPayload(array $entry): array
    {
        $payload = [];

        // Preserve args verbatim if present
        if (array_key_exists('args', $entry)) {
            $payload['args'] = $entry['args'];
        }

        // Preserve existing payload block verbatim if present
        if (array_key_exists('payload', $entry) && is_array($entry['payload'])) {
            foreach ($entry['payload'] as $key => $value) {
                $payload[$key] = $value;
            }
        }

        // Ensure stable ordering for hashing / comparison
        ksort($payload);

        return $payload;
    }
    /* =====================================================================
     * Type / target inference from FPP scheduler entries
     * ===================================================================== */

    /**
     * Infer semantic type and target from a raw FPP schedule entry.
     *
     * This is used when planner intent is absent (adoption, identity refresh).
     *
     * @param array<string,mixed> $entry
     * @return array{type:string,target:?string}
     */
    public static function inferTypeAndTargetFromScheduleEntry(array $entry): array
    {
        // Command entries
        if (!empty($entry['command'])) {
            return [
                'type'   => self::TYPE_COMMAND,
                'target' => is_string($entry['command']) ? $entry['command'] : null,
            ];
        }

        // Playlist / sequence entries
        if (!empty($entry['playlist'])) {
            if (!empty($entry['sequence'])) {
                return [
                    'type'   => self::TYPE_SEQUENCE,
                    'target' => is_string($entry['playlist']) ? $entry['playlist'] : null,
                ];
            }

            return [
                'type'   => self::TYPE_PLAYLIST,
                'target' => is_string($entry['playlist']) ? $entry['playlist'] : null,
            ];
        }

        // Fallback: treat as playlist with unknown target
        return [
            'type'   => self::TYPE_PLAYLIST,
            'target' => null,
        ];
    }
}