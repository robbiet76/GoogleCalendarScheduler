<?php
declare(strict_types=1);

/**
 * GoogleCalendarScheduler plugin lifecycle hook
 *
 * Purpose:
 * - Export FPP-derived environment data at plugin load / startup
 *
 * IMPORTANT:
 * - This file is WEB-ONLY.
 * - It must NEVER execute under CLI (e.g. gcs-export).
 */

// ---------------------------------------------------------------------
// HARD GUARD: prevent execution under CLI
// ---------------------------------------------------------------------
if (PHP_SAPI === 'cli') {
    return;
}

$pluginName = "GoogleCalendarScheduler";
$pluginRoot = __DIR__;

$exporter   = $pluginRoot . "/bin/gcs-export";
$runtimeDir = $pluginRoot . "/runtime";
$envFile    = $runtimeDir . "/fpp-env.json";

/* Ensure runtime directory exists */
if (!is_dir($runtimeDir)) {
    @mkdir($runtimeDir, 0755, true);
}

/* Run exporter if available */
if (is_executable($exporter)) {
    exec(escapeshellcmd($exporter) . " >/dev/null 2>&1", $out, $rc);
    if ($rc !== 0) {
        error_log("[GCS] gcs-export failed with exit code {$rc}");
    }
} else {
    error_log("[GCS] gcs-export binary missing or not executable");
}