<?php
declare(strict_types=1);

/**
 * GcsConfig
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
 * CONFIG MODEL:
 * - Defaults define the full schema
 * - Persisted config is always merged over defaults
 * - Missing keys are backfilled automatically
 */
final class GcsConfig
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
                // When true, scheduler.json is never modified
                'dry_run' => true,
            ],

            /*
             * ------------------------------------------------------------
             * Experimental features (Phase 11)
             * ------------------------------------------------------------
             * All experimental behavior MUST be explicitly enabled.
             * Defaults MUST remain false for safety.
             */
            'experimental' => [
                'enabled'     => false,
                'allow_apply' => false,
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

        return is_array($cfg)
            ? array_replace_recursive(self::defaults(), $cfg)
            : self::defaults();
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
