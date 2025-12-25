<?php
/**
 * GoogleCalendarScheduler
 * content.php
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Experimental scaffolding (Phase 11+)
require_once __DIR__ . '/src/experimental/ExecutionContext.php';
require_once __DIR__ . '/src/experimental/ScopedLogger.php';
require_once __DIR__ . '/src/experimental/ExecutionController.php';
require_once __DIR__ . '/src/experimental/HealthProbe.php';
require_once __DIR__ . '/src/experimental/CalendarReader.php';
require_once __DIR__ . '/src/experimental/DiffPreviewer.php';

$cfg = GcsConfig::load();

/**
 * Append a single JSON line to the apply audit log.
 * Best-effort only; never throws.
 */
function gcs_append_apply_audit(array $entry): void {
    $logDir  = '/home/fpp/media/logs';
    $logFile = $logDir . '/google_calendar_scheduler_apply.log';

    try {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $entry['ts'] = date('c');

        @file_put_contents(
            $logFile,
            json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable $ignored) {
        // Intentionally ignore logging failures
    }
}

/*
 * --------------------------------------------------------------------
 * JSON ENDPOINTS (plugin.php + nopage=1)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['endpoint'])) {
    header('Content-Type: application/json');

    /* ---------------- Diff Preview (read-only) ---------------- */
    if ($_GET['endpoint'] === 'experimental_diff') {

        if (empty($cfg['experimental']['enabled'])) {
            echo json_encode([
                'ok'    => false,
                'error' => 'experimental_disabled',
            ]);
            exit;
        }

        try {
            echo json_encode([
                'ok'   => true,
                'diff' => DiffPreviewer::preview($cfg),
            ]);
            exit;

        } catch (Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => 'experimental_error',
                'msg'   => $e->getMessage(),
            ]);
            exit;
        }
    }

    /* ---------------- Apply (audit logged) ---------------- */
    if ($_GET['endpoint'] === 'experimental_apply') {

        try {
            $result = DiffPreviewer::apply($cfg);

            $counts = DiffPreviewer::countsFromResult(is_array($result) ? $result : []);

            // If result contains summary numeric keys, they may be authoritative
            if (isset($result['adds']) && is_numeric($result['adds'])) {
                $counts['creates'] = (int)$result['adds'];
            }
            if (isset($result['updates']) && is_numeric($result['updates'])) {
                $counts['updates'] = (int)$result['updates'];
            }
            if (isset($result['deletes']) && is_numeric($result['deletes'])) {
                $counts['deletes'] = (int)$result['deletes'];
            }

            $payload = [
                'status'  => 'applied',
                'counts'  => $counts,
                'message' => 'Scheduler changes applied successfully.',
            ];

            gcs_append_apply_audit($payload);

            echo json_encode(array_merge(['ok' => true], $payload));
            exit;

        } catch (Throwable $e) {

            $payload = [
                'status'  => 'blocked',
                'counts'  => ['creates'=>0,'updates'=>0,'deletes'=>0],
                'message' => $e->getMessage(),
            ];

            gcs_append_apply_audit($payload);

            echo json_encode(array_merge(['ok' => false], $payload));
            exit;
        }
    }
}

