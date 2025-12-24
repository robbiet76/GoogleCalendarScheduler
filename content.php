<?php
/**
 * GoogleCalendarScheduler
 * content.php
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Experimental scaffolding (Phase 11)
require_once __DIR__ . '/src/experimental/ExecutionContext.php';
require_once __DIR__ . '/src/experimental/ScopedLogger.php';
require_once __DIR__ . '/src/experimental/ExecutionController.php';
require_once __DIR__ . '/src/experimental/HealthProbe.php';
require_once __DIR__ . '/src/experimental/CalendarReader.php';
require_once __DIR__ . '/src/experimental/DiffPreviewer.php';

$cfg = GcsConfig::load();

/*
 * --------------------------------------------------------------------
 * JSON ENDPOINTS (FPP plugin.php + nopage=1)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['endpoint'])) {
    header('Content-Type: application/json');

    /* ---- Diff preview (read-only) ---- */
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

    /* ---- Apply (triple-guarded, Phase 11) ---- */
    if ($_GET['endpoint'] === 'experimental_apply') {
        try {
            echo json_encode([
                'ok'      => true,
                'applied' => true,
                'result'  => DiffPreviewer::apply($cfg),
            ]);
            exit;
        } catch (Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => 'apply_blocked',
                'msg'   => $e->getMessage(),
            ]);
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
            $cfg['runtime']['dry_run']  = isset($_POST['dry_run']);
            GcsConfig::save($cfg);
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

    <button type="button" class="buttons" id="gcs-apply-btn" disabled>
        Apply Changes
    </button>

    <div id="gcs-apply-result" style="margin-top:10px;"></div>
</div>

<style>
.gcs-hidden { display:none; }
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
(function () {
'use strict';

var ENDPOINT_BASE =
  'plugin.php?_menu=content&plugin=GoogleCalendarScheduler&page=content.php&nopage=1';

function getJSON(url, cb) {
    fetch(url, { credentials:'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (t) {
            try { cb(JSON.parse(t)); }
            catch (e) { cb(null); }
        });
}

function isArr(v){ return Object.prototype.toString.call(v)==='[object Array]'; }
function counts(diff){
    var c=isArr(diff.creates)?diff.creates.length:0;
    var u=isArr(diff.updates)?diff.updates.length:0;
    var d=isArr(diff.deletes)?diff.deletes.length:0;
    return {c:c,u:u,d:d,t:c+u+d};
}

function renderBadges(el,n){
    el.innerHTML=
      '<div class="gcs-diff-badges">'+
      '<span class="gcs-badge gcs-badge-create">+ '+n.c+' Creates</span>'+
      '<span class="gcs-badge gcs-badge-update">~ '+n.u+' Updates</span>'+
      '<span class="gcs-badge gcs-badge-delete">− '+n.d+' Deletes</span>'+
      '</div>';
}

function renderSections(el,diff){
    function sec(title,items){
        if(!isArr(items)||!items.length)return;
        var s=document.createElement('div'); s.className='gcs-section';
        var h=document.createElement('h4'); h.textContent=title+' ('+items.length+')';
        var ul=document.createElement('ul'); ul.style.display='none';
        items.forEach(function(i){ var li=document.createElement('li'); li.textContent=String(i); ul.appendChild(li);});
        h.onclick=function(){ ul.style.display=(ul.style.display==='none')?'block':'none'; };
        s.appendChild(h); s.appendChild(ul); el.appendChild(s);
    }
    sec('Creates',diff.creates); sec('Updates',diff.updates); sec('Deletes',diff.deletes);
}

function hide(el,h){ if(!el)return; el.className=h?(el.className+' gcs-hidden'):el.className.replace(/\s*gcs-hidden\s*/g,' '); }

var previewBtn=document.getElementById('gcs-preview-btn');
if(!previewBtn)return;

var diffSummary=document.getElementById('gcs-diff-summary');
var diffResults=document.getElementById('gcs-diff-results');
var applyBox=document.getElementById('gcs-apply-container');
var applySummary=document.getElementById('gcs-apply-summary');
var applyBtn=document.getElementById('gcs-apply-btn');
var applyResult=document.getElementById('gcs-apply-result');

var last=null, armed=false;

previewBtn.onclick=function(){
    armed=false;
    applyBtn.disabled=true;
    applyBtn.textContent='Apply Changes';
    applyResult.textContent='';
    hide(applyBox,true);

    diffResults.textContent='Loading preview…';
    hide(diffSummary,true); diffSummary.innerHTML=''; diffResults.innerHTML='';

    getJSON(ENDPOINT_BASE+'&endpoint=experimental_diff',function(d){
        if(!d||!d.ok){ diffResults.textContent='Preview unavailable.'; return; }

        var n=counts(d.diff||{}); last=n;

        hide(diffSummary,false); renderBadges(diffSummary,n);

        diffResults.innerHTML='';
        if(n.t===0){
            diffResults.innerHTML='<div class="gcs-empty">No scheduler changes detected.</div>';
        } else {
            renderSections(diffResults,d.diff||{});
        }

        hide(applyBox,false);
        applySummary.textContent=
          'Ready to apply: '+n.c+' create(s), '+n.u+' update(s), '+n.d+' delete(s).';

        applyBtn.disabled=(n.t===0);
    });
};

applyBtn.onclick=function(){
    if(!last||last.t===0)return;

    if(!armed){
        armed=true;
        applyBtn.textContent='Confirm Apply';
        applyResult.textContent='Click "Confirm Apply" to proceed.';
        return;
    }

    applyBtn.disabled=true;
    applyBtn.textContent='Applying…';
    applyResult.textContent='Applying scheduler changes…';

    getJSON(ENDPOINT_BASE+'&endpoint=experimental_apply',function(r){
        if(r&&r.ok){
            applyBtn.textContent='Apply Completed';
            applyResult.textContent='Apply completed successfully (or blocked by guards).';
        } else {
            applyBtn.textContent='Apply Failed';
            applyResult.textContent='Apply failed or was blocked.';
        }
    });
};

})();
</script>

</div>
