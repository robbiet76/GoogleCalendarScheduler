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
     * ===================================================================== */

    public static function resolveDate(
        string $raw,
        ?string $fallbackDate,
        array &$warnings,
        string $context
    ): ?string {
        $raw = trim($raw);

        // Absolute date
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            if (self::isSentinelDate($raw)) {
                $year = (int)date('Y');
                return sprintf('%04d-%s', $year, substr($raw, 5));
            }
            return $raw;
        }

        // Holiday (resolved via FPP locale)
        if ($raw !== '') {
            $yearHint = $fallbackDate
                ? (int)substr($fallbackDate, 0, 4)
                : (int)date('Y');

            $dt = HolidayResolver::dateFromHoliday($raw, $yearHint);

            if ($dt instanceof DateTime) {
                return $dt->format('Y-m-d');
            }
        }

        $warnings[] = "Export: {$context} '{$raw}' invalid.";
        return null;
    }
}