<?php
declare(strict_types=1);

/**
 * CalendarReader
 *
 * Read-only calendar ingestion helper.
 *
 * Responsibilities:
 * - Fetch ICS data using existing project components
 * - Parse calendar events using canonical parser API
 * - Return summary information only
 *
 * IMPORTANT:
 * - No scheduler interaction
 * - No logging
 * - No config mutation
 * - No side effects
 */
final class CalendarReader
{
    /**
     * Read and summarize calendar events.
     *
     * @param array $config Loaded plugin configuration
     * @return array Summary information about the calendar
     */
    public static function readSummary(array $config): array
    {
        $icsUrl = (string)($config['calendar']['ics_url'] ?? '');
        if ($icsUrl === '') {
            return [
                'events' => 0,
                'note'   => 'No ICS URL configured',
            ];
        }

        // Fetch ICS data (read-only)
        $fetcher = new GcsIcsFetcher();
        $icsData = $fetcher->fetch($icsUrl);

        // Parse ICS data (read-only, canonical API)
        $parser = new GcsIcsParser();

        $now = new DateTime();
        $horizonDays = GcsFppSchedulerHorizon::getDays();
        $horizonEnd = (clone $now)->modify('+' . $horizonDays . ' days');

        $events = $parser->parse($icsData, $now, $horizonEnd);

        // Summary only — no event objects returned
        return [
            'events' => count($events),
        ];
    }
}
