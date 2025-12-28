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

        // Sync = plan-only, never writes (UI removed in Phase 19.3)
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

            $plan = SchedulerPlanner::plan($cfg);
            $norm = DiffPreviewer::normalizeResultForUi(['diff' => $plan]);

            echo json_encode([
                'ok' => true,
                'counts' => [
                    'creates' => count($norm['creates']),
                    'updates' => count($norm['updates']),
                    'deletes' => count($norm['deletes']),
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

            $plan = SchedulerPlanner::plan($cfg);

            echo json_encode([
                'ok'   => true,
                'diff' => [
                    'creates'        => (isset($plan['creates']) && is_array($plan['creates'])) ? $plan['creates'] : [],
                    'updates'        => (isset($plan['updates']) && is_array($plan['updates'])) ? $plan['updates'] : [],
                    'deletes'        => (isset($plan['deletes']) && is_array($plan['deletes'])) ? $plan['deletes'] : [],
                    'desiredEntries' => (isset($plan['desiredEntries']) && is_array($plan['desiredEntries'])) ? $plan['desiredEntries'] : [],
                    'existingRaw'    => (isset($plan['existingRaw']) && is_array($plan['existingRaw'])) ? $plan['existingRaw'] : [],
                ],
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
$hasIcs = !empty($cfg['calendar']['ics_url']);
?>

<div class="settings">

<!-- ========================================================= -->
<!-- Phase 19: Authoritative Status Bar -->
<!-- ========================================================= -->
<div id="gcs-status-bar" class="gcs-status gcs-status--info">
    <span class="gcs-status-dot"></span>
    <span class="gcs-status-text">
        <?php echo $hasIcs
            ? 'Ready — check calendar for changes.'
            : 'Configure a Google Calendar to begin.'; ?>
    </span>
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

<div class="gcs-diff-preview">
    <h3>Scheduler Change Preview</h3>

    <button type="button" class="buttons" id="gcs-preview-btn">
        Preview Changes
    </button>

    <div id="gcs-diff-summary" class="gcs-hidden" style="margin-top:12px;"></div>
</div>

<hr>

<div class="gcs-apply-panel gcs-hidden" id="gcs-apply-container">
    <h3>Apply Scheduler Changes</h3>
    <button type="button" class="buttons" id="gcs-apply-btn" disabled>Apply Changes</button>
    <div id="gcs-apply-result" style="margin-top:10px;"></div>
</div>

<style>
.gcs-hidden { display:none; }

.gcs-summary-row {
    display:flex;
    gap:24px;
    margin-top:6px;
}
.gcs-summary-item {
    white-space:nowrap;
}

/* Phase 19 Status Bar */
.gcs-status {
    display:flex;
    align-items:center;
    gap:8px;
    padding:6px 10px;
    margin:8px 0 12px 0;
    border-radius:6px;
    font-weight:600;
}
.gcs-status-dot {
    width:10px;
    height:10px;
    border-radius:50%;
    background:currentColor;
}
.gcs-status--info    { background:#eef4ff; color:#1d4ed8; }
.gcs-status--success { background:#e6f6ea; color:#1e7f43; }
.gcs-status--warning { background:#fff4e5; color:#9a5b00; }
.gcs-status--error   { background:#fdecea; color:#b42318; }
</style>

<script>
(function(){
'use strict';

var ENDPOINT =
  'plugin.php?_menu=content&plugin=GoogleCalendarScheduler&page=content.php&nopage=1';

var previewBtn = document.getElementById('gcs-preview-btn');
var applyBtn   = document.getElementById('gcs-apply-btn');

var diffSummary = document.getElementById('gcs-diff-summary');
var applyBox    = document.getElementById('gcs-apply-container');
var applyResult = document.getElementById('gcs-apply-result');

function gcsSetStatus(level, message) {
    var bar = document.getElementById('gcs-status-bar');
    var text = bar.querySelector('.gcs-status-text');

    bar.classList.remove(
        'gcs-status--info',
        'gcs-status--success',
        'gcs-status--warning',
        'gcs-status--error'
    );

    bar.classList.add('gcs-status--' + level);
    text.textContent = message;
}

/* Auto status check */
fetch(ENDPOINT + '&endpoint=experimental_plan_status')
  .then(r => r.json())
  .then(d => {
    if (!d || !d.ok) return;

    var t = d.counts.creates + d.counts.updates + d.counts.deletes;

    if (t === 0) {
        gcsSetStatus('success', 'Scheduler is in sync with Google Calendar.');
    } else {
        gcsSetStatus('warning', t + ' pending scheduler change(s) detected.');
    }
  })
  .catch(() => {
      gcsSetStatus('error', 'Error communicating with Google Calendar.');
  });

/* Preview handler */
previewBtn.addEventListener('click', function () {

    fetch(ENDPOINT + '&endpoint=experimental_diff')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) {
                diffSummary.innerHTML = '❌ Failed to load preview.';
                diffSummary.classList.remove('gcs-hidden');
                gcsSetStatus('error', 'Error communicating with Google Calendar.');
                return;
            }

            var creates = d.diff.creates.length;
            var updates = d.diff.updates.length;
            var deletes = d.diff.deletes.length;
            var total   = creates + updates + deletes;

            diffSummary.classList.remove('gcs-hidden');
            diffSummary.innerHTML = `
                <div><strong>Preview Summary</strong></div>
                <div class="gcs-summary-row">
                    <div class="gcs-summary-item">➕ Creates: <strong>${creates}</strong></div>
                    <div class="gcs-summary-item">✏️ Updates: <strong>${updates}</strong></div>
                    <div class="gcs-summary-item">🗑️ Deletes: <strong>${deletes}</strong></div>
                </div>
            `;

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
    gcsSetStatus('info', 'Applying scheduler changes…');

    fetch(ENDPOINT + '&endpoint=experimental_apply')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) {
                applyResult.innerHTML = '❌ Apply failed.';
                gcsSetStatus('error', 'Error communicating with Google Calendar.');
                applyBtn.disabled = false;
                return;
            }

            applyResult.innerHTML = `
                <div><strong>Apply Complete</strong></div>
                <div class="gcs-summary-row">
                    <div class="gcs-summary-item">➕ Creates: <strong>${d.counts.creates}</strong></div>
                    <div class="gcs-summary-item">✏️ Updates: <strong>${d.counts.updates}</strong></div>
                    <div class="gcs-summary-item">🗑️ Deletes: <strong>${d.counts.deletes}</strong></div>
                </div>
            `;

            gcsSetStatus('success', 'Scheduler changes applied successfully.');
        })
        .catch(() => {
            applyResult.innerHTML = '❌ Apply error.';
            gcsSetStatus('error', 'Error communicating with Google Calendar.');
            applyBtn.disabled = false;
        });
});

})();
</script>

</div>
