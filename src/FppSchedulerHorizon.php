<?php

/**
 * Reads FPP scheduler horizon (ScheduleSeconds) and converts to days.
 */
class FppSchedulerHorizon
{
    /**
     * @return int Horizon in days
     */
    public static function getDays()
    {
        $settingsFile = '/home/fpp/media/config/settings.json';

        if (!file_exists($settingsFile)) {
            // Safe default if settings unavailable
            return 30;
        }

        $json = json_decode(file_get_contents($settingsFile), true);
        if (!is_array($json)) {
            return 30;
        }

        $seconds = (int)($json['system_settings'][0]['ScheduleSeconds'] ?? 0);

        if ($seconds > 0) {
            return max(1, (int)ceil($seconds / 86400));
        }

        // FPP default when unset — still cap safely
        return 30;
    }
}
