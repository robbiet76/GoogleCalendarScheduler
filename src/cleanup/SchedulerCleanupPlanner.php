<?php
declare(strict_types=1);

/**
 * SchedulerCleanupPlanner (Phase 23.4)
 *
 * Read-only planner that identifies unmanaged entries that can be safely removed
 * because an equivalent managed entry exists.
 *
 * HARD RULES:
 * - Never deletes anything
 * - Never treats unmanaged as safe unless a managed equivalent exists
 * - Managed equivalence is semantic (type/target/time/day/date/repeat/stopType)
 */
final class SchedulerCleanupPlanner
{
    /**
     * @return array{
     *   ok: bool,
     *   counts: array{total:int, managed:int, unmanaged:int, candidates:int, blocked:int},
     *   candidates: array<int,array<string,mixed>>,
     *   blocked: array<int,array<string,mixed>>,
     *   errors: array<int,string>
     * }
     */
    public static function plan(): array
    {
        try {
            $entries = SchedulerSync::readScheduleJsonStatic(SchedulerSync::SCHEDULE_JSON_PATH);

            $total = 0;
            $managedCount = 0;
            $unmanagedCount = 0;

            // Build managed fingerprint set
            $managedFingerprints = [];
            foreach ($entries as $idx => $e) {
                if (!is_array($e)) continue;
                $total++;

                if (GcsSchedulerIdentity::isGcsManaged($e)) {
                    $managedCount++;
                    $fp = self::fingerprint($e);
                    if ($fp !== '') {
                        $managedFingerprints[$fp] = true;
                    }
                }
            }

            $candidates = [];
            $blocked = [];

            foreach ($entries as $idx => $e) {
                if (!is_array($e)) continue;

                if (GcsSchedulerIdentity::isGcsManaged($e)) {
                    continue;
                }

                $unmanagedCount++;

                $fp = self::fingerprint($e);
                if ($fp === '') {
                    $blocked[] = [
                        'index' => $idx,
                        'reason' => 'Unable to compute fingerprint (missing required fields).',
                    ];
                    continue;
                }

                if (!empty($managedFingerprints[$fp])) {
                    $candidates[] = [
                        'index' => $idx,
                        'fingerprint' => $fp,
                    ];
                } else {
                    $blocked[] = [
                        'index' => $idx,
                        'fingerprint' => $fp,
                        'reason' => 'No managed equivalent found.',
                    ];
                }
            }

            return [
                'ok' => true,
                'counts' => [
                    'total'      => $total,
                    'managed'    => $managedCount,
                    'unmanaged'  => $unmanagedCount,
                    'candidates' => count($candidates),
                    'blocked'    => count($blocked),
                ],
                'candidates' => $candidates,
                'blocked'    => $blocked,
                'errors'     => [],
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'counts' => [
                    'total'      => 0,
                    'managed'    => 0,
                    'unmanaged'  => 0,
                    'candidates' => 0,
                    'blocked'    => 0,
                ],
                'candidates' => [],
                'blocked'    => [],
                'errors' => ['Planner failed: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * Build a deterministic semantic fingerprint for matching unmanaged vs managed.
     *
     * We intentionally ignore:
     * - args[] (managed will have tag; unmanaged won’t)
     * - enabled flag (users may toggle; does not change schedule semantics)
     *
     * We include:
     * - type (playlist|command)
     * - target (playlist/command name)
     * - day enum (FPP selector)
     * - startTime/endTime
     * - startDate/endDate
     * - repeat
     * - stopType
     */
    public static function fingerprint(array $e): string
    {
        $type = '';
        $target = '';

        $cmd = isset($e['command']) ? trim((string)$e['command']) : '';
        $pl  = isset($e['playlist']) ? trim((string)$e['playlist']) : '';

        if ($cmd !== '') {
            $type = 'command';
            $target = $cmd;
        } elseif ($pl !== '') {
            $type = 'playlist';
            $target = $pl;
        } else {
            return '';
        }

        $day = isset($e['day']) ? (int)$e['day'] : null;
        $startTime = isset($e['startTime']) ? (string)$e['startTime'] : '';
        $endTime   = isset($e['endTime']) ? (string)$e['endTime'] : '';
        $startDate = isset($e['startDate']) ? (string)$e['startDate'] : '';
        $endDate   = isset($e['endDate']) ? (string)$e['endDate'] : '';

        $repeat   = isset($e['repeat']) ? (int)$e['repeat'] : 0;
        $stopType = isset($e['stopType']) ? (int)$e['stopType'] : 0;

        if ($day === null || $startTime === '' || $endTime === '' || $startDate === '' || $endDate === '') {
            return '';
        }

        // Normalize common whitespace issues
        $target = trim($target);
        $startTime = trim($startTime);
        $endTime = trim($endTime);
        $startDate = trim($startDate);
        $endDate = trim($endDate);

        return implode('|', [
            $type,
            $target,
            (string)$day,
            $startTime,
            $endTime,
            $startDate,
            $endDate,
            (string)$repeat,
            (string)$stopType,
        ]);
    }
}
