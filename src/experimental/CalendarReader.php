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
 * - No config mutation
 * - No side effects
 *
 * NOTE:
 * - Phase 13 troubleshooting instrumentation added (logging only)
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

        /*
         * ------------------------------------------------------------
         * Phase 13 instrumentation (read-only)
         * ------------------------------------------------------------
         * Log every event seen by CalendarReader so we can determine:
         * - Are events reaching the reader?
         * - What titles / UIDs / DTSTART values are observed?
         *
         * No behavior changes. Logging only.
         */
        foreach ($events as $event) {
            try {
                $title   = method_exists($event, 'getTitle') ? $event->getTitle() : '(unknown)';
                $uid     = method_exists($event, 'getUid') ? $event->getUid() : '(unknown)';
                $dtstart = method_exists($event, 'getStart') ? $event->getStart() : null;

                GcsLog::info('CalendarReader event seen', [
                    'title'   => $title,
                    'uid'     => $uid,
                    'dtstart' => $dtstart instanceof DateTimeInterface
                        ? $dtstart->format(DateTimeInterface::ATOM)
                        : null,
                ]);
            } catch (Throwable $e) {
                // Never allow instrumentation to affect behavior
            }
        }

        // Summary only — no event objects returned
        return [
            'events' => count($events),
        ];
    }
}
