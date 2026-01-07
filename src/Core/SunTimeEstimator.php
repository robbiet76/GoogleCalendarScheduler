<?php
declare(strict_types=1);

/**
 * SunTimeEstimator
 *
 * Purpose:
 * - Estimate sunrise, sunset, dawn, and dusk times using latitude/longitude
 * - Intended for CALENDAR DISPLAY ONLY (not runtime execution)
 *
 * Design goals:
 * - Deterministic
 * - No FPP dependencies
 * - No external APIs
 * - Good-enough accuracy (±10–15 minutes typical)
 * - Stable across PHP versions
 *
 * Algorithm:
 * - Based on NOAA solar calculations (simplified)
 * - Civil twilight used for Dawn/Dusk
 */
final class SunTimeEstimator
{
    /** Civil twilight angle */
    private const CIVIL_TWILIGHT_DEGREES = -6.0;

    /**
     * Resolve symbolic sun time for a date.
     *
     * @param string $ymd  YYYY-MM-DD
     * @param string $type Dawn | Dusk | SunRise | SunSet
     * @param float  $lat  Latitude
     * @param float  $lon  Longitude
     * @param int    $offsetMinutes Optional offset (FPP-style)
     * @param int    $roundMinutes  Rounding granularity (default 30)
     *
     * @return string|null HH:MM:SS or null if not computable
     */
    public static function estimate(
        string $ymd,
        string $type,
        float $lat,
        float $lon,
        int $offsetMinutes = 0,
        int $roundMinutes = 30
    ): ?string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }

        $ts = strtotime($ymd . ' 12:00:00');
        if ($ts === false) {
            return null;
        }

        [$sunrise, $sunset, $dawn, $dusk] =
            self::calculateSunTimes($ts, $lat, $lon);

        $baseSeconds = match ($type) {
            'SunRise' => $sunrise,
            'SunSet'  => $sunset,
            'Dawn'    => $dawn,
            'Dusk'    => $dusk,
            default   => null,
        };

        if ($baseSeconds === null) {
            return null;
        }

        $seconds = $baseSeconds + ($offsetMinutes * 60);

        return self::roundSeconds($seconds, $roundMinutes);
    }

    /* =====================================================================
     * Core solar math
     * ===================================================================== */

    /**
     * @return array{int,int,int,int}
     *   sunrise, sunset, dawn, dusk (seconds since midnight)
     */
    private static function calculateSunTimes(
        int $timestamp,
        float $lat,
        float $lon
    ): array {
        $dayOfYear = (int)date('z', $timestamp) + 1;

        $lngHour = $lon / 15.0;

        $sunrise = self::calcSolarTime($dayOfYear, $lat, $lngHour, true,  -0.833);
        $sunset  = self::calcSolarTime($dayOfYear, $lat, $lngHour, false, -0.833);

        $dawn = self::calcSolarTime(
            $dayOfYear,
            $lat,
            $lngHour,
            true,
            self::CIVIL_TWILIGHT_DEGREES
        );

        $dusk = self::calcSolarTime(
            $dayOfYear,
            $lat,
            $lngHour,
            false,
            self::CIVIL_TWILIGHT_DEGREES
        );

        return [
            $sunrise,
            $sunset,
            $dawn,
            $dusk,
        ];
    }

    private static function calcSolarTime(
        int $dayOfYear,
        float $lat,
        float $lngHour,
        bool $isRise,
        float $zenith
    ): int {
        $t = $dayOfYear + (($isRise ? 6 : 18) - $lngHour) / 24;

        $M = (0.9856 * $t) - 3.289;

        $L = $M
           + (1.916 * sin(deg2rad($M)))
           + (0.020 * sin(deg2rad(2 * $M)))
           + 282.634;

        $L = fmod($L + 360, 360);

        $RA = rad2deg(atan(0.91764 * tan(deg2rad($L))));
        $RA = fmod($RA + 360, 360);

        $Lquadrant  = floor($L / 90) * 90;
        $RAquadrant = floor($RA / 90) * 90;
        $RA = ($RA + ($Lquadrant - $RAquadrant)) / 15;

        $sinDec = 0.39782 * sin(deg2rad($L));
        $cosDec = cos(asin($sinDec));

        $cosH =
            (cos(deg2rad(90 + $zenith)) -
             ($sinDec * sin(deg2rad($lat))))
            / ($cosDec * cos(deg2rad($lat)));

        // Polar day/night guard
        if ($cosH > 1 || $cosH < -1) {
            return $isRise ? 6 * 3600 : 18 * 3600;
        }

        $H = $isRise
            ? 360 - rad2deg(acos($cosH))
            : rad2deg(acos($cosH));

        $H /= 15;

        $T = $H + $RA - (0.06571 * $t) - 6.622;

        $UT = fmod($T - $lngHour + 24, 24);

        return (int)round($UT * 3600);
    }

    /* =====================================================================
     * Rounding helpers
     * ===================================================================== */

    private static function roundSeconds(int $seconds, int $roundMinutes): string
    {
        $seconds = max(0, $seconds);

        $minutes = (int)round($seconds / 60);
        $rounded = (int)(round($minutes / $roundMinutes) * $roundMinutes);

        $hours = intdiv($rounded, 60);
        $mins  = $rounded % 60;

        if ($hours > 23) {
            $hours = 23;
            $mins  = 59;
        }

        return sprintf('%02d:%02d:00', $hours, $mins);
    }
}