/*
 * --------------------------------------------------------------------
 * POST handling (normal UI flow)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {

        if ($_POST['action'] === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);
            GcsConfig::save($cfg);

            // Force reload so UI reflects updated state immediately
            clearstatcache();
            $cfg = GcsConfig::load();
        }

        if ($_POST['action'] === 'sync') {
            $runner = new GcsSchedulerRunner(
                $cfg,
                GcsFppSchedulerHorizon::getDays(),
                !empty($cfg['runtime']['dry_run'])
            );
            $runner->run();
        }

    } catch (Throwable $e) {
        GcsLog::error('GoogleCalendarScheduler error', [
            'error' => $e->getMessage(),
        ]);
    }
}

$dryRun = !empty($cfg['runtime']['dry_run']);
?>

<div class="settings">

<!-- =========================================================
     APPLY MODE BANNER (always visible)
     ========================================================= -->
<div class="gcs-mode-banner <?php echo $dryRun ? 'gcs-mode-dry' : 'gcs-mode-live'; ?>">
<?php if ($dryRun): ?>
    🔒 <strong>Apply mode: Dry-run</strong><br>
    Scheduler changes will <strong>NOT</strong> be written.
<?php else: ?>
    🔓 <strong>Apply mode: Live</strong><br>
    Scheduler changes <strong>WILL</strong> be written.
<?php endif; ?>
</div>

<form method="post" id="gcs-settings-form">
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
                id="gcs-dry-run-checkbox"
                <?php if ($dryRun) echo 'checked'; ?>>
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
        disabled
        title="<?php echo $dryRun
            ? 'Apply is disabled while dry-run mode is enabled.'
            : 'Apply pending changes to the FPP scheduler.'; ?>">
        Apply Changes
    </button>

    <div id="gcs-apply-result" style="margin-top:10px;"></div>
</div>

<style>
.gcs-hidden { display:none; }

.gcs-mode-banner {
    padding:10px;
    border-radius:6px;
    margin-bottom:12px;
    font-weight:bold;
}
.gcs-mode-dry {
    background:#eef5ff;
    border:1px solid #cfe2ff;
}
.gcs-mode-live {
    background:#e6f4ea;
    border:1px solid #b7e4c7;
}

.gcs-info {
    padding:10px; background:#eef5ff; border:1px solid #cfe2ff; border-radius:6px;
}
.gcs-warning {
    padding:10px; background:#fff3cd; border:1px solid #ffeeba; border-radius:6px;
}
.gcs-diff-badges { display:flex; gap:10px; margin:8px 0; flex-wrap:wrap; }
.gcs-badge { padding:6px 10px; border-radius:12px; font-weight:bold; font-size:.9em; }
.gcs-badge-create { background:#e6f4ea; color:#1e7e34; }
.gcs-badge-update { background:#fff3cd; color:#856404; }
.gcs-badge-delete { background:#f8d7da; color:#721c24; }
.gcs-section { margin-top:10px; border-top:1px solid #ddd; padding-top:6px; }
.gcs-section h4 { cursor:pointer; margin:6px 0; }
.gcs-section ul { margin:6px 0 6px 18px; }
.gcs-empty {
    padding:10px; background:#eef5ff; border:1px solid #cfe2ff; border-radius:6px;
}
.gcs-apply-panel {
    padding:10px; border:1px solid #ddd; border-radius:6px;
}
</style>

<script>
/*
 * Phase 14.1.2:
 * Confirm before disabling dry-run (checked -> unchecked) on Save Settings.
 */
(function(){
'use strict';

var form = document.getElementById('gcs-settings-form');
var cb   = document.getElementById('gcs-dry-run-checkbox');
if (!form || !cb) return;

// Initial state as rendered (authoritative for this page load)
var initiallyChecked = cb.checked;

form.addEventListener('submit', function(e){
    // Only prompt when transitioning ON -> OFF
    if (initiallyChecked && !cb.checked) {
        var ok = window.confirm(
            'You are about to disable dry-run mode.\n\n' +
            'Scheduler changes will be written to FPP when you apply.\n\n' +
            'Are you sure you want to continue?'
        );

        if (!ok) {
            cb.checked = true;     // restore
            e.preventDefault();    // block save
            return false;
        }
    }
});
})();
</script>

