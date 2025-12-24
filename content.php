<?php
/**
 * GoogleCalendarScheduler
 * content.php
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Experimental scaffolding (explicitly required)
require_once __DIR__ . '/src/experimental/ExecutionContext.php';
require_once __DIR__ . '/src/experimental/ScopedLogger.php';
require_once __DIR__ . '/src/experimental/ExecutionController.php';
require_once __DIR__ . '/src/experimental/HealthProbe.php';
require_once __DIR__ . '/src/experimental/CalendarReader.php';
require_once __DIR__ . '/src/experimental/DiffPreviewer.php';

$cfg = GcsConfig::load();

/*
 * --------------------------------------------------------------------
 * EXPERIMENTAL ENDPOINTS (11.7 / 11.8)
 * --------------------------------------------------------------------
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['endpoint'])
    && $_GET['endpoint'] === 'experimental_diff'
) {
    header('Content-Type: application/json');

    if (empty($cfg['experimental']['enabled'])) {
        echo json_encode([
            'ok'    => false,
            'error' => 'experimental_disabled',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    try {
        $diff = DiffPreviewer::preview($cfg);

        echo json_encode([
            'ok'   => true,
            'diff' => $diff,
        ], JSON_PRETTY_PRINT);
        exit;

    } catch (Throwable $e) {
        echo json_encode([
            'ok'    => false,
            'error' => 'experimental_error',
            'msg'   => $e->getMessage(),
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

/*
 * --------------------------------------------------------------------
 * POST handling (normal UI flow)
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
        }

        if ($action === 'sync') {
            $dryRun = !empty($cfg['runtime']['dry_run']);
            $horizonDays = GcsFppSchedulerHorizon::getDays();
            $runner = new GcsSchedulerRunner($cfg, $horizonDays, $dryRun);
            $runner->run();
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
                <input type="checkbox" name="dry_run"
                    <?php if (!empty($cfg['runtime']['dry_run'])) echo 'checked'; ?>>
                Dry run (do not modify FPP scheduler)
            </label>
        </div>
        <button type="submit" class="buttons">Save Settings</button>
    </form>

    <hr>

    <form method="post">
        <input type="hidden" name="action" value="sync">
        <button type="submit" class="buttons">Sync Calendar</button>
    </form>

    <hr>

    <!-- =============================== -->
    <!-- Diff Preview UI (Phase 12.1/12.2) -->
    <!-- =============================== -->

    <div class="gcs-diff-preview">
        <h3>Scheduler Change Preview</h3>

        <button type="button" class="buttons" id="gcs-preview-btn" disabled>
            Preview Changes
        </button>

        <div id="gcs-diff-results" style="margin-top:12px;"></div>
    </div>

    <hr>

    <!-- =============================== -->
    <!-- Phase 12.3 Step A: Apply UI Skeleton -->
    <!-- =============================== -->

    <div class="gcs-apply-preview">
        <h3>Apply Scheduler Changes</h3>

        <p>
            Applying changes will modify the FPP scheduler based on the previewed
            differences above.
        </p>

        <p style="font-weight: bold; color: #856404;">
            Apply is disabled until all safety checks are satisfied.
        </p>

        <button type="button" class="buttons" disabled>
            Apply Changes
        </button>
    </div>

    <style>
        .gcs-apply-preview {
            padding: 10px;
            background: #fff3cd;
            border: 1px solid #ffeeba;
            border-radius: 6px;
        }
    </style>

    <!-- =============================== -->
    <!-- End Phase 12.3 Step A -->
    <!-- =============================== -->

</div>
