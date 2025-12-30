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
 */

/*
 * --------------------------------------------------------------------
 * Global paths
 * --------------------------------------------------------------------
 */
define('GCS_CONFIG_PATH', '/home/fpp/media/config/plugin.googleCalendarScheduler.json');
define('GCS_LOG_PATH', '/home/fpp/media/logs/google-calendar-scheduler.log');

/*
 * --------------------------------------------------------------------
 * Core infrastructure (always loaded)
 * --------------------------------------------------------------------
 * - Configuration
 * - Logging
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Log.php';

/*
 * --------------------------------------------------------------------
 * Calendar fetching + parsing (PURE)
 * --------------------------------------------------------------------
 * - Network I/O (read-only)
 * - RFC5545 parsing
 * - Timezone normalization
 */
require_once __DIR__ . '/IcsFetcher.php';
require_once __DIR__ . '/IcsParser.php';

/*
 * --------------------------------------------------------------------
 * Intent + metadata resolution (PURE)
 * --------------------------------------------------------------------
 * - Target resolution (playlist / sequence / command)
 * - Intent consolidation
 * - YAML metadata extraction
 */
require_once __DIR__ . '/TargetResolver.php';
require_once __DIR__ . '/IntentConsolidator.php';
require_once __DIR__ . '/YamlMetadata.php';

/*
 * --------------------------------------------------------------------
 * Legacy mapper (DEPRECATED)
 * --------------------------------------------------------------------
 * - Retained for backward compatibility only
 * - Not used by the current scheduling pipeline
 * - Safe to remove once legacy paths are fully retired
 */
require_once __DIR__ . '/FppScheduleMapper.php';

/*
 * --------------------------------------------------------------------
 * Scheduler identity + comparison (PURE)
 * --------------------------------------------------------------------
 * - Canonical GCS identity extraction
 * - Semantic comparison (update vs no-op)
 */
require_once __DIR__ . '/SchedulerIdentity.php';
require_once __DIR__ . '/SchedulerComparator.php';

/*
 * --------------------------------------------------------------------
 * Scheduler state + diff primitives (PURE)
 * --------------------------------------------------------------------
 * - Immutable scheduler state wrappers
 * - Diff computation
 * - Diff result value objects
 */
require_once __DIR__ . '/ExistingScheduleEntry.php';
require_once __DIR__ . '/ComparableScheduleEntry.php';
require_once __DIR__ . '/SchedulerState.php';
require_once __DIR__ . '/SchedulerDiffResult.php';
require_once __DIR__ . '/SchedulerDiff.php';

/*
 * --------------------------------------------------------------------
 * Scheduler helpers (PURE I/O + mapping only)
 * --------------------------------------------------------------------
 * - schedule.json read/write helpers
 * - Canonical intent → scheduler entry mapping
 */
require_once __DIR__ . '/SchedulerSync.php';

/*
 * --------------------------------------------------------------------
 * Calendar ingestion runner (PURE)
 * --------------------------------------------------------------------
 * - Orchestrates fetch → parse → intent generation
 * - No scheduler writes
 */
require_once __DIR__ . '/SchedulerRunner.php';

/*
 * --------------------------------------------------------------------
 * Planner (PURE)
 * --------------------------------------------------------------------
 * - Combines desired intents + existing scheduler state
 * - Produces create/update/delete diff
 */
require_once __DIR__ . '/SchedulerPlanner.php';

/*
 * --------------------------------------------------------------------
 * Apply layer (WRITE BOUNDARY)
 * --------------------------------------------------------------------
 * - The ONLY component allowed to modify schedule.json
 * - Dry-run gating enforced here
 * - Post-write verification enforced here
 */
require_once __DIR__ . '/SchedulerApply.php';