<script>
(function(){
'use strict';

var ENDPOINT =
  'plugin.php?_menu=content&plugin=GoogleCalendarScheduler&page=content.php&nopage=1';

function getJSON(url, cb){
    fetch(url, {credentials:'same-origin'})
        .then(function(r){ return r.text(); })
        .then(function(t){ try{cb(JSON.parse(t));}catch(e){cb(null);} });
}

function isArr(v){ return Object.prototype.toString.call(v)==='[object Array]'; }
function counts(d){
    var c=isArr(d.creates)?d.creates.length:0;
    var u=isArr(d.updates)?d.updates.length:0;
    var x=isArr(d.deletes)?d.deletes.length:0;
    return {c:c,u:u,x:x,t:c+u+x};
}

function hide(el,h){
    if(!el) return;
    el.className = h ? (el.className+' gcs-hidden') : el.className.replace(/\s*gcs-hidden\s*/g,' ');
}

var previewBtn=document.getElementById('gcs-preview-btn');
if(!previewBtn) return;

var diffSummary=document.getElementById('gcs-diff-summary');
var diffResults=document.getElementById('gcs-diff-results');
var applyBox=document.getElementById('gcs-apply-container');
var applySummary=document.getElementById('gcs-apply-summary');
var applyBtn=document.getElementById('gcs-apply-btn');
var applyResult=document.getElementById('gcs-apply-result');

var last=null, armed=false;

/*
 * Phase 14.1.3:
 * Require a successful Preview run before Apply can proceed.
 * This is a UI-only safety gate (backend unchanged).
 */
var previewRan = false;

previewBtn.onclick=function(){
    // Reset gate each time user runs Preview
    previewRan = false;

    armed=false;
    applyBtn.disabled=true;
    applyBtn.textContent='Apply Changes';
    applyResult.textContent='';
    hide(applyBox,true);

    diffResults.textContent='Loading preview…';
    hide(diffSummary,true);
    diffSummary.innerHTML='';
    diffResults.innerHTML='';

    getJSON(ENDPOINT+'&endpoint=experimental_diff',function(d){
        if(!d||!d.ok){
            diffResults.textContent='Preview unavailable.';
            return;
        }

        // Preview succeeded for this page load
        previewRan = true;

        var n=counts(d.diff||{}); last=n;

        hide(diffSummary,false);
        diffSummary.innerHTML=
          '<div class="gcs-diff-badges">'+
          '<span class="gcs-badge gcs-badge-create">+ '+n.c+' Creates</span>'+
          '<span class="gcs-badge gcs-badge-update">~ '+n.u+' Updates</span>'+
          '<span class="gcs-badge gcs-badge-delete">− '+n.x+' Deletes</span>'+
          '</div>';

        diffResults.innerHTML='';
        if(n.t===0){
            diffResults.innerHTML='<div class="gcs-empty">No scheduler changes detected.</div>';
        } else {
            ['creates','updates','deletes'].forEach(function(k){
                var a=(d.diff||{})[k];
                if(!isArr(a)||!a.length) return;
                var s=document.createElement('div'); s.className='gcs-section';
                var h=document.createElement('h4'); h.textContent=k+' ('+a.length+')';
                var ul=document.createElement('ul'); ul.style.display='none';
                a.forEach(function(i){ var li=document.createElement('li'); li.textContent=String(i); ul.appendChild(li);});
                h.onclick=function(){ ul.style.display=(ul.style.display==='none')?'block':'none'; };
                s.appendChild(h); s.appendChild(ul); diffResults.appendChild(s);
            });
        }

        hide(applyBox,false);
        applySummary.textContent='Ready to apply: '+n.c+' create(s), '+n.u+' update(s), '+n.x+' delete(s).';

        // Apply stays disabled until Preview has succeeded (previewRan=true) AND there are changes.
        applyBtn.disabled = (!previewRan || (n.t===0));
    });
};

applyBtn.onclick=function(){
    // Phase 14.1.3 hard gate (defense-in-depth, even if button is force-enabled)
    if (!previewRan) {
        applyResult.textContent='You must run "Preview Changes" successfully before applying.';
        return;
    }

    if(!last||last.t===0) return;

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
        if(r&&r.ok){
            applyBtn.textContent='Apply Completed';
            applyResult.textContent=r.message;
        } else {
            applyBtn.textContent='Apply Blocked';
            applyResult.textContent=r && r.message ? r.message : 'Apply failed.';
        }
    });
};

})();
</script>

</div>
