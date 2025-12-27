<?php
declare(strict_types=1);

/**
 * SchedulerSync (Phase 17+)
 *
 * New contract (Option B):
 * - No dryRun flag
 * - No diff computation
 * - No apply policy
 *
 * Responsibilities now:
 * - schedule.json I/O helpers
 * - canonical intent -> schedule entry mapping
 */
final class SchedulerSync
{
    public const SCHEDULE_JSON_PATH = '/home/fpp/media/config/schedule.json';

    /**
     * PURE helper: read schedule.json.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function readScheduleJsonStatic(string $path): array
    {
        if (!file_exists($path)) return [];

        $raw = @file_get_contents($path);
        if ($raw === false) return [];

        $rawTrim = trim($raw);
        if ($rawTrim === '') return [];

        $decoded = json_decode($rawTrim, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) $out[] = $entry;
        }
        return $out;
    }

    /**
     * STRICT helper: read schedule.json or throw.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function readScheduleJsonOrThrow(string $path): array
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

    public static function backupScheduleFileOrThrow(string $path): string
    {
        $ts = date('Ymd-His');
        $backup = $path . '.bak-' . $ts;

        if (!@copy($path, $backup)) {
            throw new RuntimeException("Failed to create backup '{$backup}' from '{$path}'");
        }

        return $backup;
    }

    /**
     * @param array<int,array<string,mixed>> $data
     */
    public static function writeScheduleJsonAtomicallyOrThrow(string $path, array $data): void
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
     * Ensure schedule.json matches expected managed-key set after apply.
     *
     * @param array<int,string> $expectedManagedKeys
     * @param array<int,string> $expectedDeletedKeys
     */
    public static function verifyScheduleJsonKeysOrThrow(array $expectedManagedKeys, array $expectedDeletedKeys): void
    {
        $after = self::readScheduleJsonStatic(self::SCHEDULE_JSON_PATH);

        $present = [];
        foreach ($after as $e) {
            if (!is_array($e)) continue;
            $k = GcsSchedulerIdentity::extractKey($e);
            if ($k !== null) $present[$k] = true;
        }

        foreach ($expectedManagedKeys as $k) {
            if (!isset($present[$k])) {
                throw new RuntimeException("Post-write verification failed: expected managed key missing in schedule.json: {$k}");
            }
        }

        foreach ($expectedDeletedKeys as $k) {
            if (isset($present[$k])) {
                throw new RuntimeException("Post-write verification failed: expected deleted key still present in schedule.json: {$k}");
            }
        }
    }

    /**
     * Public wrapper for canonical intent -> schedule entry mapping.
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|string
     */
    public static function intentToScheduleEntryPublic(array $intent)
    {
        return self::intentToScheduleEntryStatic($intent);
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

        if ($startDate === null) $startDate = $startDt->format('Y-m-d');
        if ($endDate === null)   $endDate = $startDate;

        if ($dayMask === null || $dayMask === 0) {
            $dow = (int)$startDt->format('w'); // 0=Sun..6=Sat
            $dayMask = (1 << $dow);
        }

        if ($shortDays === '') {
            $shortDays = (string)GcsIntentConsolidator::weekdayMaskToShortDays((int)$dayMask);
        }

        // Canonical identity tag stored in args[]
        $tag = self::buildGcsV1Tag($uid, $startDate, $endDate, $shortDays);

        $stopType = self::coalesceInt($tpl, ['stopType', 'stop_type'], 0);
        $repeat   = self::coalesceInt($tpl, ['repeat'], 0);

        $args = [];
        if (isset($tpl['args']) && is_array($tpl['args'])) {
            $args = array_values($tpl['args']);
        }

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
            $entry['playlist'] = $target;
        } else {
            $entry['command']  = $target;
            $entry['playlist'] = '';
        }

        return $entry;
    }

    private static function buildGcsV1Tag(string $uid, string $startDate, string $endDate, string $days): string
    {
        if ($uid === '') return '';
        $range = $startDate . '..' . $endDate;
        return '|GCS:v1|uid=' . $uid . '|range=' . $range . '|days=' . $days;
    }

    /**
     * @param array<int,mixed> $args
     */
    private static function argsContainsGcsV1Tag(array $args): bool
    {
        foreach ($args as $a) {
            if (!is_string($a)) continue;
            if (strpos($a, '|GCS:v1|') !== false) return true;
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

    private static function coalesceString(array $src, array $keys, string $default): string
    {
        foreach ($keys as $k) {
            if (isset($src[$k]) && is_string($src[$k]) && $src[$k] !== '') return $src[$k];
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
