<?php
declare(strict_types=1);

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
 * - Add-only (no updates/deletes/reordering) [for now]
 * - No scheduler logic/recurrence changes: we use whatever the intent already provides.
 *
 * Phase 17.1:
 * - Preserve canonical GCS identity tag, but store it in args[] (NOT in playlist name)
 *   so the FPP UI can still resolve and display the real playlist/sequence name.
 * - Tag format (unchanged): |GCS:v1|uid=<uid>|range=<start..end>|days=<shortdays>
 */
class SchedulerSync
{
    private bool $dryRun;

    /**
     * FPP scheduler persistence file (canonical).
     */
    public const SCHEDULE_JSON_PATH = '/home/fpp/media/config/schedule.json';

    /**
     * FPP projection endpoint (read-only) used for verification.
     */
    private const FPP_SCHEDULE_PROJECTION_URL = 'http://127.0.0.1/api/fppd/schedule';

    public function __construct(bool $dryRun = true)
    {
        $this->dryRun = (bool)$dryRun;
    }

    /**
     * PURE helper: read schedule.json (static).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function readScheduleJsonStatic(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $rawTrim = trim($raw);
        if ($rawTrim === '') {
            return [];
        }

        $decoded = json_decode($rawTrim, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * PURE helper: map intents -> schedule.json entries (static).
     *
     * @param array<int,array<string,mixed>> $intents
     * @return array{entries: array<int,array<string,mixed>>, errors: array<int,string>}
     */
    public static function mapIntentsToScheduleEntries(array $intents): array
    {
        $entries = [];
        $errors = [];

        foreach ($intents as $idx => $intent) {
            if (!is_array($intent)) {
                $errors[] = "Intent #{$idx} is not an array";
                continue;
            }

            $entryOrError = self::intentToScheduleEntryStatic($intent);
            if (is_string($entryOrError)) {
                $errors[] = "Intent #{$idx}: {$entryOrError}";
                continue;
            }

            $entries[] = $entryOrError;
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    /**
     * Sync resolved intents against the scheduler (write-capable if dryRun=false).
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

            $entryOrError = self::intentToScheduleEntryStatic($intent);
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
     * PURE mapping: intent -> schedule entry.
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|string
     */
    private static function intentToScheduleEntryStatic(array $intent)
    {
        $tpl = $intent;
        $range = null;

        if (isset($intent['template']) && is_array($intent['template'])) {
            $tpl = $intent['template'];
        }
        if (isset($intent['range']) && is_array($intent['range'])) {
            $range = $intent['range'];
        }

        $type   = self::coalesceString($tpl, ['type', 'entryType', 'intentType'], '');
        $target = self::coalesceString($tpl, ['target'], '');

        if ($type !== 'playlist' && $type !== 'command') {
            return "Unable to determine schedule entry type (expected playlist or command); got '{$type}'";
        }
        if ($target === '') {
            return 'Missing target for intent (expected playlist name or command name)';
        }

        // UID may live on outer intent even when template/range is used
        $uid = self::coalesceString($intent, ['uid'], '');
        if ($uid === '') {
            $uid = self::coalesceString($tpl, ['uid'], '');
        }

        $startRaw = self::coalesceString($tpl, ['start'], '');
        $endRaw   = self::coalesceString($tpl, ['end'], '');

        $startDt = self::parseYmdHms($startRaw);
        $endDt   = self::parseYmdHms($endRaw);

        if (!$startDt) {
            return "Invalid or missing template start (expected Y-m-d H:i:s): '{$startRaw}'";
        }
        if (!$endDt) {
            return "Invalid or missing template end (expected Y-m-d H:i:s): '{$endRaw}'";
        }

        $startTime = $startDt->format('H:i:s');
        $endTime   = $endDt->format('H:i:s');

        $startDate = null;
        $endDate   = null;
        $dayMask   = null;
        $shortDays = '';

        if (is_array($range)) {
            $rStart = isset($range['start']) ? (string)$range['start'] : '';
            $rEnd   = isset($range['end']) ? (string)$range['end'] : '';
            $rDays  = isset($range['days']) ? (string)$range['days'] : '';

            if (self::isDateYmd($rStart)) $startDate = $rStart;
            if (self::isDateYmd($rEnd))   $endDate = $rEnd;

            if ($rDays !== '') {
                $shortDays = $rDays;
                $dayMask = (int)GcsIntentConsolidator::shortDaysToWeekdayMask($rDays);
            }
        }

        if ($startDate === null) {
            $startDate = $startDt->format('Y-m-d');
        }
        if ($endDate === null) {
            $endDate = $startDate;
        }

        if ($dayMask === null || $dayMask === 0) {
            $dow = (int)$startDt->format('w'); // 0=Sun..6=Sat
            $dayMask = (1 << $dow);
        }

        if ($shortDays === '') {
            // Convert mask to short-days string for tag parity with legacy mapper
            $shortDays = (string)GcsIntentConsolidator::weekdayMaskToShortDays((int)$dayMask);
        }

        // Canonical legacy identity tag (now stored in args[] for UI compatibility)
        $tag = self::buildGcsV1Tag($uid, $startDate, $endDate, $shortDays);

        $stopType = self::coalesceInt($tpl, ['stopType', 'stop_type'], 0);
        $repeat   = self::coalesceInt($tpl, ['repeat'], 0);

        $args = [];
        if (isset($tpl['args']) && is_array($tpl['args'])) {
            $args = array_values($tpl['args']);
        }

        // Add tag to args exactly once (avoid duplicates if caller provided it)
        if ($tag !== '' && !self::argsContainsGcsV1Tag($args)) {
            $args[] = $tag;
        }

        $multisyncCommand = self::coalesceBool($tpl, ['multisyncCommand', 'multisync_command'], false);

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
            // Keep playlist name clean so FPP UI can resolve it
            $entry['playlist'] = $target;
        } else {
            // Command entries keep command name; playlist remains empty
            $entry['command']  = $target;
            $entry['playlist'] = '';
        }

        return $entry;
    }

    /**
     * Build canonical v1 GCS identity tag exactly like legacy mapper.
     */
    private static function buildGcsV1Tag(string $uid, string $startDate, string $endDate, string $days): string
    {
        if ($uid === '') {
            // No UID => no tag (keeps behavior safe; identity system can ignore)
            return '';
        }
        $range = $startDate . '..' . $endDate;
        return '|GCS:v1|uid=' . $uid . '|range=' . $range . '|days=' . $days;
    }

    /**
     * True if args already contains a GCS v1 tag.
     *
     * @param array<int,mixed> $args
     */
    private static function argsContainsGcsV1Tag(array $args): bool
    {
        foreach ($args as $a) {
            if (!is_string($a)) continue;
            if (strpos($a, '|GCS:v1|') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function parseYmdHms(string $s): ?DateTime
    {
        if ($s === '') return null;
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
        return ($dt instanceof DateTime) ? $dt : null;
    }

    private static function isDateYmd(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
        $dt = DateTime::createFromFormat('Y-m-d', $s);
        return ($dt instanceof DateTime) && ($dt->format('Y-m-d') === $s);
    }

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

    private function backupScheduleFileOrThrow(string $path): string
    {
        $ts = date('Ymd-His');
        $backup = $path . '.bak-' . $ts;

        if (!@copy($path, $backup)) {
            throw new RuntimeException("Failed to create backup '{$backup}' from '{$path}'");
        }

        return $backup;
    }

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

    private function verifyEntriesPresent(array $expectedEntries): array
    {
        $attempts = 10;
        $sleepUs = 500000;        // 500ms between attempts
        $initialSleepUs = 750000; // allow scheduler to settle after schedule.json write

        usleep($initialSleepUs);

        for ($i = 1; $i <= $attempts; $i++) {
            $proj = $this->httpGetJson(self::FPP_SCHEDULE_PROJECTION_URL);
            if (is_array($proj)) {
                $entries = $proj['schedule']['entries'] ?? null;
                if (is_array($entries)) {
                    $missing = $this->findMissingInProjection($expectedEntries, $entries);
                    if (count($missing) === 0) {
                        return ['ok' => true, 'attempts' => $i, 'missing' => []];
                    }
                    GcsLogger::instance()->warn('Scheduler projection missing expected entries', [
                        'attempt' => $i,
                        'missing' => $missing,
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

    private function findMissingInProjection(array $expected, array $projectionEntries): array
    {
        $missing = [];

        foreach ($expected as $exp) {
            $expPlaylist = (string)($exp['playlist'] ?? '');
            $expCommand  = (string)($exp['command'] ?? '');
            $expStartDate = $exp['startDate'] ?? null;
            $expStartTime = $exp['startTime'] ?? null;

            $expStartTimeNorm = self::normalizeTimeToHm(is_string($expStartTime) ? $expStartTime : null);

            $found = false;

            foreach ($projectionEntries as $p) {
                if (!is_array($p)) continue;

                $pTypeRaw = $p['type'] ?? null;
                $pType = is_string($pTypeRaw) ? strtolower($pTypeRaw) : '';
                if ($pType === '') {
                    if (!empty($p['playlist'])) $pType = 'playlist';
                    if (!empty($p['command']))  $pType = 'command';
                }

                if ($expPlaylist !== '') {
                    if ($pType !== 'playlist') continue;
                    if ((string)($p['playlist'] ?? '') !== $expPlaylist) continue;
                } else {
                    if ($pType !== 'command') continue;
                    if ((string)($p['command'] ?? '') !== $expCommand) continue;
                }

                if (($p['startDate'] ?? null) !== $expStartDate) continue;

                $pStartTimeNorm = self::normalizeTimeToHm(is_string($p['startTime'] ?? null) ? (string)$p['startTime'] : null);
                if ($expStartTimeNorm !== null && $pStartTimeNorm !== null) {
                    if ($pStartTimeNorm !== $expStartTimeNorm) continue;
                } else {
                    if (($p['startTime'] ?? null) !== $expStartTime) continue;
                }

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

    private static function normalizeTimeToHm(?string $t): ?string
    {
        if ($t === null) return null;
        $tt = trim($t);
        if ($tt === '') return null;

        if (preg_match('/^\d{2}:\d{2}/', $tt) === 1) {
            return substr($tt, 0, 5);
        }

        return null;
    }

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

    private static function coalesceString(array $src, array $keys, string $default): string
    {
        foreach ($keys as $k) {
            if (isset($src[$k]) && is_string($src[$k]) && $src[$k] !== '') {
                return $src[$k];
            }
        }
        return $default;
    }

    private static function coalesceInt(array $src, array $keys, int $default): int
    {
        foreach ($keys as $k) {
            if (isset($src[$k]) && (is_int($src[$k]) || (is_string($src[$k]) && ctype_digit($src[$k])))) {
                return (int)$src[$k];
            }
        }
        return $default;
    }

    private static function coalesceBool(array $src, array $keys, bool $default): bool
    {
        foreach ($keys as $k) {
            if (!isset($src[$k])) continue;

            $v = $src[$k];
            if (is_bool($v)) return $v;
            if (is_int($v))  return $v !== 0;

            if (is_string($v)) {
                $vv = strtolower(trim($v));
                if (in_array($vv, ['true','1','yes','on'], true)) return true;
                if (in_array($vv, ['false','0','no','off'], true)) return false;
            }
        }
        return $default;
    }
}

/**
 * Compatibility alias
 */
class GcsSchedulerSync extends SchedulerSync {}
