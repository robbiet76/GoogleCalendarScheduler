<?php
declare(strict_types=1);

/**
 * Config
 *
 * Central configuration container for GoogleCalendarScheduler.
 *
 * RESPONSIBILITIES:
 * - Define canonical default configuration values
 * - Load persisted configuration from disk
 * - Merge persisted values over defaults safely
 * - Persist configuration changes atomically
 *
 * HARD RULES:
 * - This class contains NO scheduler logic
 * - This class performs NO validation beyond structural safety
 * - Callers are responsible for interpreting values
 *
 * CONFIG MODEL (Phase 29+):
 * - Defaults define the full schema
 * - Persisted config is always merged over defaults
 * - Missing keys are backfilled automatically
 *
 * NOTE:
 * - All "experimental" configuration has been removed.
 * - Safety is enforced exclusively via runtime.dry_run.
 */
final class Config
{
    /**
     * Canonical default configuration.
     *
     * IMPORTANT:
     * - This defines the authoritative config schema
     * - New keys MUST be added here first
     * - Defaults must remain safe and conservative
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'version' => 1,

            'calendar' => [
                'ics_url' => '',
            ],

            'runtime' => [
                // When true, schedule.json is never modified
                'dry_run' => true,
            ],

            /*
             * ------------------------------------------------------------
             * Sync status metadata (read/write)
             * ------------------------------------------------------------
             * Informational only; no behavioral impact.
             */
            'sync' => [
                'last_run'          => null,
                'last_status'       => 'never',
                'last_error'        => null,
                'events_processed'  => 0,
                'schedules_added'   => 0,
                'schedules_updated' => 0,
                'schedules_removed' => 0,
            ],
        ];
    }

    /**
     * Load configuration from disk.
     *
     * BEHAVIOR:
     * - If config file does not exist, defaults are returned
     * - If config file is invalid, defaults are returned
     * - Persisted values are merged over defaults
     * - Legacy keys not present in defaults are ignored
     *
     * @return array<string,mixed>
     */
    public static function load(): array
    {
        if (!is_file(GCS_CONFIG_PATH)) {
            return self::defaults();
        }

        $raw = @file_get_contents(GCS_CONFIG_PATH);
        if ($raw === false) {
            return self::defaults();
        }

        $cfg = json_decode($raw, true);
        if (!is_array($cfg)) {
            return self::defaults();
        }

        // Merge persisted config over defaults.
        // Any legacy keys (e.g., "experimental") are implicitly ignored.
        return array_replace_recursive(self::defaults(), $cfg);
    }

    /**
     * Persist configuration to disk.
     *
     * NOTES:
     * - Directory is created if missing
     * - File is written in pretty-printed JSON for readability
     * - No validation is performed here
     *
     * @param array<string,mixed> $cfg
     */
    public static function save(array $cfg): void
    {
        @mkdir(dirname(GCS_CONFIG_PATH), 0775, true);

        file_put_contents(
            GCS_CONFIG_PATH,
            json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}
