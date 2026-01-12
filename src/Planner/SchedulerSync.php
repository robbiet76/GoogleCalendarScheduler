<?php

declare(strict_types=1);

// Ensure a stable debug flag. Some call sites expect GCS_DEBUG to exist.
if (!defined('GCS_DEBUG')) {
    define('GCS_DEBUG', false);
}

/**
 * SchedulerSync
 *
 * Canonical scheduler.json I/O layer (plus legacy mapping helpers pending extraction).
 *
 * Responsibilities:
 * - Read schedule.json safely
 * - Write schedule.json atomically
 * - Create timestamped backups
 * - Verify post-write scheduler state
 *
 * NON-RESPONSIBILITIES (by design):
 * - Does NOT compute diffs
 * - Does NOT generate or compare identities
 * - Does NOT read or write manifests
 * - Does NOT apply policy or adoption logic
 *
 * This class is primarily a mechanical I/O utility.
 * It currently also contains intent→entry mapping used by call sites;
 * that mapping should be extracted to a dedicated builder when we finish untangling dependencies.
 */
final class SchedulerSync
{
    public const SCHEDULE_JSON_PATH = '/home/fpp/media/config/schedule.json';

    /* -------------------------------------------------------------------------
     * schedule.json I/O
     * ---------------------------------------------------------------------- */

    /**
     * Read schedule.json safely.
     *
     * Invalid or malformed content returns an empty array.
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
     * Strict schedule.json reader.
     *
     * @throws RuntimeException on any structural failure
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
            throw new RuntimeException("schedule.json is not a valid JSON array at '{$path}'");
        }

        foreach ($decoded as $i => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException("schedule.json entry #{$i} is not an object");
            }
        }

        return $decoded;
    }

    /**
     * Create a timestamped backup of schedule.json.
     *
     * @return string Backup file path
     */
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
     * Atomically replace schedule.json.
     *
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

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
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
     * Post-write verification.
     *
     * Ensures all expected managed entries exist and deleted entries are gone.
     *
     * @param array<int,string> $expectedManagedKeys
     * @param array<int,string> $expectedDeletedKeys
     */
    public static function verifyScheduleJsonKeysOrThrow(
        array $expectedManagedKeys,
        array $expectedDeletedKeys
    ): void {
        $after = self::readScheduleJsonStatic(self::SCHEDULE_JSON_PATH);

        $present = [];
        foreach ($after as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Verification is UID-only.
            // Existing unmanaged entries may not have a UID and are ignored.
            if (isset($entry['uid']) && is_string($entry['uid']) && $entry['uid'] !== '') {
                $present[$entry['uid']] = true;
            }
        }

        foreach ($expectedManagedKeys as $key) {
            if (!isset($present[$key])) {
                throw new RuntimeException(
                    "Post-write verification failed: expected managed key missing: {$key}"
                );
            }
        }

        foreach ($expectedDeletedKeys as $key) {
            if (isset($present[$key])) {
                throw new RuntimeException(
                    "Post-write verification failed: expected deleted key still present: {$key}"
                );
            }
        }
    }



    /* -------------------------------------------------------------------------
     * Intent → scheduler entry mapping
     * ---------------------------------------------------------------------- */

    /**
     * Public wrapper for intent-to-entry mapping.
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|string
     */
    public static function intentToScheduleEntryPublic(array $intent)
    {
        return self::intentToScheduleEntryStatic($intent);
    }

