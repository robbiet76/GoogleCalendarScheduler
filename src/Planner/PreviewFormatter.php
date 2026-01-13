<?php
declare(strict_types=1);

/**
 * PreviewFormatter
 *
 * Translates a manifest diff into a UI-safe, human-readable preview.
 *
 * Responsibilities:
 *  - NO mutation
 *  - NO reconciliation logic
 *  - NO scheduler.json inspection
 *  - Safe for dry-run / preview mode
 *
 * Operates ONLY on canonical array representations.
 */
final class PreviewFormatter
{
    public static function format(ManifestResult $manifest): array
    {
        $rows = [];

        foreach ($manifest->creates() as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('PreviewFormatter expects array entries only');
            }
            $canonical = $entry;
            $rows[] = self::row('create', $canonical);
        }

        foreach ($manifest->updates() as $pair) {
            if (is_array($pair) && isset($pair['after'])) {
                $after = $pair['after'];
                if (!is_array($after)) {
                    throw new RuntimeException('PreviewFormatter expects array entries only');
                }
                $canonical = $after;
                $rows[] = self::row('update', $canonical);
            }
        }

        foreach ($manifest->deletes() as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('PreviewFormatter expects array entries only');
            }
            $canonical = $entry;
            $rows[] = self::row('delete', $canonical);
        }

        return [
            'version' => 1,
            'mode'    => 'preview',
            'summary' => [
                'creates' => count($manifest->creates()),
                'updates' => count($manifest->updates()),
                'deletes' => count($manifest->deletes()),
            ],
            'rows' => $rows,
        ];
    }

    private static function row(string $action, array $e): array
    {
        return [
            'action' => $action,
            'type'   => $e['type']   ?? 'unknown',
            'target' => $e['target'] ?? '(none)',
            'when'   => [
                'days'      => $e['days']      ?? ($e['day'] ?? null),
                'startTime' => $e['startTime'] ?? null,
                'endTime'   => $e['endTime']   ?? null,
                'startDate' => self::formatDateForPreview($e['startDate'] ?? null),
                'endDate'   => self::formatDateForPreview($e['endDate'] ?? null),
            ],
            '_manifest' => $e['_manifest'] ?? null,
        ];
    }

    private static function formatDateForPreview($value): mixed
    {
        if (is_array($value)) {
            // Dual-date identity: prefer symbolic for readability, fall back to hard
            return $value['symbolic'] ?? $value['hard'] ?? null;
        }

        return $value;
    }
}