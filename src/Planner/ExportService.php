<?php
declare(strict_types=1);

final class ExportService
{
    private const FPP_ENV_PATH =
        __DIR__ . '/../../runtime/fpp-env.json';

    /**
     * Export scheduler entries into calendar payload.
     *
     * @param array<int,array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    public static function export(array $entries): array
    {
        $warnings = [];

        // -----------------------------------------------------------------
        // DEBUG: entry count
        // -----------------------------------------------------------------
        error_log(sprintf(
            '[GCS DEBUG][ExportService] export() called with %d entries',
            count($entries)
        ));

        // -----------------------------------------------------------------
        // Load runtime FPP environment
        // -----------------------------------------------------------------
        $env = FppEnvironment::loadFromFile(
            self::FPP_ENV_PATH,
            $warnings
        );

        // Register environment with FPPSemantics
        FPPSemantics::setEnvironment($env->toArray());

        // Optional: set PHP default timezone for DateTime operations
        if ($env->getTimezone()) {
            date_default_timezone_set($env->getTimezone());
        }

        // -----------------------------------------------------------------
        // Export entries → events
        // -----------------------------------------------------------------
        $events = [];

        foreach ($entries as $idx => $entry) {
            $adapted = ScheduleEntryExportAdapter::adapt($entry, $warnings);

            if ($adapted !== null) {
                $events[] = $adapted;
            } else {
                error_log(sprintf(
                    '[GCS DEBUG][ExportService] entry #%d skipped by adapter',
                    $idx
                ));
            }
        }

        error_log(sprintf(
            '[GCS DEBUG][ExportService] adapter produced %d events',
            count($events)
        ));

        // -----------------------------------------------------------------
        // Generate ICS payload
        // -----------------------------------------------------------------
        $ics = null;

        try {
            $ics = IcsWriter::write($events, $warnings);
        } catch (Throwable $e) {
            error_log('[GCS DEBUG][ExportService] IcsWriter threw exception: ' . $e->getMessage());
        }

        if (!is_string($ics) || trim($ics) === '') {
            error_log('[GCS DEBUG][ExportService] IcsWriter returned EMPTY payload');
        } else {
            error_log(sprintf(
                '[GCS DEBUG][ExportService] IcsWriter payload length = %d bytes',
                strlen($ics)
            ));
        }

        // -----------------------------------------------------------------
        // Return export bundle
        // -----------------------------------------------------------------
        return [
            'events'   => $events,
            'ics'      => $ics,
            'warnings' => $warnings,
            'fppEnv'   => $env->toArray(),
        ];
    }
}