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
        // Load runtime FPP environment
        // -----------------------------------------------------------------
        $env = FppEnvironment::loadFromFile(
            self::FPP_ENV_PATH,
            $warnings
        );

        // Register environment with FPPSemantics
        FPPSemantics::setEnvironment($env->toArray());

        if ($env->getTimezone()) {
            date_default_timezone_set($env->getTimezone());
        }

        // -----------------------------------------------------------------
        // Adapt scheduler entries
        // -----------------------------------------------------------------
        $events = [];

        foreach ($entries as $entry) {
            $adapted = ScheduleEntryExportAdapter::adapt($entry, $warnings);
            if ($adapted !== null) {
                $events[] = $adapted;
            }
        }

        // -----------------------------------------------------------------
        // Build ICS payload
        // -----------------------------------------------------------------
        $ics = null;

        if (!empty($events)) {
            try {
                $ics = IcsWriter::build($events);

                if (!is_string($ics) || trim($ics) === '') {
                    error_log(
                        '[GCS ERROR] IcsWriter returned empty payload'
                    );
                    $warnings[] =
                        'ICS export failed (no ICS payload returned).';
                    $ics = null;
                }
            } catch (Throwable $e) {
                error_log(
                    '[GCS ERROR] IcsWriter exception: ' . $e->getMessage()
                );
                $warnings[] =
                    'ICS export failed: ' . $e->getMessage();
                $ics = null;
            }
        } else {
            error_log(
                '[GCS DEBUG] No events passed to IcsWriter'
            );
        }

        return [
            'events'   => $events,
            'ics'      => $ics,
            'warnings' => $warnings,
            'fppEnv'   => $env->toArray(),
        ];
    }
}