<?php

final class GcsConfig {
    public static function defaults(): array {
        return [
            "version" => 1,

            "calendar" => [
                "ics_url" => ""
            ],

            "runtime" => [
                "dry_run" => true
            ],

            /*
             * ------------------------------------------------------------
             * Experimental features (Phase 11)
             * ------------------------------------------------------------
             * All experimental behavior is gated behind this flag.
             * Default MUST remain false.
             */
            "experimental" => [
                "enabled" => false
            ],

            "sync" => [
                "last_run" => null,
                "last_status" => "never",
                "last_error" => null,
                "events_processed" => 0,
                "schedules_added" => 0,
                "schedules_updated" => 0,
                "schedules_removed" => 0
            ]
        ];
    }

    public static function load(): array {
        if (!is_file(GCS_CONFIG_PATH)) {
            return self::defaults();
        }

        $cfg = json_decode(@file_get_contents(GCS_CONFIG_PATH), true);

        return is_array($cfg)
            ? array_replace_recursive(self::defaults(), $cfg)
            : self::defaults();
    }

    public static function save(array $cfg): void {
        @mkdir(dirname(GCS_CONFIG_PATH), 0775, true);

        file_put_contents(
            GCS_CONFIG_PATH,
            json_encode(
                $cfg,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n"
        );
    }
}
