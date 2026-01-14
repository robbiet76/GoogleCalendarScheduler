<?php
declare(strict_types=1);

/**
 * GoogleCalendarScheduler bootstrap
 *
 * CRITICAL CONTEXT:
 * - Falcon Player (FPP) does NOT use an autoloader
 * - Every class MUST be explicitly required
 * - File order matters due to direct class references
 *
 * DESIGN GOAL:
 * - This file is the authoritative dependency map
 * - Grouping reflects architectural layers, not filenames
 * - No logic belongs here — load-only
 *
 * ARCHITECTURE:
 * - Core    : Domain + shared infrastructure (no scheduler writes)
 * - Planner : PURE planning layer (no writes)
 * - Apply   : WRITE boundary (scheduler mutations only)
 */

/*
 * ============================================================
 * Global paths
 * ============================================================
 */
define('GCS_CONFIG_PATH', '/home/fpp/media/config/plugin.googleCalendarScheduler.json');
define('GCS_LOG_PATH', '/home/fpp/media/logs/google-calendar-scheduler.log');
define('GCS_DEBUG_HOLIDAYS', false);

/*
 * ============================================================
 * Core — domain + shared infrastructure (PURE)
 * ============================================================
 */
require_once __DIR__ . '/Core/Config.php';
require_once __DIR__ . '/Core/GcsLog.php';

/* ---------- Runtime environment + semantics ---------- */
require_once __DIR__ . '/Core/FppEnvironment.php';
require_once __DIR__ . '/Core/FppSemantics.php';

/* ---------- Identity / comparison ---------- */
require_once __DIR__ . '/Core/SchedulerIdentity.php';
require_once __DIR__ . '/Core/SchedulerComparator.php';
require_once __DIR__ . '/Core/ManifestIdentity.php';
require_once __DIR__ . '/Core/ManifestStore.php';
require_once __DIR__ . '/Core/SchedulerAdopt.php';

/* ---------- Scheduler state ---------- */
require_once __DIR__ . '/Core/SchedulerState.php';
require_once __DIR__ . '/Core/SchedulerDiffResult.php';
require_once __DIR__ . '/Core/SchedulerIntent.php';

/* ---------- Parsing / metadata ---------- */
require_once __DIR__ . '/Core/IcsFetcher.php';
require_once __DIR__ . '/Core/IcsParser.php';
require_once __DIR__ . '/Core/YamlMetadata.php';
require_once __DIR__ . '/Core/TargetResolver.php';
require_once __DIR__ . '/Core/DiffPreviewer.php';
require_once __DIR__ . '/Core/ScheduleEntryExportAdapter.php';

/*
 * ============================================================
 * Initialize FPP runtime environment (SAFE, non-fatal)
 * ============================================================
 */
try {
    $warnings = [];

    $env = FppEnvironment::loadFromFile(
        '/home/fpp/media/plugins/GoogleCalendarScheduler/runtime/fpp-env.json',
        $warnings
    );

    FPPSemantics::setEnvironment($env->toArray());

    // Optional: log warnings (recommended)
    foreach ($warnings as $w) {
        error_log('[GoogleCalendarScheduler] ' . $w);
    }
} catch (Throwable $e) {
    // Never fatal — plugin must still load
    error_log('[GoogleCalendarScheduler] FPP environment load failed: ' . $e->getMessage());
}

/*
 * ============================================================
 * Planner — PURE planning layer (NO WRITES)
 * ============================================================
 */
require_once __DIR__ . '/Planner/ManifestResult.php';
require_once __DIR__ . '/Planner/PreviewFormatter.php';
require_once __DIR__ . '/Planner/SchedulerSync.php';
require_once __DIR__ . '/Planner/SchedulerRunner.php';
require_once __DIR__ . '/Planner/SchedulerDiff.php';
require_once __DIR__ . '/Planner/InventoryService.php';
require_once __DIR__ . '/Planner/ExportService.php';
require_once __DIR__ . '/Planner/SchedulerPlanner.php';

/*
 * ============================================================
 * Apply — WRITE BOUNDARY (scheduler mutations ONLY)
 * ============================================================
 */

require_once __DIR__ . '/Apply/SchedulerApply.php';