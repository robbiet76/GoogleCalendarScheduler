<?php
/**
 * SchedulerStore
 * - Reads FPP schedule.json (flat array on FPP 9.3)
 * - Writes atomically with a lock (live writes enabled later)
 */
class SchedulerStore
{
    private $scheduleFile;

    public function __construct($scheduleFile = '/home/fpp/media/config/schedule.json')
    {
        $this->scheduleFile = $scheduleFile;
    }

    public function getPath()
    {
        return $this->scheduleFile;
    }

    /**
     * Canonical read method
     *
     * @return array<int,array<string,mixed>>
     * @throws Exception
     */
    public function read()
    {
        if (!file_exists($this->scheduleFile)) {
            return [];
        }

        $raw = file_get_contents($this->scheduleFile);
        if ($raw === false) {
            throw new Exception("Failed reading schedule file: {$this->scheduleFile}");
        }

        $rawTrim = trim($raw);
        if ($rawTrim === '') {
            return [];
        }

        $data = json_decode($rawTrim, true);
        if (!is_array($data)) {
            throw new Exception("Schedule JSON invalid: {$this->scheduleFile}");
        }

        // FPP 9.3: flat array of schedules
        if (isset($data['schedules']) && is_array($data['schedules'])) {
            return $data['schedules'];
        }

        return $data;
    }

    /**
     * Backward/forward compatibility alias
     *
     * @return array<int,array<string,mixed>>
     */
    public function load()
    {
        return $this->read();
    }

    /**
     * Atomic write with lock.
     * Not used in dry-run; enabled when writes are allowed.
     *
     * @param array<int,array<string,mixed>> $schedules
     * @throws Exception
     */
    public function write(array $schedules)
    {
        $payload = json_encode($schedules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new Exception("Failed encoding schedule JSON");
        }

        $dir = dirname($this->scheduleFile);
        if (!is_dir($dir)) {
            throw new Exception("Schedule directory missing: {$dir}");
        }

        $lockPath = $this->scheduleFile . '.lock';
        $lockFp = fopen($lockPath, 'c');
        if (!$lockFp) {
            throw new Exception("Unable to open lock file: {$lockPath}");
        }

        try {
            if (!flock($lockFp, LOCK_EX)) {
                throw new Exception("Unable to acquire schedule lock");
            }

            $tmp = $this->scheduleFile . '.tmp';
            if (file_put_contents($tmp, $payload) === false) {
                throw new Exception("Failed writing temp schedule: {$tmp}");
            }

            if (!rename($tmp, $this->scheduleFile)) {
                throw new Exception("Failed replacing schedule file: {$this->scheduleFile}");
            }
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }
}
