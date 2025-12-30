<?php
declare(strict_types=1);

/**
 * POST action handler (controller-only).
 *
 * RESPONSIBILITIES:
 * - Handle POSTed actions from content.php
 * - Save configuration
 * - Trigger scheduler sync (dry-run or live)
 *
 * HARD RULES:
 * - MUST NOT render UI
 * - MUST NOT echo output
 * - MUST NOT perform GET routing
 *
 * NOTE:
 * - All side effects (writes) are intentional and explicit here
 */

require_once __DIR__ . '/bootstrap.php';

/*
 * TEMPORARY / LEGACY:
 * - Horizon abstraction will be removed
 * - Future behavior will hardcode a safe fixed window (e.g., 365 days)
 */
require_once __DIR__ . '/FppSchedulerHorizon.php';

$action = $_POST['action'] ?? '';

/*
 * --------------------------------------------------------------------
 * Save settings
 * --------------------------------------------------------------------
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

/*
 * --------------------------------------------------------------------
 * Run scheduler sync (dry-run or live)
 * --------------------------------------------------------------------
 */
if ($action === 'sync') {
    $cfg    = GcsConfig::load();
    $dryRun = !empty($cfg['runtime']['dry_run']);

    GcsLog::info('Starting scheduler sync', [
        'dryRun' => $dryRun,
        'mode'   => $dryRun ? 'dry-run' : 'live',
    ]);

    /*
     * Horizon:
     * - Currently delegated to helper for compatibility
     * - Will be replaced with a fixed large window (Phase 26+)
     */
    $horizonDays = GcsFppSchedulerHorizon::getDays();

    $runner = new GcsSchedulerRunner(
        $cfg,
        $horizonDays,
        $dryRun
    );

    $result = $runner->run();

    GcsLog::info('Scheduler sync completed', array_merge(
        $result,
        [
            'dryRun' => $dryRun,
            'mode'   => $dryRun ? 'dry-run' : 'live',
        ]
    ));
}

/*
 * Explicit termination:
 * - Prevent accidental output
 * - Prevent fall-through execution
 */
return;
