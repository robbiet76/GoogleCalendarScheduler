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

        // Save settings
        if ($_POST['action'] === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

            GcsConfig::save($cfg);
            clearstatcache();
            $cfg = GcsConfig::load();
        }

        /**
         * Sync = PLAN-ONLY.
         *
         * IMPORTANT:
         * - Sync MUST NOT write, regardless of dry_run checkbox.
         * - Sync must not instantiate SchedulerSync.
         *
         * Purpose:
         * - Validate pipeline can read/parse/resolve/consolidate.
         * - Produce logs for troubleshooting.
         */
        if ($_POST['action'] === 'sync') {
            $runner = new GcsSchedulerRunner(
                $cfg,
                GcsFppSchedulerHorizon::getDays(),
                true // plan-only: enforce runner dry-run so apply/run cannot be called accidentally
            );

            $intents = $runner->plan();

            GcsLogger::instance()->info('Sync plan completed', [
                'intents_built' => is_array($intents) ? count($intents) : 0,
            ]);
        }

    } catch (Throwable $e) {
        GcsLogger::instance()->error('GoogleCalendarScheduler error', [
            'error' => $e->getMessage(),
        ]);
    }
}

/*
 * --------------------------------------------------------------------
 * AJAX endpoints (Preview / Apply)
 * --------------------------------------------------------------------
 */
