<?php

/**
 * SchedulerSync
 *
 * Phase 16.2 behavior:
 * - Accept resolved scheduler intents (including consolidated template+range form)
 * - DRY-RUN: report what would be created/updated/deleted (no writes)
 * - APPLY: append new entries to FPP scheduler persistence file schedule.json
 *          with backup + atomic write + read-after-write verification.
 *
 * IMPORTANT CONSTRAINTS:
 * - Add-only (no updates/deletes/reordering)
 * - No scheduler logic/recurrence changes: we use whatever the intent already provides.
 * - No breaking config changes.
 */
class SchedulerSync
{
    private bool $dryRun;

    /**
     * FPP scheduler persistence file (canonical).
     * NOTE: This is the authoritative scheduler storage on FPP.
     */
    private const SCHEDULE_JSON_PATH = '/home/fpp/media/config/schedule.json';

    /**
     * FPP projection endpoint (read-only) used for verification.
     */
    private const FPP_SCHEDULE_PROJECTION_URL = 'http://127.0.0.1/api/fppd/schedule';

    /**
     * @param bool $dryRun
     */
    public function __construct(bool $dryRun = true)
    {
        $this->dryRun = (bool)$dryRun;
    }

    /**
     * Sync resolved intents against the scheduler.
     *
     * Phase 16.2:
     * - CREATE only (append-only)
     * - No updates or deletes
     * - Dry-run safe
     *
     * @param array<int,array<string,mixed>> $intents
     * @return array<string,mixed>
     */
    public function sync(array $intents): array
    {
        $errors = [];
        $entriesToAppend = [];

        foreach ($intents as $idx => $intent) {
            GcsLogger::instance()->info(
                $this->dryRun ? 'Scheduler intent (dry-run)' : 'Scheduler intent',
                is_array($intent) ? $intent : ['intent' => $intent]
            );

            if (!is_array($intent)) {
                $errors[] = "Intent #{$idx} is not an array";
                continue;
            }

            $entryOrError = $this->intentToScheduleEntry($intent);
            if (is_string($entryOrError)) {
                $errors[] = "Intent #{$idx}: {$entryOrError}";
                continue;
            }

            $entriesToAppend[] = $entryOrError;
        }

        // Dry-run path: never throw; just report
        if ($this->dryRun) {
            foreach ($entriesToAppend as $eIdx => $entry) {
                GcsLogger::instance()->info('Scheduler normalized entry (dry-run)', [
                    'index' => $eIdx,
                    'entry' => $entry,
                ]);
            }

            return [
                'adds'         => count($entriesToAppend),
                'updates'      => 0,
                'deletes'      => 0,
                'dryRun'       => true,
                'intents_seen' => count($intents),
                'errors'       => $errors,
            ];
        }

        // Apply path: validation must pass (truth gate)
        if (!empty($errors)) {
            $msg = 'Scheduler apply aborted due to intent validation errors';
            GcsLogger::instance()->error($msg, ['errors' => $errors]);
            throw new RuntimeException($msg . ': ' . implode('; ', $errors));
        }

        if (count($entriesToAppend) === 0) {
            return [
                'adds'         => 0,
                'updates'      => 0,
                'deletes'      => 0,
                'dryRun'       => false,
                'intents_seen' => count($intents),
                'errors'       => [],
            ];
        }

        // Load existing schedule.json
        $existing = $this->readScheduleJsonOrThrow(self::SCHEDULE_JSON_PATH);
        $beforeCount = count($existing);

        // Append-only
        $newSchedule = array_merge($existing, $entriesToAppend);

        // Backup + atomic write
        $backupPath = $this->backupScheduleFileOrThrow(self::SCHEDULE_JSON_PATH);
        $this->writeScheduleJsonAtomicallyOrThrow(self::SCHEDULE_JSON_PATH, $newSchedule);

        // Verify read-after-write via projection API
        $verification = $this->verifyEntriesPresent($entriesToAppend);

        if (!$verification['ok']) {
            $msg = 'Scheduler apply verification failed (schedule.json written but projection did not reflect expected entries)';
            GcsLogger::instance()->error($msg, [
                'backup' => $backupPath,
                'beforeCount' => $beforeCount,
                'afterCount' => count($newSchedule),
                'verification' => $verification,
            ]);

            throw new RuntimeException($msg . ': ' . ($verification['message'] ?? 'unknown'));
        }

        GcsLogger::instance()->info('Scheduler apply completed', [
            'adds' => count($entriesToAppend),
            'backup' => $backupPath,
            'beforeCount' => $beforeCount,
            'afterCount' => count($newSchedule),
            'verification' => $verification,
        ]);

        return [
            'adds'         => count($entriesToAppend),
            'updates'      => 0,
            'deletes'      => 0,
            'dryRun'       => false,
            'intents_seen' => count($intents),
            'errors'       => [],
        ];
    }

