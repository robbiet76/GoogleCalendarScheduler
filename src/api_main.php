<?php
/**
 * POST action handler only.
 * DO NOT render UI.
 * DO NOT redirect.
 * DO NOT output anything.
 *
 * FPP will automatically re-render content.php after this script exits.
 */

require_once __DIR__ . '/bootstrap.php';

$action = $_POST['action'] ?? '';

/**
 * Save settings
 */
if ($action === 'save') {
    $cfg = GcsConfig::load();

    $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
    $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

    GcsConfig::save($cfg);

    GcsLog::info('Settings saved', [
        'dryRun' => $cfg['runtime']['dry_run'],
    ]);
}

/**
 * Run scheduler sync (dry-run or live)
 */
if ($action === 'sync') {
    $cfg = GcsConfig::load();
    $dryRun = !empty($cfg['runtime']['dry_run']);

    GcsLog::info('Starting sync', [
        'dryRun' => $dryRun,
    ]);

<<<<<<< HEAD
    $horizonDays = GcsFppSchedulerHorizon::getDays();
    GcsLog::info('Using FPP scheduler horizon', ['days' => $horizonDays]);

    $sync = new GcsSchedulerSync($cfg, $horizonDays, $dryRun);
    $result = $sync->run();
=======
    $horizonDays = FppSchedulerHorizon::getDays();
    GcsLog::info('Using FPP scheduler horizon', [
        'days' => $horizonDays,
    ]);

    $runner = new SchedulerRunner($cfg, $horizonDays, $dryRun);
    $result = $runner->run();
>>>>>>> master

    GcsLog::info('Sync completed', $result);
}

/*
 * IMPORTANT:
 * No redirect.
 * No echo.
 * Just exit cleanly.
 */
return;
