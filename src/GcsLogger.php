<?php
/**
 * GcsLogger
 * Simple file logger fallback (JSON context).
 */
class GcsLogger
{
    private $path;

    public function __construct($path = '/home/fpp/media/logs/google-calendar-scheduler.log')
    {
        $this->path = $path;
    }

    public function info($msg, $ctx = [])
    {
        $this->write('INFO', $msg, $ctx);
    }

    public function error($msg, $ctx = [])
    {
        $this->write('ERROR', $msg, $ctx);
    }

    private function write($level, $msg, $ctx)
    {
        $ts = date('Y-m-d H:i:s');
        $ctxJson = '';
        if (is_array($ctx) && !empty($ctx)) {
            $encoded = json_encode($ctx, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $ctxJson = ' ' . $encoded;
            }
        }
        $line = "[{$ts}] {$level} {$msg}{$ctxJson}\n";
        @file_put_contents($this->path, $line, FILE_APPEND);
    }
}