    /**
     * Convert an intent array to a schedule.json entry array.
     *
     * Supports both schemas:
     *  A) Flat intent (legacy):
     *     ['type','target','start','end',...]
     *  B) Consolidated intent (current):
     *     ['template'=>{...flat...}, 'range'=>{'start','end','days'}]
     *
     * NO recurrence logic changes:
     * - We only map fields already computed upstream.
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|string
     */
    private function intentToScheduleEntry(array $intent)
    {
        $tpl = $intent;
        $range = null;

        if (isset($intent['template']) && is_array($intent['template'])) {
            $tpl = $intent['template'];
        }
        if (isset($intent['range']) && is_array($intent['range'])) {
            $range = $intent['range'];
        }

        // Determine type + target
        $type   = $this->coalesceString($tpl, ['type', 'entryType', 'intentType'], '');
        $target = $this->coalesceString($tpl, ['target'], '');

        if ($type !== 'playlist' && $type !== 'command') {
            return "Unable to determine schedule entry type (expected playlist or command); got '{$type}'";
        }
        if ($target === '') {
            return 'Missing target for intent (expected playlist name or command name)';
        }

        // Times: derived from template start/end (already normalized upstream)
        $startRaw = $this->coalesceString($tpl, ['start'], '');
        $endRaw   = $this->coalesceString($tpl, ['end'], '');

        $startDt = $this->parseYmdHms($startRaw);
        $endDt   = $this->parseYmdHms($endRaw);

        if (!$startDt) {
            return "Invalid or missing template start (expected Y-m-d H:i:s): '{$startRaw}'";
        }
        if (!$endDt) {
            return "Invalid or missing template end (expected Y-m-d H:i:s): '{$endRaw}'";
        }

        $startTime = $startDt->format('H:i:s');
        $endTime   = $endDt->format('H:i:s');

        // Dates/day mask: prefer consolidated range (authoritative for multi-day)
        $startDate = null;
        $endDate   = null;
        $dayMask   = null;

        if (is_array($range)) {
            $rStart = isset($range['start']) ? (string)$range['start'] : '';
            $rEnd   = isset($range['end']) ? (string)$range['end'] : '';
            $rDays  = isset($range['days']) ? (string)$range['days'] : '';

            if ($this->isDateYmd($rStart)) {
                $startDate = $rStart;
            }
            if ($this->isDateYmd($rEnd)) {
                $endDate = $rEnd;
            }

            // "SuMoTuWeThFrSa" subset string -> weekday bitmask (existing helper)
            if ($rDays !== '') {
                $dayMask = (int)GcsIntentConsolidator::shortDaysToWeekdayMask($rDays);
            }
        }

        // Fall back to single-occurrence date if no range given
        if ($startDate === null) {
            $startDate = $startDt->format('Y-m-d');
        }
        if ($endDate === null) {
            $endDate = $startDate;
        }

        // Fall back day-of-week mask derived from startDt if no mask
        if ($dayMask === null || $dayMask === 0) {
            $dow = (int)$startDt->format('w'); // 0=Sun..6=Sat
            $dayMask = (1 << $dow);
        }

        // stopType: default 0 (Graceful)
        $stopType = $this->coalesceInt($tpl, ['stopType', 'stop_type'], 0);

        // repeat: default 0 (no repeat) — upstream recurrence expansion handles repeats separately
        $repeat = $this->coalesceInt($tpl, ['repeat'], 0);

        // Args for commands (optional)
        $args = [];
        if (isset($tpl['args']) && is_array($tpl['args'])) {
            $args = array_values($tpl['args']);
        }

        // Multisync flags (optional)
        $multisyncCommand = $this->coalesceBool($tpl, ['multisyncCommand', 'multisync_command'], false);

        $entry = [
            'enabled'          => 1,
            'sequence'         => 0,
            'day'              => $dayMask,
            'startTime'        => $startTime,
            'startTimeOffset'  => 0,
            'endTime'          => $endTime,
            'endTimeOffset'    => 0,
            'repeat'           => $repeat,
            'startDate'        => $startDate,
            'endDate'          => $endDate,
            'stopType'         => $stopType,
            'playlist'         => '',
            'command'          => '',
            'args'             => $args,
            'multisyncCommand' => $multisyncCommand,
        ];

        if ($type === 'playlist') {
            $entry['playlist'] = $target;
        } else {
            $entry['command'] = $target;
        }

        return $entry;
    }

