<?php
/**
 * GoogleCalendarScheduler
 * content.php
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Experimental scaffolding (explicitly required, inert)
require_once __DIR__ . '/src/experimental/ExecutionContext.php';
require_once __DIR__ . '/src/experimental/ScopedLogger.php';
require_once __DIR__ . '/src/experimental/ExecutionController.php';
require_once __DIR__ . '/src/experimental/HealthProbe.php';
require_once __DIR__ . '/src/experimental/CalendarReader.php';
require_once __DIR__ . '/src/experimental/DiffPreviewer.php';

$cfg = GcsConfig::load();

/*
 * --------------------------------------------------------------------
 * POST handling
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    try {

        if ($action === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = isset($_POST['dry_run']);

            GcsConfig::save($cfg);
            $cfg = GcsConfig::load();

            GcsLog::info('Settings saved', [
                'dryRun' => $cfg['runtime']['dry_run'],
            ]);
        }

        if ($action === 'sync') {
            $dryRun = !empty($cfg['runtime']['dry_run']);

            GcsLog::info('Starting sync', [
                'dryRun' => $dryRun,
            ]);

            $horizonDays = GcsFppSchedulerHorizon::getDays();
            GcsLog::info('Using FPP scheduler horizon', [
                'days' => $horizonDays,
            ]);

            $runner = new GcsSchedulerRunner($cfg, $horizonDays, $dryRun);
            $result = $runner->run();

            GcsLog::info('Sync completed', $result);
        }

    } catch (Throwable $e) {
        GcsLog::error('GoogleCalendarScheduler error', [
            'error' => $e->getMessage(),
        ]);
    }
}
?>

<div class="settings">
    <h2>Google Calendar Scheduler</h2>

    <!-- SAVE SETTINGS -->
    <form method="post">
        <input type="hidden" name="action" value="save">

        <div class="setting">
            <label><strong>Google Calendar ICS URL</strong></label><br>
            <input
                type="text"
                name="ics_url"
                size="100"
                value="<?php echo htmlspecialchars($cfg['calendar']['ics_url'] ?? '', ENT_QUOTES); ?>"
            >
        </div>

        <div class="setting">
            <label>
                <input
                    type="checkbox"
                    name="dry_run"
                    <?php if (!empty($cfg['runtime']['dry_run'])) echo 'checked'; ?>
                >
                Dry run (do not modify FPP scheduler)
            </label>
        </div>

        <button type="submit" class="buttons">Save Settings</button>
    </form>

    <hr>

    <!-- SYNC -->
    <form method="post">
        <input type="hidden" name="action" value="sync">
        <button type="submit" class="buttons">Sync Calendar</button>
    </form>
</div>
