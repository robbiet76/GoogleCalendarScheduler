<?php
declare(strict_types=1);

/**
 * SchedulerAdopt
 *
 * Background identity refresher.
 *
 * PURPOSE:
 * - Assign manifest identities to existing FPP schedule entries that do not yet have one.
 * - Persist a semantic snapshot per adopted entry (uid/id/hash/identity/payload) so we can diff and undo.
 *
 * GUARANTEES:
 * - Does NOT write to schedule.json
 * - Does NOT create/update/delete scheduler entries
 * - Persists identity/snapshots ONLY in ManifestStore
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

        // Load manifest
        $store = new ManifestStore();
        $manifest = $store->load();

        // Normalize current manifest entries
        $current = $manifest['current'] ?? [];
        $entries = is_array($current['entries'] ?? null)
            ? $current['entries']
            : [];

        // Index existing IDs + UIDs
        $knownIds = [];
        $knownUids = [];
        foreach ($entries as $e) {
            if (is_array($e)) {
                if (isset($e['id']) && is_string($e['id']) && $e['id'] !== '') {
                    $knownIds[$e['id']] = true;
                }
                if (isset($e['uid']) && is_string($e['uid']) && $e['uid'] !== '') {
                    $knownUids[$e['uid']] = true;
                }
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

            if (empty($identity['ids']) || empty($identity['hashes'])) {
                $skipped++;
                continue;
            }

            $id = ManifestIdentity::primaryId($identity);
            $hash = ManifestIdentity::primaryHash($identity);

            if ($id === '') {
                $skipped++;
                continue;
            }

            // Stable UID: use existing if present, else derive deterministically
            $uid = '';
            if (isset($entry['uid']) && is_string($entry['uid']) && $entry['uid'] !== '') {
                $uid = $entry['uid'];
            } else {
                $uid = 'gcs-manifest-' . $id . '@local';
            }

            if (isset($knownIds[$id]) || isset($knownUids[$uid])) {
                $skipped++;
                continue;
            }

            $entries[] = [
                'uid'      => $uid,
                'id'       => $id,
                'hash'     => $hash,
                'identity' => $identity,
                'payload'  => $entry,
            ];

            $knownIds[$id] = true;
            $knownUids[$uid] = true;
            $adopted++;
        }

        // Persist ONLY if we actually adopted new identities
        if ($adopted > 0) {
            if (!isset($manifest['current']) || !is_array($manifest['current'])) {
                $manifest['current'] = [
                    'appliedAt' => null,
                    'entries'   => [],
                    'order'     => [],
                ];
            }

            $manifest['current']['entries'] = $entries;
            $store->save($manifest);
        }

        GcsLogger::instance()->info('GCS ADOPT COMPLETE', [
            'adopted' => $adopted,
            'skipped' => $skipped,
        ]);

        return [
            'ok'      => true,
            'adopted' => $adopted,
            'skipped' => $skipped,
        ];
    }
}