<?php
/**
 * GoogleCalendarScheduler
 * content.php
 *
 * Handles POST actions and renders UI.
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';
require_once __DIR__ . '/src/IcsFetcher.php';
require_once __DIR__ . '/src/IcsParser.php';
require_once __DIR__ . '/src/SchedulerSync.php';

$cfg = GcsConfig::load();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    try {
        if ($action === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

            GcsConfig::save($cfg);
            $cfg = GcsConfig::load();

            GcsLog::info('Settings saved', [
                'dryRun' => !empty($cfg['runtime']['dry_run']),
            ]);
        }

        if ($action === 'sync') {
            $dryRun = !empty($cfg['runtime']['dry_run']);

            GcsLog::info('Starting sync', ['dryRun' => $dryRun]);

            $horizonDays = FppSchedulerHorizon::getDays();
            GcsLog::info('Using FPP scheduler horizon', ['days' => $horizonDays]);

            // --- Fetch ICS ---
            $fetcher = new IcsFetcher();
            $ics = $fetcher->fetch($cfg['calendar']['ics_url']);

            // --- Parse ICS ---
            $parser = new IcsParser();
            $events = $parser->parse($ics);

            GcsLog::info('Parser returned', [
                'eventCount' => count($events),
            ]);

            // --- Sync ---
            $sync = new SchedulerSync(
                $dryRun,
                $horizonDays,
                $cfg
            );

            $result = $sync->sync($events);

            GcsLog::info('Sync completed', $result);

            $cfg = GcsConfig::load();
        }
    }
    catch (Throwable $e) {
        GcsLog::error('Sync crashed', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
    }
}

// Always render UI
require __DIR__ . '/src/content_main.php';