    /**
     * Pure mapping: resolved intent → FPP scheduler entry.
     *
     * This method performs no I/O and has no side effects.
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|string Error message on failure
     */
    private static function intentToScheduleEntryStatic(array $intent)
    {
        $tpl = $intent;
        $range = null;
        $payload = [];

        if (isset($intent['template']) && is_array($intent['template'])) {
            $tpl = $intent['template'];
        }
        if (isset($intent['range']) && is_array($intent['range'])) {
            $range = $intent['range'];
        }
        if (isset($intent['payload']) && is_array($intent['payload'])) {
            $payload = $intent['payload'];
        }

        // Extract GCS metadata (opaque, never merged or persisted)
        $gcs = [];
        if (isset($intent['gcs']) && is_array($intent['gcs'])) {
            $gcs = $intent['gcs'];
        }
        // $gcs is treated as opaque metadata and must never be merged into the scheduler entry or used for identity.

        $typeRaw = self::coalesceString($tpl, ['type', 'entryType', 'intentType'], '');
        $type    = FPPSemantics::normalizeType($typeRaw);
        $target  = self::coalesceString($tpl, ['target'], '');

        if ($type !== FPPSemantics::TYPE_PLAYLIST
            && $type !== FPPSemantics::TYPE_SEQUENCE
            && $type !== FPPSemantics::TYPE_COMMAND) {
            return "Unable to determine schedule entry type (expected playlist, sequence, or command); got '{$typeRaw}'";
        }
        if ($target === '') {
            return 'Missing target for intent (expected playlist/sequence name or command name)';
        }

        // UID may live on the outer intent even when template/range is used
        $uid = self::extractUid($intent);
        if ($uid === '') {
            $uid = self::extractUid($tpl);
        }
        // UID is optional here; legacy FPP entries do not have one yet.

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

        // Commands are represented as 1-minute events for symmetry with export.
        if ($type === FPPSemantics::TYPE_COMMAND) {
            $endDt = (clone $startDt)->modify('+1 minute');
        }

        $startTime = $startDt->format('H:i:s');
        $endTime   = $endDt->format('H:i:s');

        $startDate = null;
        $endDate   = null;
        $shortDays = '';

        if (is_array($range)) {
            $rStart = isset($range['start']) ? (string)$range['start'] : '';
            $rEnd   = isset($range['end']) ? (string)$range['end'] : '';
            $rDays  = isset($range['days']) ? (string)$range['days'] : '';

            if (self::isDateYmd($rStart)) $startDate = $rStart;
            if (self::isDateYmd($rEnd))   $endDate = $rEnd;

            if ($rDays !== '') {
                $shortDays = trim($rDays);
            }
        }

        if ($startDate === null) $startDate = $startDt->format('Y-m-d');
        if ($endDate === null)   $endDate = $startDate;

        // FPP scheduler commands must have endDate == startDate.
        // The 1-minute duration is calendar-side only.
        if ($type === FPPSemantics::TYPE_COMMAND) {
            $endDate = $startDate;
        }

        // If days were not provided by range, fall back to the start date's weekday.
        if ($shortDays === '') {
            $shortDays = self::dowToShortDay((int)$startDt->format('w'));
        }

        // Phase 20 FIX: FPP "day" MUST be an enum selector (0..15), not a weekday bitmask.
        $fppDayEnum = self::shortDaysToFppDayEnum($shortDays, $startDt);


        // STOP TYPE (aligned to FPP ScheduleEntry.cpp via FPPSemantics)
        $stopType = FPPSemantics::stopTypeToEnum($tpl['stopType'] ?? null);

        // REPEAT (type-aware defaults via FPPSemantics)
        $repeatRaw = $tpl['repeat'] ?? null;
        if ($repeatRaw === null) {
            $repeatRaw = FPPSemantics::getDefaultRepeatForType($type);
        }
        $repeat = self::repeatToFppRepeat($repeatRaw);

        // NOTE: Ownership and identity are tracked exclusively via the Manifest.
        // Scheduler args must remain user-defined only (especially for commands).

        $entry = [
            'enabled'          => FPPSemantics::DEFAULT_ENABLED ? 1 : 0,
            'sequence'         => 0,
            'day'              => $fppDayEnum,
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
        ];
        if ($uid !== '') {
            $entry['uid'] = $uid;
        }

        if ($type === FPPSemantics::TYPE_PLAYLIST) {
            $entry['playlist']  = $target;
            $entry['sequence']  = 0;
            $entry['command']   = '';
        } elseif ($type === FPPSemantics::TYPE_SEQUENCE) {
            // Sequences behave like playlists but are flagged and use extensionless names.
            $entry['playlist']  = $target;
            $entry['sequence']  = 1;
            $entry['command']   = '';
        } else {
            $entry['command']   = $target;
            $entry['playlist']  = '';
            $entry['sequence']  = 0;
        }

        // Payload is opaque command metadata.
        // It must never be merged into the scheduler entry structure.
        // SchedulerApply is responsible for interpreting and applying it.
        if (!empty($payload)) {
            $entry['_payload'] = $payload;
        }

        // NOTE:
        // Manifest identity is NOT generated here.
        // Identity is assigned in SchedulerPlanner after full normalization.

        // DEBUG: log final scheduler entry shape before returning
        if (self::isDebugEnabled()) {
            error_log('[GCS DEBUG][SYNC ENTRY CREATED] ' . json_encode([
                'type'       => $type,
                'target'     => $target,
                'uid'        => $uid,
                'startDate'  => $entry['startDate'] ?? null,
                'endDate'    => $entry['endDate'] ?? null,
                'day'        => $entry['day'] ?? null,
                'startTime'  => $entry['startTime'] ?? null,
                'endTime'    => $entry['endTime'] ?? null,
                'playlist'   => $entry['playlist'] ?? null,
                'command'    => $entry['command'] ?? null,
                'sequence'   => $entry['sequence'] ?? null,
                'repeat'     => $entry['repeat'] ?? null,
                'stopType'   => $entry['stopType'] ?? null,
            ]));
        }
        // Preserve manifest identity if present (opaque, planner-owned)
        if (isset($intent['_manifest']) && is_array($intent['_manifest'])) {
            $entry['_manifest'] = $intent['_manifest'];
        }
        return $entry;
    }


