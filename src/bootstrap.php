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

/*
 * ============================================================
 * Core — domain + shared infrastructure (PURE)
 * ============================================================
 * - Configuration
 * - Logging
 * - Identity + comparison
 * - Immutable scheduler state
 * - Parsing, intent modeling, metadata
 * - Shared adapters/utilities
 */
require_once __DIR__ . '/Core/Config.php';
require_once __DIR__ . '/Core/GcsLog.php';

require_once __DIR__ . '/Core/SchedulerIdentity.php';
require_once __DIR__ . '/Core/SchedulerComparator.php';

require_once __DIR__ . '/Core/ExistingScheduleEntry.php';
require_once __DIR__ . '/Core/ComparableScheduleEntry.php';
require_once __DIR__ . '/Core/SchedulerState.php';
require_once __DIR__ . '/Core/SchedulerDiffResult.php';

require_once __DIR__ . '/Core/SchedulerIntent.php';

require_once __DIR__ . '/Core/IcsFetcher.php';
require_once __DIR__ . '/Core/IcsParser.php';
require_once __DIR__ . '/Core/YamlMetadata.php';

require_once __DIR__ . '/Core/TargetResolver.php';

require_once __DIR__ . '/Core/DiffPreviewer.php';
require_once __DIR__ . '/Core/ScheduleEntryExportAdapter.php';

/*
 * ============================================================
 * Planner — PURE planning layer (NO WRITES)
 * ============================================================
 * - Calendar ingestion orchestration
 * - Intent → desired scheduler entry mapping
 * - Diff computation (create / update / delete)
 */
require_once __DIR__ . '/Planner/SchedulerSync.php';
require_once __DIR__ . '/Planner/SchedulerRunner.php';
require_once __DIR__ . '/Planner/SchedulerDiff.php';
require_once __DIR__ . '/Planner/InventorySnapshot.php';
require_once __DIR__ . '/Planner/InventoryService.php';
require_once __DIR__ . '/Planner/ExportService.php';
require_once __DIR__ . '/Planner/SchedulerPlanner.php';

/*
 * ============================================================
 * Apply — WRITE BOUNDARY (scheduler mutations ONLY)
 * ============================================================
 * - The ONLY location allowed to modify schedule.json
 * - Dry-run gating enforced here
 * - Post-write verification enforced here
 */
require_once __DIR__ . '/Apply/SchedulerCleanupPlanner.php';
require_once __DIR__ . '/Apply/SchedulerCleanupApplier.php';
require_once __DIR__ . '/Apply/SchedulerApply.php';
