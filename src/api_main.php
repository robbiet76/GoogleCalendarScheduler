<?php

require_once __DIR__ . '/bootstrap.php';

class GcsApiMain
{
    public static function handle(): void
    {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            self::handleSave();
        } elseif ($action === 'sync') {
            self::handleSync();
        }

        // IMPORTANT:
        // FPP requires redirect directly to the content page
        header('Location: plugin.php?plugin=GoogleCalendarScheduler&page=src/content_main.php');
        exit;
    }

    private static function handleSave(): void
    {
        $cfg = GcsConfig::load();

        $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
        $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

        GcsConfig::save($cfg);

        GcsLog::info('Settings saved', [
            'dryRun' => $cfg['runtime']['dry_run'],
        ]);
    }

    private static function handleSync(): void
    {
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

GcsApiMain::handle();
