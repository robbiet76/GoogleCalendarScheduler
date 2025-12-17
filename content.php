<?php
/**
 * GoogleCalendarScheduler - Plugin page
 *
 * IMPORTANT (per /opt/fpp/www/plugin.php):
 * - This file is included inside the plugin.php HTML wrapper.
 * - Do NOT redirect (headers already sent).
 * - Handle POST actions here and then render the UI in the same request.
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';
require_once __DIR__ . '/src/SchedulerSync.php';

$cfg = GcsConfig::load();

// Handle POST actions (Save / Sync) and then fall through to render UI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'save') {
        $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
        $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

        GcsConfig::save($cfg);
        $cfg = GcsConfig::load(); // reload to reflect persisted state

        GcsLog::info('Settings saved', [
            'dryRun' => !empty($cfg['runtime']['dry_run']),
        ]);
    }

    if ($action === 'sync') {
        $dryRun = !empty($cfg['runtime']['dry_run']);

        GcsLog::info('Starting sync', ['dryRun' => $dryRun]);

        $horizonDays = FppSchedulerHorizon::getDays();
        GcsLog::info('Using FPP scheduler horizon', ['days' => $horizonDays]);

        $sync = new SchedulerSync($cfg, $horizonDays, $dryRun);
        $result = $sync->run();

        GcsLog::info('Sync completed', $result);

        // reload config in case sync updates status fields
        $cfg = GcsConfig::load();
    }
}

// Render UI (no side effects inside content_main.php)
require __DIR__ . '/src/content_main.php';
