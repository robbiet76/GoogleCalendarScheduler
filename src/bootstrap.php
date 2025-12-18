<?php

define('GCS_CONFIG_PATH', '/home/fpp/media/config/plugin.googleCalendarScheduler.json');
define('GCS_LOG_PATH', '/home/fpp/media/logs/google-calendar-scheduler.log');

/*
 * Core infrastructure
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Log.php';

/*
 * Calendar + parsing
 */
require_once __DIR__ . '/IcsFetcher.php';
require_once __DIR__ . '/IcsParser.php';

/*
 * Scheduler pipeline (order matters)
 */
require_once __DIR__ . '/TargetResolver.php';
require_once __DIR__ . '/IntentConsolidator.php';
require_once __DIR__ . '/FppScheduleMapper.php';

/*
 * =====================================================
 * Phase 8 – Scheduler Diff / Apply
 * =====================================================
 */

// Models
require_once __DIR__ . '/Model/ExistingScheduleEntry.php';
require_once __DIR__ . '/Model/ComparableScheduleEntry.php';
require_once __DIR__ . '/Model/SchedulerDiffResult.php';

// Engine
require_once __DIR__ . '/SchedulerState.php';
require_once __DIR__ . '/SchedulerDiff.php';
require_once __DIR__ . '/SchedulerApply.php';

/*
 * Runner + Sync (must come last)
 */
require_once __DIR__ . '/SchedulerRunner.php';
require_once __DIR__ . '/SchedulerSync.php';
