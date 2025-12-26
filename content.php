<?php
declare(strict_types=1);

/**
 * GoogleCalendarScheduler
 * content.php
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Experimental scaffolding
require_once __DIR__ . '/src/experimental/ExecutionContext.php';
require_once __DIR__ . '/src/experimental/ScopedLogger.php';
require_once __DIR__ . '/src/experimental/ExecutionController.php';
require_once __DIR__ . '/src/experimental/HealthProbe.php';
require_once __DIR__ . '/src/experimental/CalendarReader.php';
require_once __DIR__ . '/src/experimental/DiffPreviewer.php';

$cfg = GcsConfig::load();

/*
 * --------------------------------------------------------------------
 * POST handling (Save / Sync)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {

        if ($_POST['action'] === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

            GcsConfig::save($cfg);
            clearstatcache();
            $cfg = GcsConfig::load();
        }

        // Sync = plan-only, never writes
        if ($_POST['action'] === 'sync') {
            SchedulerPlanner::plan($cfg);
        }

    } catch (Throwable $e) {
        GcsLogger::instance()->error('GoogleCalendarScheduler error', [
            'error' => $e->getMessage(),
        ]);
    }
}

/*
 * --------------------------------------------------------------------
 * AJAX endpoints
 * --------------------------------------------------------------------
 */
if (isset($_GET['endpoint'])) {
    header('Content-Type: application/json');

    try {

        // Auto status check (plan-only)
        if ($_GET['endpoint'] === 'experimental_plan_status') {
            if (empty($cfg['experimental']['enabled'])) {
                echo json_encode(['ok' => false]);
                exit;
            }

            $diff = SchedulerPlanner::plan($cfg);

            echo json_encode([
                'ok' => true,
                'counts' => [
                    'creates' => count($diff['creates']),
                    'updates' => count($diff['updates']),
                    'deletes' => count($diff['deletes']),
                ],
            ]);
            exit;
        }

        // Preview (plan-only)
        if ($_GET['endpoint'] === 'experimental_diff') {
            if (empty($cfg['experimental']['enabled'])) {
                echo json_encode(['ok' => false]);
                exit;
            }

            $diff = SchedulerPlanner::plan($cfg);

            echo json_encode([
                'ok'   => true,
                'diff' => $diff,
            ]);
            exit;
        }

        // Apply (ONLY write path)
        if ($_GET['endpoint'] === 'experimental_apply') {
            $result = DiffPreviewer::apply($cfg);
            $counts = DiffPreviewer::countsFromResult($result);

            echo json_encode([
                'ok'     => true,
                'counts' => $counts,
            ]);
            exit;
        }

    } catch (Throwable $e) {
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
        ]);
        exit;
    }
}

$dryRun = !empty($cfg['runtime']['dry_run']);
?>

<div class="settings">

<div id="gcs-sync-status" class="gcs-hidden gcs-warning" style="margin-bottom:12px;"></div>

<!-- APPLY MODE BANNER -->
<div class="gcs-mode-banner <?php echo $dryRun ? 'gcs-mode-dry' : 'gcs-mode-live'; ?>">
<?php if ($dryRun): ?>
    🔒 <strong>Apply mode: Dry-run</strong><br>
    Scheduler changes will <strong>NOT</strong> be written.
<?php else: ?>
    🔓 <strong>Apply mode: Live</strong><br>
    Scheduler changes <strong>WILL</strong> be written (only when you click Apply).
<?php endif; ?>
</div>

<form method="post">
    <input type="hidden" name="action" value="save">

    <div class="setting">
        <label><strong>Google Calendar ICS URL</strong></label><br>
        <input type="text" name="ics_url" size="100"
            value="<?php echo htmlspecialchars($cfg['calendar']['ics_url'] ?? '', ENT_QUOTES); ?>">
    </div>

    <div class="setting">
        <label>
            <input type="checkbox" name="dry_run" <?php if ($dryRun) echo 'checked'; ?>>
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

