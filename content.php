<?php
/**
 * GoogleCalendarScheduler
 * Single-page FPP plugin entry point.
 *
 * Handles POST actions and always renders UI.
 * This avoids FPP post-redirect / page routing issues.
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';
require_once __DIR__ . '/src/SchedulerSync.php';

// Handle POST actions first
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $cfg = GcsConfig::load();

        $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
        $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

        GcsConfig::save($cfg);

        GcsLog::info('Settings saved', [
            'dryRun' => $cfg['runtime']['dry_run'],
        ]);
    }

    if ($action === 'sync') {
        $cfg = GcsConfig::load();
        $dryRun = !empty($cfg['runtime']['dry_run']);

        GcsLog::info('Starting sync', ['dryRun' => $dryRun]);

        $horizonDays = FppSchedulerHorizon::getDays();
        GcsLog::info('Using FPP scheduler horizon', ['days' => $horizonDays]);

        $sync = new SchedulerSync($cfg, $horizonDays, $dryRun);
        $result = $sync->run();

        GcsLog::info('Sync completed', $result);
    }
}

// Always render UI
require_once __DIR__ . '/src/content_main.php';
