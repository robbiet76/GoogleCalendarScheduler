<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/IcsParser.php';
require_once __DIR__ . '/SchedulerSync.php';
require_once __DIR__ . '/IntentConsolidator.php';
require_once __DIR__ . '/SchedulerDiff.php';
require_once __DIR__ . '/FppSchedulerHorizon.php';

class GcsApiMain
{
    public static function run(array $settings, bool $dryRun = true)
    {
        $log = GcsLogger::instance();

        $log->info('Starting sync', [
            'dryRun' => $dryRun,
        ]);

        $horizonDays = FppSchedulerHorizon::getDays();

        $log->info('Using FPP scheduler horizon', [
            'days' => $horizonDays,
        ]);

        $tz  = new DateTimeZone(date_default_timezone_get());
        $now = new DateTime('now', $tz);

        $horizonStart = clone $now;
        $horizonEnd   = (clone $now)->modify('+' . $horizonDays . ' days');

        $icsUrl = $settings['calendar']['ics_url'] ?? '';
        if (!$icsUrl) {
            throw new RuntimeException('ICS URL not configured');
        }

        $ics = @file_get_contents($icsUrl);
        if ($ics === false) {
            throw new RuntimeException('Failed to fetch ICS');
        }

        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonStart, $horizonEnd);

        $consolidator = new GcsIntentConsolidator();
        $intents = $consolidator->consolidate($events);

        $sync = new GcsSchedulerSync($dryRun);
        $result = $sync->sync($intents);

        $log->info('Sync completed', $result);

        return $result;
    }
}

/* --------------------------------------------------------------------------
 * Page dispatcher (THIS IS WHAT WAS MISSING)
 * -------------------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg = GcsConfig::load();

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
        $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

        GcsConfig::save($cfg);

        header('Location: plugin.php?plugin=GoogleCalendarScheduler');
        exit;
    }

    if ($action === 'sync') {
        $dryRun = !empty($cfg['runtime']['dry_run']);

        try {
            GcsApiMain::run($cfg, $dryRun);
            $cfg['sync']['last_status'] = 'OK';
        } catch (Throwable $e) {
            GcsLogger::instance()->error('Sync failed', [
                'error' => $e->getMessage(),
            ]);
            $cfg['sync']['last_status'] = 'ERROR';
        }

        GcsConfig::save($cfg);

        header('Location: plugin.php?plugin=GoogleCalendarScheduler');
        exit;
    }
}
