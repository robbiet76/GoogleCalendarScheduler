<?php
declare(strict_types=1);

/**
 * HolidayResolver
 *
 * Resolves holiday identifiers used by FPP scheduler into concrete dates
 * using the FPP runtime locale exported via fpp-env.json.
 *
 * Canonical rules:
 * - shortName is the ONLY canonical identifier
 * - schedule.json stores shortName values (e.g. "NewYearsEve")
 * - UI "name" is NOT used for resolution
 * - All rules come from FPP (no hard-coded tables)
 *
 * This class is PURE:
 * - No I/O
 * - No scheduler logic
 * - No DateTime side effects beyond construction
 */
final class HolidayResolver
{
    /**
     * Cached holiday definitions indexed by shortName.
     *
     * @var array<string,array<string,mixed>>|null
     */
    private static ?array $holidayIndex = null;

    /* ============================================================
     * Public API
     * ============================================================ */

    /**
     * Determine whether a value is a known holiday token.
     *
     * Identity-safe:
     * - Does NOT resolve dates
     * - Does NOT depend on year
     *
     * @param string $value
     * @return bool
     */
    public static function isHolidayToken(string $value): bool
    {
        $index = self::getHolidayIndex();
        return isset($index[$value]);
    }

    /**
     * Attempt to lift a concrete Y-m-d date into a holiday shortName.
     *
     * Identity-safe:
     * - Concrete -> symbolic only
     * - Never resolves symbols into dates
     * - Deterministic for a given year
     *
     * Intended for ManifestIdentity normalization.
     *
     * @param string $ymd Date string in Y-m-d format
     * @return string|null Holiday shortName or null if not a holiday
     */
    public static function dateToHoliday(string $ymd): ?string
    {
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $ymd)) {
            return null;
        }

        try {
            $dt = new DateTime($ymd);
        } catch (\Exception $e) {
            return null;
        }

        $year = (int)$dt->format('Y');

        foreach (self::getHolidayIndex() as $shortName => $_def) {
            $resolved = self::dateFromHoliday($shortName, $year);
            if ($resolved instanceof DateTime && $resolved->format('Y-m-d') === $ymd) {
                return $shortName;
            }
        }

        return null;
    }

    /**
     * Resolve a holiday shortName to a DateTime.
     *
     * @param string $shortName Holiday identifier from schedule.json
     * @param int    $year      Target year
     *
     * @return DateTime|null
     *
     * ⚠️ Runtime-only:
     * MUST NOT be used by ManifestIdentity or identity comparison.
     */
    public static function dateFromHoliday(string $shortName, int $year): ?DateTime
    {
        $index = self::getHolidayIndex();
        if ($index === []) {
            return null;
        }

        if (!isset($index[$shortName])) {
            return null;
        }

        $def = $index[$shortName];

        // Calculated holiday (FPP uses month/day=0 with a calc block)
        if (isset($def['calc']) && is_array($def['calc'])) {
            $dt = self::resolveCalculatedHoliday($def['calc'], $year);
            if (defined('GCS_DEBUG_HOLIDAYS') && GCS_DEBUG_HOLIDAYS) {
                if ($dt instanceof DateTime) {
                    error_log(
                        '[GCS DEBUG][HolidayResolver] ' .
                        $shortName . ' ' . $year . ' resolved to ' . $dt->format('Y-m-d')
                    );
                } else {
                    error_log(
                        '[GCS DEBUG][HolidayResolver] ' .
                        $shortName . ' ' . $year . ' failed to resolve'
                    );
                }
            }
            return $dt;
        }

        // Fixed-date holiday (only when month/day are real values)
        if (isset($def['month'], $def['day'])) {
            $m = (int)$def['month'];
            $d = (int)$def['day'];
            if ($m >= 1 && $m <= 12 && $d >= 1 && $d <= 31) {
                return new DateTime(sprintf('%04d-%02d-%02d', $year, $m, $d));
            }
        }

        return null;
    }

    /* ============================================================
     * Internal helpers
     * ============================================================ */

    /**
     * Build lookup index from FPP locale holidays.
     *
     * Indexed strictly by shortName.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function getHolidayIndex(): array
    {
        if (self::$holidayIndex !== null) {
            return self::$holidayIndex;
        }

        self::$holidayIndex = [];

        if (!class_exists('FppEnvironment')) {
            return self::$holidayIndex;
        }

        // The environment is already loaded during bootstrap
        // and exposed via Config / runtime paths.
        $warnings = [];
        $envPath  = __DIR__ . '/../../runtime/fpp-env.json';

        if (!is_file($envPath)) {
            return self::$holidayIndex;
        }

        $env = FppEnvironment::loadFromFile($envPath, $warnings);
        $raw = $env->getRaw();

        $holidays = $raw['rawLocale']['holidays'] ?? null;
        if (!is_array($holidays)) {
            return self::$holidayIndex;
        }

        foreach ($holidays as $h) {
            if (!is_array($h)) {
                continue;
            }

            if (!isset($h['shortName']) || !is_string($h['shortName'])) {
                continue;
            }

            // shortName is canonical and exact-match
            self::$holidayIndex[$h['shortName']] = $h;
        }

        return self::$holidayIndex;
    }

    /**
     * Resolve calculated (non-fixed) holidays.
     *
     * Supported types mirror FPP locale definitions.
     */
    private static function resolveCalculatedHoliday(array $calc, int $year): ?DateTime
    {
        // Easter-based holiday
        if (($calc['type'] ?? '') === 'easter') {
            $base = new DateTime();
            $base->setTimestamp(easter_date($year));
            $base->setTime(0, 0, 0);

            $offset = (int)($calc['offset'] ?? 0);
            return $base->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        }

        // Weekday-based holiday (e.g. Thanksgiving)
        if (
            !isset($calc['month'], $calc['dow'], $calc['week'], $calc['type'])
        ) {
            return null;
        }

        $month = (int)$calc['month'];
        $fppDow = (int)$calc['dow'];   // FPP: 0=Sunday .. 6=Saturday
        $week  = (int)$calc['week'];
        $origMonth = $month;

        // Convert FPP weekday to PHP ISO-8601 weekday (1=Monday .. 7=Sunday)
        $isoDow = ($fppDow === 0) ? 7 : $fppDow;

        if ($calc['type'] === 'tail') {
            $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));
            $d->modify('last day of this month');
            while ((int)$d->format('N') !== $isoDow) {
                $d->modify('-1 day');
            }
            $d->modify('-' . (($week - 1) * 7) . ' days');

            if ((int)$d->format('n') !== $origMonth) {
                return null;
            }

            return $d;
        }

        if ($calc['type'] === 'head') {
            // Canonical Nth-weekday-of-month calculation (FPP-compatible)
            $firstOfMonth = new DateTime(sprintf('%04d-%02d-01', $year, $month));
            $firstDow = (int)$firstOfMonth->format('N');

            $delta = ($isoDow - $firstDow + 7) % 7;
            $day = 1 + $delta + (($week - 1) * 7);

            if ($day < 1 || $day > (int)$firstOfMonth->format('t')) {
                return null;
            }

            return new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
        }

        return null;
    }
}
