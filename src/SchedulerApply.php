<?php
/**
 * SchedulerApply
 * - dry-run: logs operations only
 * - live: applies operations to array and returns new array (writing happens elsewhere)
 */
class SchedulerApply
{
    /**
     * @param array<int,array<string,mixed>> $existing
     * @param array{add:array,update:array,delete:array} $ops
     * @param bool $dryRun
     * @param object $logger Must support ->info($msg,$ctx) and ->error($msg,$ctx)
     * @return array<int,array<string,mixed>>
     */
    public function apply(array $existing, array $ops, $dryRun, $logger)
    {
        $logger->info("Scheduler diff", [
            'adds' => count($ops['add']),
            'updates' => count($ops['update']),
            'deletes' => count($ops['delete']),
            'dryRun' => (bool)$dryRun,
        ]);

        foreach ($ops['add'] as $e) {
            $logger->info("ADD", ['playlist' => $e['playlist'] ?? null]);
        }
        foreach ($ops['update'] as $u) {
            $logger->info("UPDATE", [
                'from' => $u['from']['playlist'] ?? null,
                'to' => $u['to']['playlist'] ?? null,
                'idx' => $u['idx'] ?? null,
            ]);
        }
        foreach ($ops['delete'] as $d) {
            $logger->info("DELETE", [
                'playlist' => $d['entry']['playlist'] ?? null,
                'idx' => $d['idx'] ?? null,
            ]);
        }

        if ($dryRun) {
            return $existing;
        }

        // Apply deletes by index descending
        $deleteIdx = array_map(function($d) { return (int)$d['idx']; }, $ops['delete']);
        rsort($deleteIdx);
        foreach ($deleteIdx as $idx) {
            if ($idx >= 0 && $idx < count($existing)) {
                array_splice($existing, $idx, 1);
            }
        }

        // Apply updates by index
        foreach ($ops['update'] as $u) {
            $idx = (int)$u['idx'];
            if ($idx >= 0 && $idx < count($existing)) {
                $existing[$idx] = $u['to'];
            }
        }

        // Adds appended
        foreach ($ops['add'] as $e) {
            $existing[] = $e;
        }

        return $existing;
    }
}
