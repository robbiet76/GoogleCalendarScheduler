<?php

class GcsIcsFetcher
{
    public function fetch(string $url): string
    {
        if (empty($url)) {
            GcsLogger::instance()->error('ICS URL is empty');
            return '';
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            $err = error_get_last();
            GcsLogger::instance()->error('ICS fetch failed', [
                'error' => $err['message'] ?? 'unknown',
            ]);
            return '';
        }

        return (string)$data;
    }
}

