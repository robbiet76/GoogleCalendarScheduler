<?php
declare(strict_types=1);

/**
 * GcsLog
 *
 * Low-level static logger used by the Google Calendar Scheduler plugin.
 *
 * Responsibilities:
 * - Format log lines consistently
 * - Append log entries to the configured log file
 *
 * HARD GUARANTEES:
 * - No exceptions thrown
 * - No dependency on plugin state
 * - No side effects beyond file append
 *
 * This class is intentionally minimal and static.
 */
final class GcsLog
{
    /**
     * Write a single log entry.
     *
     * @param string $level Log level (INFO, WARN, ERROR)
     * @param string $msg   Log message
     * @param array<string,mixed> $ctx Optional structured context
     */
    private static function write(string $level, string $msg, array $ctx = []): void
    {
        $line = sprintf(
            "[%s] %s %s",
            date('Y-m-d H:i:s'),
            $level,
            $msg
        );

        if (!empty($ctx)) {
            $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES);
        }

        file_put_contents(GCS_LOG_PATH, $line . "\n", FILE_APPEND);
    }

    /**
     * Write an informational log entry.
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * Write a warning log entry.
     */
    public static function warn(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    /**
     * Write an error log entry.
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }
}

/**
 * GcsLogger
 *
 * Compatibility wrapper used throughout the codebase.
 *
 * Provides a minimal instance-based interface to the static
 * GcsLog implementation.
 *
 * Allows call sites such as:
 *   GcsLogger::instance()->info(...)
 *
 * This wrapper exists solely to avoid refactoring older code.
 */
final class GcsLogger
{
    private static ?self $instance = null;

    /**
     * Return the singleton logger instance.
     */
    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Log an informational message.
     */
    public function info(string $message, array $context = []): void
    {
        GcsLog::info($message, $context);
    }

    /**
     * Log a warning message.
     */
    public function warn(string $message, array $context = []): void
    {
        GcsLog::warn($message, $context);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): void
    {
        GcsLog::error($message, $context);
    }
}
