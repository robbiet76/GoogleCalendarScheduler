<?php
declare(strict_types=1);

/**
 * SchedulerAdopt
 *
 * Background identity refresher.
 *
 * PURPOSE:
 * - Assign manifest identities to existing FPP schedule entries
 *   that do not yet have one.
 *
 * GUARANTEES:
 * - Does NOT write to schedule.json
 * - Does NOT create/update/delete scheduler entries
 * - Persists identity ONLY in ManifestStore
 * - Idempotent and safe to run repeatedly
 */
final class SchedulerAdopt
{
    /**
     * Run background adoption.
     *
     * @return array<string,mixed>
     */
    public static function run(): array
    {
        GcsLogger::instance()->info('GCS ADOPT ENTERED');

        // Read schedule.json using the real SchedulerSync contract
        $schedule = SchedulerSync::readScheduleJsonOrThrow(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        // Load manifest using the real ManifestStore contract
        $store = new ManifestStore();
        $manifest = $store->load();

        // Normalize current manifest entries
        $current = $manifest['current'] ?? null;
        $entries = is_array($current['entries'] ?? null)
            ? $current['entries']
            : [];

        // Index existing IDs
        $knownIds = [];
        foreach ($entries as $e) {
            if (isset($e['id']) && is_string($e['id'])) {
                $knownIds[$e['id']] = true;
            }
        }

        $adopted = 0;
        $skipped = 0;

        foreach ($schedule as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Build manifest identity (array-based, no objects)
            $identity = ManifestIdentity::fromScheduleEntry($entry);

            if (empty($identity['ids'])) {
                $skipped++;
                continue;
            }

            $id = ManifestIdentity::primaryId($identity);

            if ($id === '' || isset($knownIds[$id])) {
                $skipped++;
                continue;
            }

            $entries[] = [
                'id' => $id,
                'identity' => $identity,
            ];

            $knownIds[$id] = true;
            $adopted++;
        }

        // Persist ONLY if we actually adopted new identities
        if ($adopted > 0) {
            // Rebuild manifest without altering semantic apply state
            $manifest['current']['entries'] = $entries;
            $store->save($manifest);
        }

        GcsLogger::instance()->info('GCS ADOPT COMPLETE', [
            'adopted' => $adopted,
            'skipped' => $skipped,
        ]);

        return [
            'ok' => true,
            'adopted' => $adopted,
            'skipped' => $skipped,
        ];
    }
}