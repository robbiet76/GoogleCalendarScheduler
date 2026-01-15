<?php
declare(strict_types=1);

/**
 * ManifestStore
 *
 * Persists and restores Google Calendar Scheduler manifest state.
 *
 * Responsibilities:
 * - Load and save manifest.json
 * - Persist semantic scheduler identity snapshots
 * - Maintain current and previous applied states (single-level undo)
 * - Provide atomic rollback support
 *
 * Notes:
 * - Manifest is runtime state, not configuration
 * - Only a single calendar and single undo level are supported
 */
final class ManifestStore
{
    private const SCHEMA_VERSION = 1;

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? '/home/fpp/media/plugins/GoogleCalendarScheduler/runtime/manifest.json';
    }

    /**
     * Load manifest from disk or return an empty initialized structure.
     */
    public function load(): array
    {
        if (!file_exists($this->path)) {
            return $this->emptyManifest();
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return $this->emptyManifest();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->emptyManifest();
        }

        return $this->normalize($data);
    }

    /**
     * Save manifest atomically.
     */
    public function save(array $manifest): void
    {
        $manifest = $this->normalize($manifest);

        $tmp = $this->path . '.tmp';
        file_put_contents(
            $tmp,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        rename($tmp, $this->path);
    }

    /**
     * Promote current -> previous and set new current snapshot.
     */
    public function commitCurrent(array $calendar, array $entries, array $order): void
    {
        // Commit represents an APPLY boundary.
        // Previous snapshot is retained for single-level undo.
        $manifest = $this->load();

        if (!empty($manifest['current'])) {
            $manifest['previous'] = $manifest['current'];
        }

        $manifest['calendar'] = $calendar;
        $manifest['current'] = [
            'appliedAt' => gmdate('c'),
            'entries'   => $entries,
            'order'     => $order,
        ];

        $this->save($manifest);
    }

    /**
     * Roll back to previous snapshot if available.
     * Returns rolled-back snapshot or null if not possible.
     */
    public function rollback(): ?array
    {
        $manifest = $this->load();

        if (empty($manifest['previous'])) {
            return null;
        }

        $manifest['current'] = $manifest['previous'];
        $manifest['previous'] = null;

        $this->save($manifest);
        return $manifest['current'];
    }

    /**
     * Build an empty manifest shell.
     */
    private function emptyManifest(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'calendar'      => null,
            'current'       => [
                'appliedAt' => null,
                'entries'   => [],
                'order'     => [],
            ],
            'previous'      => null,
        ];
    }

    /**
     * Normalize manifest structure and enforce schema version.
     */
    private function normalize(array $manifest): array
    {
        $manifest['schemaVersion'] = self::SCHEMA_VERSION;

        // Manifest is always normalized to a single-calendar, single-snapshot model.

        // calendar
        if (!array_key_exists('calendar', $manifest)) {
            $manifest['calendar'] = null;
        }

        // current snapshot (required)
        if (!isset($manifest['current']) || !is_array($manifest['current'])) {
            $manifest['current'] = [
                'appliedAt' => null,
                'entries'   => [],
                'order'     => [],
            ];
        }

        if (!array_key_exists('appliedAt', $manifest['current'])) {
            $manifest['current']['appliedAt'] = null;
        }
        if (!isset($manifest['current']['entries']) || !is_array($manifest['current']['entries'])) {
            $manifest['current']['entries'] = [];
        }
        if (!isset($manifest['current']['order']) || !is_array($manifest['current']['order'])) {
            $manifest['current']['order'] = [];
        }

        // previous snapshot (optional, but must match shape if present)
        if (!array_key_exists('previous', $manifest)) {
            $manifest['previous'] = null;
        }

        if ($manifest['previous'] !== null) {
            if (!is_array($manifest['previous'])) {
                $manifest['previous'] = null;
            } else {
                if (!array_key_exists('appliedAt', $manifest['previous'])) {
                    $manifest['previous']['appliedAt'] = null;
                }
                if (!isset($manifest['previous']['entries']) || !is_array($manifest['previous']['entries'])) {
                    $manifest['previous']['entries'] = [];
                }
                if (!isset($manifest['previous']['order']) || !is_array($manifest['previous']['order'])) {
                    $manifest['previous']['order'] = [];
                }
            }
        }

        // sanitize entries
        $manifest['current']['entries'] = $this->sanitizeEntries($manifest['current']['entries']);
        if (is_array($manifest['previous'] ?? null)) {
            $manifest['previous']['entries'] = $this->sanitizeEntries($manifest['previous']['entries']);
        }

        return $manifest;
    }

    /**
     * Validate and sanitize manifest entries to keep schema clean.
     *
     * @param array<int,mixed> $entries
     * @return array<int,array<string,mixed>>
     */
    private function sanitizeEntries(array $entries): array
    {
        $out = [];

        foreach ($entries as $e) {
            if (!is_array($e)) {
                continue;
            }

            // Required top-level keys
            foreach (['uid', 'id', 'hash', 'identity', 'payload'] as $k) {
                if (!array_key_exists($k, $e)) {
                    continue 2;
                }
            }

            if (!is_string($e['uid']) || $e['uid'] === '') {
                continue;
            }
            if (!is_string($e['id']) || $e['id'] === '') {
                continue;
            }
            if (!is_string($e['hash']) || $e['hash'] === '') {
                continue;
            }
            if (!is_array($e['payload'])) {
                continue;
            }

            // Identity must contain ids[] and hashes[]
            if (!is_array($e['identity'])) {
                continue;
            }

            // Semantic identity must include canonical fields
            foreach (['type', 'target', 'days', 'startTime', 'endTime', 'startDate', 'endDate'] as $k) {
                if (!array_key_exists($k, $e['identity'])) {
                    continue 2;
                }
            }

            // startDate / endDate must be dual-date token structures
            foreach (['startDate', 'endDate'] as $dk) {
                if (
                    !is_array($e['identity'][$dk]) ||
                    empty($e['identity'][$dk]['tokens']) ||
                    !is_array($e['identity'][$dk]['tokens'])
                ) {
                    continue 2;
                }
            }

            $out[] = $e;
        }

        return $out;
    }
}
