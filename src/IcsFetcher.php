<?php

final class GcsIcsFetcher {
    public static function fetch(string $url): string {
        if (!$url) {
            throw new RuntimeException('ICS URL is empty');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'FPP-GoogleCalendarScheduler'
        ]);

        $data = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($data === false || $code !== 200) {
            throw new RuntimeException(
                'Failed to fetch ICS',
                $code ?: 500
            );
        }

        return $data;
    }
}
