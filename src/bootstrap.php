<?php

/**
 * GoogleCalendarScheduler bootstrap
 *
 * IMPORTANT:
 * - FPP does NOT use an autoloader
 * - Every class must be explicitly required
 * - Order matters
 */

define('GCS_CONFIG_PATH', '/home/fpp/media/config/plugin.googleCalendarScheduler.json');
define('GCS_LOG_PATH', '/home/fpp/media/logs/google-calendar-scheduler.log');

/*
 * --------------------------------------------------------------------
 * Core infrastructure
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Log.php';

/*
 * --------------------------------------------------------------------
 * Calendar fetching + parsing
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/IcsFetcher.php';
require_once __DIR__ . '/IcsParser.php';

/*
 * --------------------------------------------------------------------
 * Intent + mapping pipeline
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/TargetResolver.php';
require_once __DIR__ . '/IntentConsolidator.php';
require_once __DIR__ . '/YamlMetadata.php';
require_once __DIR__ . '/FppScheduleMapper.php';

/*
 * --------------------------------------------------------------------
 * Scheduler identity + comparison (Phase 11)
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/SchedulerIdentity.php';
require_once __DIR__ . '/SchedulerComparator.php';

/*
 * --------------------------------------------------------------------
 * Scheduler state + diff + apply
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/ExistingScheduleEntry.php';
require_once __DIR__ . '/ComparableScheduleEntry.php';
require_once __DIR__ . '/SchedulerState.php';
require_once __DIR__ . '/SchedulerDiffResult.php';
require_once __DIR__ . '/SchedulerDiff.php';
require_once __DIR__ . '/SchedulerApply.php';
require_once __DIR__ . '/SchedulerSync.php';

/*
 * --------------------------------------------------------------------
 * Planning-only execution (Phase 16.5)
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/SchedulerPlanner.php';

/*
 * --------------------------------------------------------------------
 * Runner (top-level orchestrator)
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/SchedulerRunner.php';