    /**
     * Phase 21 (clean): Map YAML-friendly repeat values into FPP's encoded repeat integer.
     *
     * Confirmed behavior from FPP schedule.json:
     * - minutes * 100
     */
    private static function repeatToFppRepeat($v): int
    {
        if ($v === null) return 0;

        if (is_string($v)) {
            $s = strtolower(trim($v));
            if ($s === '' || $s === 'none') return 0;
            if ($s === 'immediate') return 1;
            if (ctype_digit($s)) {
                $mins = (int)$s;
                return ($mins > 0) ? $mins * 100 : 0;
            }
            return 0;
        }

        if (is_int($v)) {
            if ($v <= 0) return 0;
            if ($v === 1) return 1;
            if ($v >= 100) return $v;
            return $v * 100;
        }

        if (is_float($v)) {
            $mins = (int)round($v);
            return ($mins > 0) ? $mins * 100 : 0;
        }

        return 0;
    }

    /* -------------------- helpers -------------------- */

    private static function shortDaysToFppDayEnum(string $shortDays, DateTime $startDt): int
    {
        $d = trim($shortDays);

        if ($d === 'SuMoTuWeThFrSa') return 7;
        if ($d === 'MoTuWeThFr')     return 8;
        if ($d === 'SuSa')           return 9;
        if ($d === 'MoWeFr')         return 10;
        if ($d === 'TuTh')           return 11;
        if ($d === 'SuMoTuWeTh')     return 12;
        if ($d === 'FrSa')           return 13;

        $single = self::singleShortDayToFppEnum($d);
        if ($single !== null) return $single;

        return (int)$startDt->format('w');
    }

    private static function singleShortDayToFppEnum(string $d): ?int
    {
        return match ($d) {
            'Su' => 0,
            'Mo' => 1,
            'Tu' => 2,
            'We' => 3,
            'Th' => 4,
            'Fr' => 5,
            'Sa' => 6,
            default => null,
        };
    }

    private static function dowToShortDay(int $dow): string
    {
        return match ($dow) {
            0 => 'Su',
            1 => 'Mo',
            2 => 'Tu',
            3 => 'We',
            4 => 'Th',
            5 => 'Fr',
            6 => 'Sa',
            default => 'Su',
        };
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

    private static function isDebugEnabled(): bool
    {
        // Prefer an explicit constant if defined.
        if (defined('GCS_DEBUG')) {
            return (bool) constant('GCS_DEBUG');
        }

        // Allow enabling via environment variable for deployments where constants aren't set.
        $env = getenv('GCS_DEBUG');
        if ($env === false) {
            return false;
        }

        $v = strtolower(trim((string) $env));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private static function extractUid(array $src): string
    {
        return (isset($src['uid']) && is_string($src['uid'])) ? $src['uid'] : '';
    }
}