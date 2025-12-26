<?php

/**
 * SchedulerSync
 *
 * Phase 15.3 behavior:
 * - Accept resolved scheduler intents
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
     * Phase 15.3:
     * - CREATE only (append-only)
     * - No updates or deletes
     * - Dry-run safe
     *
     * @param array<int,array<string,mixed>> $intents
     * @return array<string,mixed>
     */
    public function sync(array $intents): array
    {
        $adds = 0;
        $errors = [];

        // Normalize + validate intents into schedule.json entries (without writing yet)
        $entriesToAppend = [];
        foreach ($intents as $idx => $intent) {
            // Log every intent for visibility (still safe)
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

        // If dry-run: report what would be added; do not touch schedule.json
        if ($this->dryRun) {
            $adds = count($entriesToAppend);

            // Helpful dry-run logging: show normalized entries
            foreach ($entriesToAppend as $eIdx => $entry) {
                GcsLogger::instance()->info('Scheduler normalized entry (dry-run)', [
                    'index' => $eIdx,
                    'entry' => $entry,
                ]);
            }

            return [
                'adds'         => $adds,
                'updates'      => 0,
                'deletes'      => 0,
                'dryRun'       => true,
                'intents_seen' => count($intents),
                'errors'       => $errors,
            ];
        }

        // Apply path: if we had validation errors, fail loudly (truth gate)
        if (!empty($errors)) {
            $msg = 'Scheduler apply aborted due to intent validation errors';
            GcsLogger::instance()->error($msg, ['errors' => $errors]);
            throw new RuntimeException($msg . ': ' . implode('; ', $errors));
        }

        // Nothing to append: no-op success
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

        // Append-only (no reordering, no updates)
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

            // Truth gate: fail the apply
            throw new RuntimeException($msg . ': ' . ($verification['message'] ?? 'unknown'));
        }

        $adds = count($entriesToAppend);

        GcsLogger::instance()->info('Scheduler apply completed', [
            'adds' => $adds,
            'backup' => $backupPath,
            'beforeCount' => $beforeCount,
            'afterCount' => count($newSchedule),
            'verification' => $verification,
        ]);

        return [
            'adds'         => $adds,
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
     * NO recurrence logic changes:
     * - If a consolidated intent is provided ({template, range}), we trust it.
     * - Otherwise we fall back to legacy flat fields (best-effort).
     *
     * Returns:
     * - array<string,mixed> schedule entry on success
     * - string error message on failure
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|string
     */
    private function intentToScheduleEntry(array $intent)
    {
        // Consolidated schema: { template: {...}, range: {...} }
        $template = (isset($intent['template']) && is_array($intent['template'])) ? $intent['template'] : [];
        $range    = (isset($intent['range']) && is_array($intent['range'])) ? $intent['range'] : [];

        // Flatten template over intent for legacy key lookup without changing intent meaning.
        $flat = $intent;
        foreach ($template as $k => $v) {
            $flat[$k] = $v;
        }

        // Determine type: playlist or command
        $type = $this->coalesceString($flat, ['type', 'entryType', 'intentType'], '');
        $target = $this->coalesceString($flat, ['target'], '');

        // Legacy explicit fields
        $playlist = $this->coalesceString($flat, ['playlist', 'playlistName', 'name'], '');
        $command  = $this->coalesceString($flat, ['command', 'commandName'], '');

        // Prefer resolved "target" when present
        if ($type === 'playlist' && $playlist === '' && $target !== '') {
            $playlist = $target;
        }
        if ($type === 'command' && $command === '' && $target !== '') {
            $command = $target;
        }

        // If "type" absent, infer from explicit fields (legacy support)
        if ($type === '') {
            if ($playlist !== '') {
                $type = 'playlist';
            } elseif ($command !== '') {
                $type = 'command';
            }
        }

        if ($type !== 'playlist' && $type !== 'command') {
            return 'Unable to determine schedule entry type (expected playlist or command)';
        }

        // Datetimes: consolidated intents provide template.start/end in "Y-m-d H:i:s"
        $dtStart = $this->coalesceString($flat, ['start'], '');
        $dtEnd   = $this->coalesceString($flat, ['end'], '');

        $parsedStart = $this->parseDateTime($dtStart);
        $parsedEnd   = $this->parseDateTime($dtEnd);

        // startTime/endTime: accept explicit HH:MM:SS if present, otherwise derive from datetime
        $startTime = $this->coalesceString($flat, ['startTime', 'start_time'], '');
        if ($startTime === '' && $parsedStart !== null) {
            $startTime = $parsedStart->format('H:i:s');
        }

        $endTime = $this->coalesceString($flat, ['endTime', 'end_time'], '');
        if ($endTime === '' && $parsedEnd !== null) {
            $endTime = $parsedEnd->format('H:i:s');
        }
        if ($endTime === '' && $startTime !== '') {
            $endTime = $startTime;
        }

        // Date range: consolidated range.start/end is authoritative
        $startDate = $this->coalesceString($flat, ['startDate', 'start_date'], '');
        $endDate   = $this->coalesceString($flat, ['endDate', 'end_date'], '');

        $rangeStart = $this->coalesceString($range, ['start'], '');
        $rangeEnd   = $this->coalesceString($range, ['end'], '');

        if ($rangeStart !== '') {
            $startDate = $rangeStart;
        } elseif ($startDate === '' && $parsedStart !== null) {
            $startDate = $parsedStart->format('Y-m-d');
        }

        if ($rangeEnd !== '') {
            $endDate = $rangeEnd;
        } elseif ($endDate === '' && $parsedEnd !== null) {
            $endDate = $parsedEnd->format('Y-m-d');
        }

        if ($startDate === '') {
            $startDate = '0000-01-01';
        }
        if ($endDate === '') {
            $endDate = '0000-12-31';
        }

        // Day mask:
        // - If consolidated range.days (e.g. "SuMoTu") is present, trust it and map using existing helper.
        // - Else allow legacy numeric 'day' fields.
        $day = -1;

        $rangeDaysShort = $this->coalesceString($range, ['days'], '');
        if ($rangeDaysShort !== '') {
            // Uses the same mapping helpers as the consolidator/FPP mapper.
            $day = GcsIntentConsolidator::shortDaysToWeekdayMask($rangeDaysShort);
        } else {
            $day = $this->coalesceInt($flat, ['day', 'dayMask', 'day_mask'], -1);
        }

        if ($day < 0) {
            return 'Missing scheduler day mask (expected range.days short string or numeric day)';
        }

        // Repeat: default 0. If string "none" present, map to 0.
        $repeat = $this->coalesceInt($flat, ['repeat'], -1);
        if ($repeat < 0) {
            $repeatStr = $this->coalesceString($flat, ['repeat'], '');
            $repeat = $this->mapRepeatStringToInt($repeatStr, 0);
        }

        // stopType: default 0 (Graceful). If string "graceful" present, map to 0.
        $stopType = $this->coalesceInt($flat, ['stopType', 'stop_type'], -1);
        if ($stopType < 0) {
            $stopTypeStr = $this->coalesceString($flat, ['stopType', 'stop_type'], '');
            $stopType = $this->mapStopTypeStringToInt($stopTypeStr, 0);
        }

        // Validate time format (basic): HH:MM:SS
        if (!$this->isTimeHms($startTime)) {
            return "Invalid or missing startTime (expected HH:MM:SS): '{$startTime}'";
        }
        if (!$this->isTimeHms($endTime)) {
            return "Invalid endTime (expected HH:MM:SS): '{$endTime}'";
        }

        // Args for commands
        $args = [];
        if (isset($flat['args']) && is_array($flat['args'])) {
            $args = array_values($flat['args']);
        }

        // Multisync flags (optional)
        $multisyncCommand = $this->coalesceBool($flat, ['multisyncCommand', 'multisync_command'], false);

        // Common required fields in schedule.json
        $entry = [
            'enabled'          => 1,
            'sequence'         => 0,
            'day'              => $day,
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
            if ($playlist === '') {
                return 'Playlist schedule entry missing playlist name';
            }
            $entry['playlist'] = $playlist;
            $entry['command']  = '';
        } else {
            if ($command === '') {
                return 'Command schedule entry missing command name';
            }
            $entry['playlist'] = '';
            $entry['command']  = $command;
        }

        return $entry;
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

        // Ensure each element is an array
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

        // Ensure trailing newline (nice + consistent)
        $json .= "\n";

        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException("Failed to write temp schedule file '{$tmp}'");
        }

        // Best effort: preserve permissions from original if exists
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
     * We do not retry writes. We allow brief read retries because projection may lag.
     *
     * @param array<int,array<string,mixed>> $expectedEntries
     * @return array<string,mixed>
     */
    private function verifyEntriesPresent(array $expectedEntries): array
    {
        $attempts = 3;
        $sleepUs = 200000; // 200ms

        for ($i = 1; $i <= $attempts; $i++) {
            $proj = $this->httpGetJson(self::FPP_SCHEDULE_PROJECTION_URL);
            if (!is_array($proj)) {
                // keep trying; final failure below
                GcsLogger::instance()->warn('Scheduler projection read failed', [
                    'attempt' => $i,
                    'url' => self::FPP_SCHEDULE_PROJECTION_URL,
                ]);
            } else {
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
     * Determine which expected schedule.json entries are missing from the projection entries list.
     *
     * @param array<int,array<string,mixed>> $expected
     * @param array<int,array<string,mixed>> $projectionEntries
     * @return array<int,array<string,mixed>> missing expected entries (as small descriptors)
     */
    private function findMissingInProjection(array $expected, array $projectionEntries): array
    {
        $missing = [];

        foreach ($expected as $exp) {
            $expIsPlaylist = isset($exp['playlist']) && is_string($exp['playlist']) && $exp['playlist'] !== '';
            $expPlaylist = $expIsPlaylist ? $exp['playlist'] : '';
            $expCommand  = (!$expIsPlaylist && isset($exp['command']) && is_string($exp['command'])) ? $exp['command'] : '';
            $expDay      = $exp['day'] ?? null;
            $expStartDate= $exp['startDate'] ?? null;
            $expStartTime= $exp['startTime'] ?? null;

            $found = false;

            foreach ($projectionEntries as $p) {
                if (!is_array($p)) {
                    continue;
                }

                // Projection entries have "type": "playlist" or "command"
                $pType = $p['type'] ?? null;

                if ($expIsPlaylist) {
                    if ($pType !== 'playlist') {
                        continue;
                    }
                    if (($p['playlist'] ?? null) !== $expPlaylist) {
                        continue;
                    }
                    if (($p['day'] ?? null) !== $expDay) {
                        continue;
                    }
                    if (($p['startDate'] ?? null) !== $expStartDate) {
                        continue;
                    }
                    if (($p['startTime'] ?? null) !== $expStartTime) {
                        continue;
                    }
                    $found = true;
                    break;
                } else {
                    if ($pType !== 'command') {
                        continue;
                    }
                    if (($p['command'] ?? null) !== $expCommand) {
                        continue;
                    }
                    if (($p['day'] ?? null) !== $expDay) {
                        continue;
                    }
                    if (($p['startDate'] ?? null) !== $expStartDate) {
                        continue;
                    }
                    if (($p['startTime'] ?? null) !== $expStartTime) {
                        continue;
                    }
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $missing[] = [
                    'playlist' => $expPlaylist,
                    'command' => $expCommand,
                    'day' => $expDay,
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
                'timeout' => 2,
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

    /**
     * Parse a datetime string in the canonical "Y-m-d H:i:s" format (or strtotime fallback).
     *
     * @param string $s
     * @return DateTime|null
     */
    private function parseDateTime(string $s): ?DateTime
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
        if ($dt instanceof DateTime) {
            return $dt;
        }

        $ts = strtotime($s);
        if ($ts !== false) {
            return (new DateTime())->setTimestamp($ts);
        }

        return null;
    }

    /**
     * Map known stopType strings to FPP numeric values.
     * Conservative: unknown values fall back to provided default.
     *
     * @param string $s
     * @param int $default
     * @return int
     */
    private function mapStopTypeStringToInt(string $s, int $default): int
    {
        $v = strtolower(trim($s));
        if ($v === '') {
            return $default;
        }

        // Observed in FPP: 0 => Graceful
        if ($v === 'graceful') {
            return 0;
        }

        return $default;
    }

    /**
     * Map known repeat strings to FPP numeric values.
     * Conservative: unknown values fall back to provided default.
     *
     * @param string $s
     * @param int $default
     * @return int
     */
    private function mapRepeatStringToInt(string $s, int $default): int
    {
        $v = strtolower(trim($s));
        if ($v === '') {
            return $default;
        }

        // Intent "none" == no repeat
        if ($v === 'none') {
            return 0;
        }

        return $default;
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

    private function isTimeHms(string $t): bool
    {
        // Basic HH:MM:SS validation
        return (bool)preg_match('/^\d{2}:\d{2}:\d{2}$/', $t);
    }
}

/**
 * Compatibility alias
 *
 * Some legacy code refers to GcsSchedulerSync.
 */
class GcsSchedulerSync extends SchedulerSync {}
