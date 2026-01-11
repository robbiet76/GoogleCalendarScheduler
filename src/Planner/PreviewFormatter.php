<?php
declare(strict_types=1);

/**
 * PreviewFormatter
 *
 * Translates a ManifestResult into a UI-safe, human-readable preview.
 *
 * Responsibilities:
 *  - NO mutation
 *  - NO reconciliation logic
 *  - NO scheduler.json inspection
 *  - Safe for dry-run / preview mode
 *
 * Output is a flat, ordered list of preview rows.
 */
final class PreviewFormatter
{
    public static function format(ManifestResult $result): array
    {
        $rows = [];

        foreach ($result->creates() as $entry) {
            $rows[] = self::row('create', $entry);
        }

        foreach ($result->updates() as $entry) {
            $rows[] = self::row('update', $entry);
        }

        foreach ($result->deletes() as $entry) {
            $rows[] = self::row('delete', $entry);
        }

        return [
            'version' => 1,
            'mode'    => 'preview',
            'summary' => $result->summary(),
            'rows'    => $rows,
            'messages'=> $result->messages(),
        ];
    }

    private static function row(string $action, array $entry): array
    {
        return [
            'action' => $action,                 // create | update | delete
            'type'   => self::resolveType($entry), // playlist | sequence | command
            'target' => self::resolveTarget($entry),

            'when'   => [
                'day'        => $entry['day']        ?? null,
                'startTime'  => $entry['startTime']  ?? null,
                'endTime'    => $entry['endTime']    ?? null,
                'startDate'  => $entry['startDate']  ?? null,
                'endDate'    => $entry['endDate']    ?? null,
            ],
        ];
    }

    private static function resolveType(array $e): string
    {
        if (!empty($e['command'])) {
            return 'command';
        }
        if (!empty($e['sequence'])) {
            return 'sequence';
        }
        return 'playlist';
    }

    private static function resolveTarget(array $e): string
    {
        if (!empty($e['command'])) {
            return (string)$e['command'];
        }
        if (!empty($e['playlist'])) {
            return (string)$e['playlist'];
        }
        return '(unknown)';
    }
}