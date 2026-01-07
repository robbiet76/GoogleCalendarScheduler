<?php
declare(strict_types=1);

final class ExportService
{
    private const FPP_ENV_PATH =
        __DIR__ . '/../../runtime/fpp-env.json';

    /**
     * Load FPP environment exported by C++ layer.
     */
    private static function loadFppEnv(array &$warnings): array
    {
        if (!is_file(self::FPP_ENV_PATH)) {
            $warnings[] = 'FPP environment not available (fpp-env.json missing).';
            return [
                'ok'        => false,
                'latitude'  => null,
                'longitude' => null,
                'timezone'  => null,
            ];
        }

        $raw = @file_get_contents(self::FPP_ENV_PATH);
        if ($raw === false) {
            $warnings[] = 'Unable to read fpp-env.json.';
            return [
                'ok'        => false,
                'latitude'  => null,
                'longitude' => null,
                'timezone'  => null,
            ];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $warnings[] = 'FPP environment invalid JSON.';
            return [
                'ok'        => false,
                'latitude'  => null,
                'longitude' => null,
                'timezone'  => null,
            ];
        }

        if (($json['schemaVersion'] ?? 0) !== 1) {
            $warnings[] = 'Unsupported FPP environment schema version.';
        }

        if (!($json['ok'] ?? false)) {
            $warnings[] = $json['error'] ?? 'FPP environment reported error.';
        }

        return [
            'ok'        => (bool)($json['ok'] ?? false),
            'latitude'  => is_numeric($json['latitude'] ?? null)
                ? (float)$json['latitude']
                : null,
            'longitude' => is_numeric($json['longitude'] ?? null)
                ? (float)$json['longitude']
                : null,
            'timezone'  => is_string($json['timezone'] ?? null)
                ? $json['timezone']
                : null,
        ];
    }

    /**
     * Export scheduler entries into calendar payload.
     */
    public static function export(array $entries): array
    {
        $warnings = [];

        $fppEnv = FppEnvironment::load(self::FPP_ENV_PATH, $warnings);

        if ($fppEnv->timezone()) {
            date_default_timezone_set($fppEnv->timezone());
        }

        // Set default timezone once (safe here)
        if ($fppEnv['timezone']) {
            date_default_timezone_set($fppEnv['timezone']);
        }

        $events = [];

        foreach ($entries as $entry) {
            $adapted = ScheduleEntryExportAdapter::adapt($entry, $warnings);
            if ($adapted) {
                $events[] = $adapted;
            }
        }

        return [
            'events'   => $events,
            'warnings' => $warnings,
            'fppEnv'   => [
                'ok'        => $fppEnv['ok'],
                'latitude'  => $fppEnv['latitude'],
                'longitude' => $fppEnv['longitude'],
                'timezone'  => $fppEnv['timezone'],
            ],
        ];
    }
}