    private function parseYmdHms(string $s): ?DateTime
    {
        if ($s === '') {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
        return ($dt instanceof DateTime) ? $dt : null;
    }

    private function isDateYmd(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $s);
        return ($dt instanceof DateTime) && ($dt->format('Y-m-d') === $s);
    }

    /**
     * Read and decode schedule.json, returning an array of entries.
     *
     * @param string $path
     * @return array<int,array<string,mixed>>
     */
    private function readScheduleJsonOrThrow(string $path): array
    {
        if (!file_exists($path)) {
            throw new RuntimeException("schedule.json not found at '{$path}'");
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Unable to read schedule.json at '{$path}'");
        }

        $rawTrim = trim($raw);
        if ($rawTrim === '') {
            return [];
        }

        $decoded = json_decode($rawTrim, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("schedule.json is not valid JSON array at '{$path}'");
        }

        foreach ($decoded as $i => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException("schedule.json entry #{$i} is not an object/array");
            }
        }

        return $decoded;
    }

    /**
     * Create a timestamped backup of schedule.json.
     *
     * @param string $path
     * @return string backup path
     */
    private function backupScheduleFileOrThrow(string $path): string
    {
        $ts = date('Ymd-His');
        $backup = $path . '.bak-' . $ts;

        if (!@copy($path, $backup)) {
            throw new RuntimeException("Failed to create backup '{$backup}' from '{$path}'");
        }

        return $backup;
    }

    /**
     * Atomically write schedule.json (temp file + rename).
     *
     * @param string $path
     * @param array<int,array<string,mixed>> $data
     * @return void
     */
    private function writeScheduleJsonAtomicallyOrThrow(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new RuntimeException("schedule.json directory not found: '{$dir}'");
        }

        $tmp = $path . '.tmp-' . getmypid();

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Failed to encode schedule.json');
        }

        $json .= "\n";

        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException("Failed to write temp schedule file '{$tmp}'");
        }

        if (file_exists($path)) {
            @chmod($tmp, fileperms($path) & 0777);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to replace schedule.json atomically at '{$path}'");
        }
    }

    /**
     * Verify that entries appear in the projection endpoint /api/fppd/schedule.
     *
     * Projection can lag behind schedule.json writes, so we retry reads.
     *
     * @param array<int,array<string,mixed>> $expectedEntries
     * @return array<string,mixed>
     */
    private function verifyEntriesPresent(array $expectedEntries): array
    {
        $attempts = 10;
        $sleepUs = 500000; // 500ms (total ~5s)

        for ($i = 1; $i <= $attempts; $i++) {
            $proj = $this->httpGetJson(self::FPP_SCHEDULE_PROJECTION_URL);
            if (is_array($proj)) {
                $entries = $proj['schedule']['entries'] ?? null;
                if (is_array($entries)) {
                    $missing = $this->findMissingInProjection($expectedEntries, $entries);
                    if (count($missing) === 0) {
                        return [
                            'ok' => true,
                            'attempts' => $i,
                            'missing' => [],
                        ];
                    }
                    GcsLogger::instance()->warn('Scheduler projection missing expected entries', [
                        'attempt' => $i,
                        'missing' => $missing,
                    ]);
                } else {
                    GcsLogger::instance()->warn('Scheduler projection did not contain schedule.entries', [
                        'attempt' => $i,
                        'keys' => array_keys($proj),
                    ]);
                }
            } else {
                GcsLogger::instance()->warn('Scheduler projection read failed', [
                    'attempt' => $i,
                    'url' => self::FPP_SCHEDULE_PROJECTION_URL,
                ]);
            }

            if ($i < $attempts) {
                usleep($sleepUs);
            }
        }

        return [
            'ok' => false,
            'attempts' => $attempts,
            'message' => 'Expected entries not found in projection after retries',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $expected
     * @param array<int,array<string,mixed>> $projectionEntries
     * @return array<int,array<string,mixed>>
     */
    private function findMissingInProjection(array $expected, array $projectionEntries): array
    {
        $missing = [];

        foreach ($expected as $exp) {
            $expPlaylist = $exp['playlist'] ?? '';
            $expCommand  = $exp['command'] ?? '';
            $expStartDate = $exp['startDate'] ?? null;
            $expStartTime = $exp['startTime'] ?? null;

            $found = false;

            foreach ($projectionEntries as $p) {
                if (!is_array($p)) {
                    continue;
                }

                if ($expPlaylist !== '') {
                    if (($p['type'] ?? null) !== 'playlist') continue;
                    if (($p['playlist'] ?? null) !== $expPlaylist) continue;
                } else {
                    if (($p['type'] ?? null) !== 'command') continue;
                    if (($p['command'] ?? null) !== $expCommand) continue;
                }

                if (($p['startDate'] ?? null) !== $expStartDate) continue;
                if (($p['startTime'] ?? null) !== $expStartTime) continue;

                $found = true;
                break;
            }

            if (!$found) {
                $missing[] = [
                    'playlist'  => $expPlaylist,
                    'command'   => $expCommand,
                    'startDate' => $expStartDate,
                    'startTime' => $expStartTime,
                ];
            }
        }

        return $missing;
    }


    /**
     * GET a URL and decode JSON response.
     *
     * @param string $url
     * @return array<string,mixed>|null
     */
    private function httpGetJson(string $url): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 3,
                'header'  => "Accept: application/json\r\n",
            ]
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function coalesceString(array $src, array $keys, string $default): string
    {
        foreach ($keys as $k) {
            if (isset($src[$k]) && is_string($src[$k]) && $src[$k] !== '') {
                return $src[$k];
            }
        }
        return $default;
    }

    private function coalesceInt(array $src, array $keys, int $default): int
    {
        foreach ($keys as $k) {
            if (isset($src[$k]) && (is_int($src[$k]) || (is_string($src[$k]) && ctype_digit($src[$k])))) {
                return (int)$src[$k];
            }
        }
        return $default;
    }

    private function coalesceBool(array $src, array $keys, bool $default): bool
    {
        foreach ($keys as $k) {
            if (!isset($src[$k])) {
                continue;
            }
            $v = $src[$k];
            if (is_bool($v)) {
                return $v;
            }
            if (is_int($v)) {
                return $v !== 0;
            }
            if (is_string($v)) {
                $vv = strtolower(trim($v));
                if ($vv === 'true' || $vv === '1' || $vv === 'yes' || $vv === 'on') {
                    return true;
                }
                if ($vv === 'false' || $vv === '0' || $vv === 'no' || $vv === 'off') {
                    return false;
                }
            }
        }
        return $default;
    }
}

/**
 * Compatibility alias
 */
class GcsSchedulerSync extends SchedulerSync {}
