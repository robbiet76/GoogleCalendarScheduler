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

        foreach ($result->creates() as $intent) {
            $rows[] = self::row('create', $intent);
        }

        foreach ($result->updates() as $pair) {
            // updates are { before, after }
            $rows[] = self::row('update', $pair['after']);
        }

        foreach ($result->deletes() as $intent) {
            $rows[] = self::row('delete', $intent);
        }

        return [
            'version'  => 1,
            'mode'     => 'preview',
            'summary'  => $result->summary(),
            'rows'     => $rows,
            'messages' => $result->messages(),
        ];
    }

    private static function row(string $action, $intent): array
    {
        return [
            'action' => $action,               // create | update | delete
            'type'   => $intent->getType(),    // playlist | sequence | command
            'target' => $intent->getTarget(),

            'when' => [
                'day'       => $intent->getDay(),
                'startTime' => $intent->getStartTime(),
                'endTime'   => $intent->getEndTime(),
                'startDate' => $intent->getStartDate(),
                'endDate'   => $intent->getEndDate(),
            ],
        ];
    }
}