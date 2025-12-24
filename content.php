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
            $diff = DiffPreviewer::preview($cfg);

            // Defensive counts from diff payload
            $creates = (isset($diff['creates']) && is_array($diff['creates'])) ? count($diff['creates']) : 0;
            $updates = (isset($diff['updates']) && is_array($diff['updates'])) ? count($diff['updates']) : 0;
            $deletes = (isset($diff['deletes']) && is_array($diff['deletes'])) ? count($diff['deletes']) : 0;

            // Diagnostics: do NOT change behavior; only report what we can observe safely.
            $horizonDays = null;
            try {
                $horizonDays = GcsFppSchedulerHorizon::getDays();
            } catch (Throwable $ignored) {
                $horizonDays = null;
            }

            $diagnostics = [
                'timestamp' => date('c'),
                'configPath' => defined('GCS_CONFIG_PATH') ? GCS_CONFIG_PATH : null,
                'experimental' => [
                    'enabled' => !empty($cfg['experimental']['enabled']),
                    'allow_apply' => !empty($cfg['experimental']['allow_apply']),
                ],
                'runtime' => [
                    'dry_run' => !empty($cfg['runtime']['dry_run']),
                ],
                'scheduler' => [
                    'horizon_days' => $horizonDays,
                ],
                'diff_counts' => [
                    'creates' => $creates,
                    'updates' => $updates,
                    'deletes' => $deletes,
                    'total'   => $creates + $updates + $deletes,
                ],
                'request' => [
                    'uri' => $_SERVER['REQUEST_URI'] ?? null,
                ],
            ];

            echo json_encode([
                'ok'          => true,
                'diff'        => $diff,
                'diagnostics' => $diagnostics,
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

    /*
     * ---- Apply (Phase 13.2 Step A: structured result envelope) ----
     *
     * Behavior unchanged (guards still enforced). Only response shape is normalized.
     */
    if ($_GET['endpoint'] === 'experimental_apply') {
        try {
            $result = DiffPreviewer::apply($cfg);

            // Defensive normalization of counts (supports multiple possible result shapes)
            $counts = [
                'creates' => 0,
                'updates' => 0,
                'deletes' => 0,
            ];

            if (is_array($result)) {
                if (isset($result['creates']) && is_array($result['creates'])) $counts['creates'] = count($result['creates']);
                if (isset($result['updates']) && is_array($result['updates'])) $counts['updates'] = count($result['updates']);
                if (isset($result['deletes']) && is_array($result['deletes'])) $counts['deletes'] = count($result['deletes']);

                // Alternative numeric keys (if DiffPreviewer returns aggregate counts)
                if (isset($result['creates_count']) && is_numeric($result['creates_count'])) $counts['creates'] = (int)$result['creates_count'];
                if (isset($result['updates_count']) && is_numeric($result['updates_count'])) $counts['updates'] = (int)$result['updates_count'];
                if (isset($result['deletes_count']) && is_numeric($result['deletes_count'])) $counts['deletes'] = (int)$result['deletes_count'];
            }

            echo json_encode([
                'ok'      => true,
                'status'  => 'applied',
                'counts'  => $counts,
                'message' => 'Scheduler changes applied successfully.',
                // Keep raw result for now (useful for later UI/audit improvements)
                'result'  => $result,
            ]);
            exit;

        } catch (Throwable $e) {
            // Guard-triggered or execution-blocked cases surface here
            echo json_encode([
                'ok'      => false,
                'status'  => 'blocked',
                'message' => $e->getMessage(),
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

/* Apply result status styles */
.gcs-result {
    padding:10px;
    border-radius:6px;
    border: 1px solid #ddd;
    margin-top: 8px;
}
.gcs-result-success {
    background:#e6f4ea;
    border-color:#c7eed2;
    color:#1e7e34;
}
.gcs-result-warn {
    background:#fff3cd;
    border-color:#ffeeba;
    color:#856404;
}
.gcs-result-error {
    background:#f8d7da;
    border-color:#f5c6cb;
    color:#721c24;
}
.gcs-result-title {
    font-weight:bold;
    margin-bottom:4px;
}
.gcs-mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.9em;
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

function esc(s) {
    return String(s)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
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

function renderApplyResult(el, resp) {
    if (!el) return;

    if (!resp) {
        el.innerHTML =
            '<div class="gcs-result gcs-result-error">' +
              '<div class="gcs-result-title">Apply failed</div>' +
              '<div>Unable to parse server response.</div>' +
            '</div>';
        return;
    }

    var ok = !!resp.ok;
    var status = resp.status ? String(resp.status) : (ok ? 'applied' : 'blocked');
    var msg = resp.message ? String(resp.message) : (ok ? 'Apply completed.' : 'Apply failed or was blocked.');
    var cts = resp.counts && typeof resp.counts === 'object' ? resp.counts : null;

    var css = 'gcs-result-error';
    var title = 'Apply failed';

    if (ok && status === 'applied') {
        css = 'gcs-result-success';
        title = 'Apply completed';
    } else if (!ok && status === 'blocked') {
        css = 'gcs-result-warn';
        title = 'Apply blocked';
    }

    var countsLine = '';
    if (cts) {
        var c = Number(cts.creates || 0);
        var u = Number(cts.updates || 0);
        var d = Number(cts.deletes || 0);
        countsLine = '<div class="gcs-mono">Counts: ' + c + ' creates, ' + u + ' updates, ' + d + ' deletes</div>';
    }

    el.innerHTML =
        '<div class="gcs-result ' + css + '">' +
          '<div class="gcs-result-title">' + esc(title) + '</div>' +
          '<div>' + esc(msg) + '</div>' +
          (countsLine ? countsLine : '') +
        '</div>';
}

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
    applyResult.innerHTML='';
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

        // If you want to quickly inspect diagnostics without changing UI,
        // open browser devtools and view the network response JSON.
    });
};

applyBtn.onclick=function(){
    if(!last||last.t===0)return;

    if(!armed){
        armed=true;
        applyBtn.textContent='Confirm Apply';
        applyResult.innerHTML =
            '<div class="gcs-result gcs-result-warn">' +
              '<div class="gcs-result-title">Confirmation required</div>' +
              '<div>Click <strong>Confirm Apply</strong> to proceed.</div>' +
            '</div>';
        return;
    }

    applyBtn.disabled=true;
    applyBtn.textContent='Applying…';
    applyResult.innerHTML =
        '<div class="gcs-result gcs-result-warn">' +
          '<div class="gcs-result-title">Applying…</div>' +
          '<div>Please wait while changes are applied.</div>' +
        '</div>';

    getJSON(ENDPOINT_BASE+'&endpoint=experimental_apply',function(r){
        if (r && r.ok) {
            applyBtn.textContent = 'Apply Completed';
        } else if (r && r.status === 'blocked') {
            applyBtn.textContent = 'Apply Blocked';
        } else {
            applyBtn.textContent = 'Apply Failed';
        }

        renderApplyResult(applyResult, r);
    });
};

})();
</script>

</div>
