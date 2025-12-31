<?php
declare(strict_types=1);

/**
 * ExportService
 *
 * Orchestrates read-only export of unmanaged FPP scheduler entries to ICS.
 *
 * Responsibilities:
 * - Read scheduler.json (read-only)
 * - Select unmanaged scheduler entries only
 * - Convert scheduler entries into export intents
 * - Generate an ICS representation of those intents
 *
 * Guarantees:
 * - Never mutates scheduler.json
 * - Never exports GCS-managed entries
 * - Best-effort processing: invalid entries are skipped with warnings
 *
 * This service performs no scheduling logic and is intended strictly for
 * export and interoperability use cases.
 */
final class ExportService
{
    /**
     * Export unmanaged scheduler entries to an ICS document.
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
            $entries = SchedulerSync::readScheduleJsonStatic(
                SchedulerSync::SCHEDULE_JSON_PATH
            );
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

        // Select unmanaged scheduler entries only (ownership determined by GCS identity tag)
        $unmanaged = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!SchedulerIdentity::isGcsManaged($entry)) {
                $unmanaged[] = $entry;
            }
        }

        $unmanagedTotal = count($unmanaged);

        // Convert unmanaged scheduler entries into export intents
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

        // Generate ICS output (may be empty; caller can handle messaging)
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
