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
 * Phase 8.1 – Scheduler State (READ-ONLY)
 */
require_once __DIR__ . '/SchedulerState.php';

/*
 * Phase 8.2 – Scheduler Diff (READ-ONLY)
 */
require_once __DIR__ . '/SchedulerDiff.php';

/*
 * Runner + Sync
 */
require_once __DIR__ . '/SchedulerRunner.php';
require_once __DIR__ . '/SchedulerSync.php';
