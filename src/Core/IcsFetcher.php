<?php
declare(strict_types=1);

/**
 * IcsFetcher
 *
 * Low-level HTTP fetcher for calendar ICS data.
 *
 * RESPONSIBILITIES:
 * - Retrieve raw ICS text from a configured URL
 * - Apply a conservative timeout
 * - Log failures without throwing
 *
 * HARD RULES:
 * - Never throws
 * - Never parses ICS content
 * - Never mutates configuration
 *
 * ERROR HANDLING:
 * - All failures return an empty string
 * - Errors are logged via GcsLogger
 *
 * NOTE:
 * SSL verification is explicitly disabled to match common
 * Google Calendar / self-hosted ICS usage in constrained
 * FPP environments.
 */
final class IcsFetcher
{
    /**
     * Fetch raw ICS content from a URL.
     *
     * @param string $url Fully-qualified ICS URL
     * @return string Raw ICS data or empty string on failure
     */
    public function fetch(string $url): string
    {
        if ($url === '') {
            GcsLogger::instance()->error('ICS URL is empty');
            return '';
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
            ],
            'ssl' => [
                // Intentionally disabled for FPP environments
                'verify_peer'      => false,
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
