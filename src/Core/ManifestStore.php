<?php
declare(strict_types=1);

/**
 * ManifestStore
 *
 * Persists and restores Google Calendar Scheduler manifest state.
 *
 * Responsibilities:
 * - Load and save manifest.json
 * - Maintain current and previous snapshots
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
            'current'       => null,
            'previous'      => null,
        ];
    }

    /**
     * Normalize manifest structure and enforce schema version.
     */
    private function normalize(array $manifest): array
    {
        $manifest['schemaVersion'] = self::SCHEMA_VERSION;

        foreach (['calendar', 'current', 'previous'] as $key) {
            if (!array_key_exists($key, $manifest)) {
                $manifest[$key] = null;
            }
        }

        return $manifest;
    }
}
