<?php

/**
 * Low-level static logger (unchanged behavior)
 */
final class GcsLog
{
    private static function write(string $level, string $msg, array $ctx = []): void
    {
        $line = sprintf(
            "[%s] %s %s",
            date('Y-m-d H:i:s'),
            $level,
            $msg
        );

        if ($ctx) {
            $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES);
        }

        file_put_contents(GCS_LOG_PATH, $line . "\n", FILE_APPEND);
    }

    public static function info(string $m, array $c = []): void
    {
        self::write('INFO', $m, $c);
    }

    public static function warn(string $m, array $c = []): void
    {
        self::write('WARN', $m, $c);
    }

    public static function error(string $m, array $c = []): void
    {
        self::write('ERROR', $m, $c);
    }
}

/**
 * Compatibility wrapper used by the rest of the codebase
 * Allows: GcsLogger::instance()->info(...)
 */
final class GcsLogger
{
    private static $instance;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function info(string $m, array $c = []): void
    {
        GcsLog::info($m, $c);
    }

    public function warn(string $m, array $c = []): void
    {
        GcsLog::warn($m, $c);
    }

    public function error(string $m, array $c = []): void
    {
        GcsLog::error($m, $c);
    }
}
