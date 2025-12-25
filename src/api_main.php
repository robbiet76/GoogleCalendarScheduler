<?php
/**
 * POST action handler only.
 * DO NOT render UI.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/FppSchedulerHorizon.php';

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
        'mode'   => $dryRun ? 'dry-run' : 'live',
    ]);

    // Canonical horizon helper in this repo
    $horizonDays = GcsFppSchedulerHorizon::getDays();

    $runner = new GcsSchedulerRunner($cfg, $horizonDays, $dryRun);
    $result = $runner->run();

    GcsLog::info('Sync completed', array_merge($result, [
        'dryRun' => $dryRun,
        'mode'   => $dryRun ? 'dry-run' : 'live',
    ]));
}

return;