<div class="gcs-diff-preview">
    <h3>Scheduler Change Preview</h3>

    <button type="button" class="buttons" id="gcs-preview-btn">
        Preview Changes
    </button>

    <div id="gcs-diff-summary" class="gcs-hidden" style="margin-top:12px;"></div>
    <div id="gcs-diff-results" style="margin-top:10px;"></div>
</div>

<hr>

<div class="gcs-apply-panel gcs-hidden" id="gcs-apply-container">
    <h3>Apply Scheduler Changes</h3>
    <button type="button" class="buttons" id="gcs-apply-btn" disabled>Apply Changes</button>
    <div id="gcs-apply-result" style="margin-top:10px;"></div>
</div>

<style>
.gcs-hidden { display:none; }
.gcs-warning { padding:10px; background:#fff3cd; border:1px solid #ffeeba; border-radius:6px; }
.gcs-mode-banner { padding:10px; border-radius:6px; margin-bottom:12px; font-weight:bold; }
.gcs-mode-dry { background:#eef5ff; border:1px solid #cfe2ff; }
.gcs-mode-live { background:#e6f4ea; border:1px solid #b7e4c7; }
</style>

<script>
(function(){
'use strict';

var ENDPOINT =
  'plugin.php?_menu=content&plugin=GoogleCalendarScheduler&page=content.php&nopage=1';

var statusBox  = document.getElementById('gcs-sync-status');
var previewBtn = document.getElementById('gcs-preview-btn');
var applyBtn   = document.getElementById('gcs-apply-btn');

var diffSummary = document.getElementById('gcs-diff-summary');
var diffResults = document.getElementById('gcs-diff-results');
var applyBox    = document.getElementById('gcs-apply-container');
var applyResult = document.getElementById('gcs-apply-result');

var lastDiff = null;

/* Auto status check */
fetch(ENDPOINT + '&endpoint=experimental_plan_status')
  .then(r => r.json())
  .then(d => {
    if(!d || !d.ok) return;
    var t = d.counts.creates + d.counts.updates + d.counts.deletes;
    if (t > 0) {
        statusBox.classList.remove('gcs-hidden');
        statusBox.innerHTML =
            '⚠️ <strong>Scheduler is out of sync with Google Calendar</strong><br>' +
            t + ' pending change(s) detected. Click <em>Preview Changes</em> to review.';
    }
  });

/* Preview handler */
previewBtn.addEventListener('click', function () {

    fetch(ENDPOINT + '&endpoint=experimental_diff')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) {
                diffResults.innerHTML = '❌ Failed to load preview.';
                return;
            }

            lastDiff = d.diff;

            var creates = d.diff.creates.length;
            var updates = d.diff.updates.length;
            var deletes = d.diff.deletes.length;
            var total   = creates + updates + deletes;

            diffSummary.classList.remove('gcs-hidden');
            diffSummary.innerHTML =
                '<strong>Preview Summary</strong><br>' +
                'Creates: ' + creates + ', ' +
                'Updates: ' + updates + ', ' +
                'Deletes: ' + deletes;

            diffResults.innerHTML =
                '<pre>' + JSON.stringify(d.diff, null, 2) + '</pre>';

            if (total > 0) {
                applyBox.classList.remove('gcs-hidden');
                applyBtn.disabled = false;
            }
        });
});

/* Apply handler */
applyBtn.addEventListener('click', function () {

    applyBtn.disabled = true;
    applyResult.innerHTML = '⏳ Applying changes...';

    fetch(ENDPOINT + '&endpoint=experimental_apply')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) {
                applyResult.innerHTML =
                    '❌ Apply failed: ' + (d && d.error ? d.error : 'unknown error');
                applyBtn.disabled = false;
                return;
            }

            applyResult.innerHTML =
                '✅ Apply complete<br>' +
                'Creates: ' + d.counts.creates + ', ' +
                'Updates: ' + d.counts.updates + ', ' +
                'Deletes: ' + d.counts.deletes;
        })
        .catch(err => {
            applyResult.innerHTML = '❌ Apply error: ' + err;
            applyBtn.disabled = false;
        });
});

})();
</script>

</div>
