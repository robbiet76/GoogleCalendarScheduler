<?php
declare(strict_types=1);

/**
 * SchedulerExportService
 *
 * Phase 23.2
 *
 * Read-only export orchestration:
 * - Read schedule.json
 * - Select unmanaged entries only
 * - Convert to export intents via ScheduleEntryExportAdapter
 * - Generate ICS via IcsWriter
 *
 * HARD RULES:
 * - Never modifies schedule.json
 * - Never exports managed entries
 * - Invalid entries are skipped with warnings
 * - Best-effort: valid entries still export
 */
final class SchedulerExportService
{
    /**
     * Export unmanaged schedules to ICS.
     *
     * @return array{
     *   ok: bool,
     *   exported: int,
     *   skipped: int,
     *   unmanaged_total: int,
     *   warnings: string[],
     *   errors: string[],
     *   ics: string
     * }
     */
    public static function exportUnmanaged(): array
    {
        $warnings = [];
        $errors = [];

        // Read scheduler entries (read-only)
        try {
            $entries = SchedulerSync::readScheduleJsonStatic(SchedulerSync::SCHEDULE_JSON_PATH);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exported' => 0,
                'skipped' => 0,
                'unmanaged_total' => 0,
                'warnings' => [],
                'errors' => ['Failed to read schedule.json: ' . $e->getMessage()],
                'ics' => '',
            ];
        }

        // Filter unmanaged entries only (ownership determined solely by GCS tag)
        $unmanaged = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;

            if (!GcsSchedulerIdentity::isGcsManaged($entry)) {
                $unmanaged[] = $entry;
            }
        }

        $unmanagedTotal = count($unmanaged);

        // Convert unmanaged entries -> export intents
        $exportEvents = [];
        $skipped = 0;

        foreach ($unmanaged as $entry) {
            $intent = ScheduleEntryExportAdapter::adapt($entry, $warnings);
            if ($intent === null) {
                $skipped++;
                continue;
            }
            $exportEvents[] = $intent;
        }

        $exported = count($exportEvents);

        // Build ICS (even if empty; UI can message appropriately)
        $ics = '';
        try {
            $ics = IcsWriter::build($exportEvents);
        } catch (Throwable $e) {
            $errors[] = 'Failed to generate ICS: ' . $e->getMessage();
            $ics = '';
        }

        return [
            'ok' => empty($errors),
            'exported' => $exported,
            'skipped' => $skipped,
            'unmanaged_total' => $unmanagedTotal,
            'warnings' => $warnings,
            'errors' => $errors,
            'ics' => $ics,
        ];
    }
}
