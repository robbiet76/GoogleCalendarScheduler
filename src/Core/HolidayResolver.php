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
     * Resolve a holiday shortName to a DateTime.
     *
     * @param string $shortName Holiday identifier from schedule.json
     * @param int    $year      Target year
     *
     * @return DateTime|null
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

        // Fixed-date holiday
        if (isset($def['month'], $def['day'])) {
            return new DateTime(sprintf(
                '%04d-%02d-%02d',
                $year,
                (int)$def['month'],
                (int)$def['day']
            ));
        }

        // Calculated holiday
        if (isset($def['calc']) && is_array($def['calc'])) {
            return self::resolveCalculatedHoliday($def['calc'], $year);
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

        // Convert FPP weekday to PHP ISO-8601 weekday (1=Monday .. 7=Sunday)
        $phpDow = ($fppDow === 0) ? 7 : $fppDow;

        $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));

        if ($calc['type'] === 'tail') {
            $d->modify('last day of this month');
            while ((int)$d->format('N') !== $phpDow) {
                $d->modify('-1 day');
            }
        } else {
            while ((int)$d->format('N') !== $phpDow) {
                $d->modify('+1 day');
            }
        }

        $d->modify('+' . (($week - 1) * 7) . ' days');
        return $d;
    }
}