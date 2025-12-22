<?php
/**
 * GoogleCalendarScheduler
 * content.php
<<<<<<< HEAD
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';
=======
 *
 * Handles POST actions and renders UI.
 *
 * IMPORTANT:
 *  - All POST actions are delegated to src/api_main.php
 *  - No scheduler logic lives here
 *  - UI rendering continues after POST handling
 */

require_once __DIR__ . '/src/bootstrap.php';
>>>>>>> master

$cfg = GcsConfig::load();

/*
<<<<<<< HEAD
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
            // IMPORTANT: dry-run comes ONLY from persisted config
            $dryRun = !empty($cfg['runtime']['dry_run']);

            GcsLog::info('Starting sync', [
                'dryRun' => $dryRun,
            ]);

            $horizonDays = GcsFppSchedulerHorizon::getDays();
            GcsLog::info('Using FPP scheduler horizon', [
                'days' => $horizonDays,
            ]);

            // --------------------------------------------------------
            // FIX: use correct GcsSchedulerRunner class name
            // --------------------------------------------------------
            $runner = new GcsSchedulerRunner($cfg, $horizonDays, $dryRun);
            $result = $runner->run();

            GcsLog::info('Sync completed', $result);
        }

    } catch (Throwable $e) {
        GcsLog::error('GoogleCalendarScheduler error', [
            'error' => $e->getMessage(),
        ]);
    }
=======
 * Delegate POST actions (save / sync) to api_main.php
 * api_main.php is responsible for:
 *  - reading $_POST['action']
 *  - running SchedulerRunner
 *  - logging
 *  - NOT producing output
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_once __DIR__ . '/src/api_main.php';
>>>>>>> master
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

<<<<<<< HEAD
=======
/*
 * ---------------------------------------------------------------------
 * UI rendering continues below (unchanged)
 * ---------------------------------------------------------------------
 *
 * NOTE:
 * Do NOT exit or redirect here.
 * FPP will re-render this page automatically.
 */
>>>>>>> master