if (isset($_GET['endpoint'])) {
    header('Content-Type: application/json');

    try {
        // Preview (plan + diff; NO writes; NO SchedulerSync construction)
        if ($_GET['endpoint'] === 'experimental_diff') {
            if (empty($cfg['experimental']['enabled'])) {
                echo json_encode(['ok' => false]);
                exit;
            }

            $diff = DiffPreviewer::preview($cfg);

            echo json_encode([
                'ok'   => true,
                'diff' => $diff,
            ]);
            exit;
        }

        // Apply (guarded)
        if ($_GET['endpoint'] === 'experimental_apply') {
            try {
                $result = DiffPreviewer::apply($cfg);
                $counts = DiffPreviewer::countsFromResult($result);

                echo json_encode([
                    'ok'     => true,
                    'counts' => $counts,
                ]);
                exit;

            } catch (RuntimeException $e) {
                echo json_encode([
                    'ok'    => false,
                    'error' => $e->getMessage(),
                ]);
                exit;
            }
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
        <input
            type="text"
            name="ics_url"
            size="100"
            value="<?php echo htmlspecialchars($cfg['calendar']['ics_url'] ?? '', ENT_QUOTES); ?>"
        >
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
    <div style="margin-top:6px; opacity:.85;">
        <small>
            <strong>Sync is plan-only</strong> (never writes). It validates parsing + intent building and logs results.
            Use Preview + Apply to change scheduler entries.
        </small>
    </div>
</form>

<hr>

<div class="gcs-diff-preview">
    <h3>Scheduler Change Preview</h3>

<?php if (empty($cfg['experimental']['enabled'])): ?>
    <div class="gcs-info">
        Experimental diff preview is currently disabled.
    </div>
<?php else: ?>
    <button type="button" class="buttons" id="gcs-preview-btn">
        Preview Changes
    </button>

    <div id="gcs-diff-summary" class="gcs-hidden" style="margin-top:12px;"></div>
    <div id="gcs-diff-results" style="margin-top:10px;"></div>
<?php endif; ?>
</div>

<hr>

<div class="gcs-apply-panel gcs-hidden" id="gcs-apply-container">
    <h3>Apply Scheduler Changes</h3>

    <div class="gcs-warning">
        <strong>This will modify the FPP scheduler.</strong><br>
        Review the preview above. Applying cannot be undone.
    </div>

    <div id="gcs-apply-summary" style="margin-top:10px; font-weight:bold;"></div>

    <button
        type="button"
        class="buttons"
        id="gcs-apply-btn"
        disabled>
        Apply Changes
    </button>

    <div id="gcs-apply-result" style="margin-top:10px;"></div>
</div>

<style>
.gcs-hidden { display:none; }
.gcs-mode-banner { padding:10px; border-radius:6px; margin-bottom:12px; font-weight:bold; }
.gcs-mode-dry { background:#eef5ff; border:1px solid #cfe2ff; }
.gcs-mode-live { background:#e6f4ea; border:1px solid #b7e4c7; }
.gcs-info { padding:10px; background:#eef5ff; border:1px solid #cfe2ff; border-radius:6px; }
.gcs-warning { padding:10px; background:#fff3cd; border:1px solid #ffeeba; border-radius:6px; }
.gcs-diff-badges { display:flex; gap:10px; margin:8px 0; }
.gcs-badge { padding:6px 10px; border-radius:12px; font-weight:bold; font-size:.9em; }
.gcs-badge-create { background:#e6f4ea; color:#1e7e34; }
.gcs-badge-update { background:#fff3cd; color:#856404; }
.gcs-badge-delete { background:#f8d7da; color:#721c24; }
.gcs-empty { padding:10px; background:#eef5ff; border:1px solid #cfe2ff; border-radius:6px; }
.gcs-apply-panel { padding:10px; border:1px solid #ddd; border-radius:6px; }
</style>

<script>
(function(){
'use strict';

var ENDPOINT =
  'plugin.php?_menu=content&plugin=GoogleCalendarScheduler&page=content.php&nopage=1';

function getJSON(url, cb){
    fetch(url, {credentials:'same-origin'})
        .then(r => r.json())
        .then(j => cb(j))
        .catch(() => cb(null));
}

function countArr(a){ return Array.isArray(a) ? a.length : 0; }

var previewBtn = document.getElementById('gcs-preview-btn');
if(!previewBtn) return;

var diffSummary = document.getElementById('gcs-diff-summary');
var diffResults = document.getElementById('gcs-diff-results');
var applyBox    = document.getElementById('gcs-apply-container');
var applySummary= document.getElementById('gcs-apply-summary');
var applyBtn    = document.getElementById('gcs-apply-btn');
var applyResult = document.getElementById('gcs-apply-result');

var last=null, armed=false;

previewBtn.onclick=function(){
    armed=false;
    applyBtn.disabled=true;
    applyBtn.textContent='Apply Changes';
    applyResult.textContent='';
    diffResults.textContent='Loading preview…';

    getJSON(ENDPOINT+'&endpoint=experimental_diff',function(d){
        if(!d || !d.ok || !d.diff){
            diffResults.textContent='Preview unavailable.';
            applyBox.classList.add('gcs-hidden');
            last=null;
            return;
        }

        var c=countArr(d.diff.creates),
            u=countArr(d.diff.updates),
            x=countArr(d.diff.deletes),
            t=c+u+x;

        diffSummary.classList.remove('gcs-hidden');
        diffSummary.innerHTML =
          '<div class="gcs-diff-badges">'+
          '<span class="gcs-badge gcs-badge-create">+ '+c+' Creates</span>'+
          '<span class="gcs-badge gcs-badge-update">~ '+u+' Updates</span>'+
          '<span class="gcs-badge gcs-badge-delete">− '+x+' Deletes</span>'+
          '</div>';

        diffResults.innerHTML = (t===0)
            ? '<div class="gcs-empty">No scheduler changes detected.</div>'
            : '';

        applyBox.classList.remove('gcs-hidden');
        applySummary.textContent =
            (t===0) ? 'No pending scheduler changes.' : t+' pending scheduler changes detected.';

        // Apply enabled only if preview finds changes AND dry_run is off
        var isDryRun = <?php echo $dryRun ? 'true' : 'false'; ?>;
        applyBtn.disabled = (t===0) || isDryRun;

        if (isDryRun && t > 0) {
            applyResult.innerHTML =
              '<strong>Apply disabled</strong><br>Dry-run mode is ON. Turn it off to apply scheduler changes.';
        }

        last={t:t};
    });
};

applyBtn.onclick=function(){
    if(!last || last.t===0) return;

    if(!armed){
        armed=true;
        applyBtn.textContent='Confirm Apply';
        applyResult.textContent='Click "Confirm Apply" to proceed.';
        return;
    }

    applyBtn.disabled=true;
    applyBtn.textContent='Applying…';
    applyResult.textContent='Applying scheduler changes…';

    getJSON(ENDPOINT+'&endpoint=experimental_apply',function(r){
        if(!r || !r.ok){
            applyBtn.textContent='Apply Failed';
            applyResult.textContent = (r && r.error) ? r.error : 'Apply failed.';
            return;
        }

        applyBtn.textContent='Apply Completed';
        applyResult.innerHTML =
          '<strong>Changes applied successfully</strong><br>'+
          r.counts.creates+' scheduler entries were created.';

        setTimeout(() => previewBtn.click(), 150);
    });
};

})();
</script>

</div>
