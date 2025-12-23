<?php
declare(strict_types=1);

/**
 * CalendarReader
 *
 * Read-only calendar ingestion helper.
 *
 * Responsibilities:
 * - Fetch ICS data using existing project components
 * - Parse calendar events
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
        // Validate presence of ICS URL
        $icsUrl = (string)($config['calendar']['ics_url'] ?? '');
        if ($icsUrl === '') {
            return [
                'events' => 0,
                'note'   => 'No ICS URL configured',
            ];
        }

        // Fetch ICS data (read-only)
        $fetcher = new GcsIcsFetcher($icsUrl);
        $icsData = $fetcher->fetch();

        // Parse ICS data (read-only)
        $parser = new GcsIcsParser($icsData);
        $events = $parser->parse();

        // Produce summary only (no event objects returned)
        return [
            'events' => count($events),
        ];
    }
}
