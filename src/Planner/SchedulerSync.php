<?php
declare(strict_types=1);

/**
 * SchedulerSync
 *
 * Canonical scheduler I/O and intent-to-entry mapping layer.
 *
 * Responsibilities:
 * - Read and write schedule.json safely
 * - Perform atomic writes with backup and verification
 * - Convert resolved scheduling intents into FPP scheduler entries
 *
 * HARD RULES:
 * - This class does NOT compute diffs
 * - This class does NOT apply policy decisions
 * - This class does NOT perform dry-run logic
 *
 * All mutation decisions are made elsewhere.
 * This class only performs trusted mechanical operations.
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

            // Phase 29+: identity key is UID-only (SchedulerIdentity is the authority)
            $key = SchedulerIdentity::extractKey($entry);
            if ($key !== null) {
                $present[$key] = true;
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
     * Manifest diff (pure, no I/O)
     * ---------------------------------------------------------------------- */

    /**
     * Compare desired scheduler entries against the manifest.
     *
     * @param array<int,array<string,mixed>> $desiredEntries
     * @return array{toCreate:array,toUpdate:array,toDelete:array}
     */
    public static function diffAgainstManifest(array $desiredEntries): array
    {
        $store = new ManifestStore();
        $manifest = $store->load();

        $desiredById = [];
        foreach ($desiredEntries as $entry) {
            if (!is_array($entry) || !isset($entry['_manifest']['id'])) {
                continue;
            }
            $desiredById[$entry['_manifest']['id']] = $entry;
        }

        $toCreate = [];
        $toUpdate = [];
        $toDelete = [];

        // Detect creates and updates
        foreach ($desiredById as $id => $entry) {
            $hash = $entry['_manifest']['hash'] ?? null;

            if (!isset($manifest['entries'][$id])) {
                $toCreate[$id] = $entry;
                continue;
            }

            if ($manifest['entries'][$id]['hash'] !== $hash) {
                $toUpdate[$id] = $entry;
            }
        }

        // Detect deletes
        foreach ($manifest['entries'] as $id => $meta) {
            if (!isset($desiredById[$id])) {
                $toDelete[$id] = $meta;
            }
        }

        return [
            'toCreate' => $toCreate,
            'toUpdate' => $toUpdate,
            'toDelete' => $toDelete,
        ];
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

        if (isset($intent['template']) && is_array($intent['template'])) {
            $tpl = $intent['template'];
        }
        if (isset($intent['range']) && is_array($intent['range'])) {
            $range = $intent['range'];
        }

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

        // UID may live on outer intent even when template/range is used
        $uid = self::coalesceString($intent, ['uid'], '');
        if ($uid === '') {
            $uid = self::coalesceString($tpl, ['uid'], '');
        }
        if ($uid === '') {
            return 'Missing uid for intent (required for managed scheduler entries)';
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

        $args = [];
        if (isset($tpl['args']) && is_array($tpl['args'])) {
            $args = array_values($tpl['args']);
        }

        // NOTE: Ownership and identity are tracked exclusively via the Manifest.
        // Scheduler args must remain user-defined only (especially for commands).

        $multisyncCommand = self::coalesceBool($tpl, ['multisyncCommand', 'multisync_command'], false);

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
            'args'             => $args,
            'multisyncCommand' => $multisyncCommand,
        ];

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

        // Manifest identity is attached in-memory only. It must not be persisted to schedule.json.
        $entry['_manifest'] = [
            'id' => ManifestIdentity::buildId($entry),
            'hash' => ManifestIdentity::buildHash($entry),
        ];

        // Debug: emits manifest identity for traceability during development.
        error_log('[GCS DEBUG][ManifestIdentity] ' . json_encode($entry['_manifest']));

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

    /**
     * Remove any GCS-owned tags from args[].
     *
     * Phase 29+ policy:
     * - We do NOT preserve any legacy tag variants in scheduler state.
     * - We always emit exactly one canonical managed tag built by SchedulerIdentity.
     *
     * @param array<int,mixed> $args
     * @return array<int,mixed>
     */
    private static function stripAllGcsTagsFromArgs(array $args): array
    {
        $out = [];

        foreach ($args as $a) {
            if (!is_string($a)) {
                $out[] = $a;
                continue;
            }

            // Remove any legacy internal tags or new display-prefixed tags
            if (strpos($a, SchedulerIdentity::INTERNAL_TAG) !== false) {
                continue;
            }
            if (strpos($a, SchedulerIdentity::DISPLAY_TAG) !== false) {
                continue;
            }

            $out[] = $a;
        }

        return array_values($out);
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
}
