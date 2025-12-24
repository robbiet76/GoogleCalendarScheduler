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

    /* ---- Apply (kept for Phase 13.1 Step B wiring) ---- */
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

<!-- ================= Diff Preview ================= -->
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

<!-- ================= Apply UX (Phase 13.1 Step A: UI ONLY) ================= -->
<div class="gcs-apply-panel gcs-hidden" id="gcs-apply-container">
    <h3>Apply Scheduler Changes</h3>

    <div class="gcs-warning">
        <div style="font-weight:bold; margin-bottom:6px;">
            This will modify the FPP scheduler.
        </div>
        <div>
            Review the preview above. Applying cannot be undone.
        </div>
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
    padding: 10px;
    background: #eef5ff;
    border: 1px solid #cfe2ff;
    border-radius: 6px;
}

.gcs-warning {
    padding: 10px;
    background: #fff3cd;
    border: 1px solid #ffeeba;
    border-radius: 6px;
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
    padding:10px;
    background:#eef5ff;
    border:1px solid #cfe2ff;
    border-radius:6px;
}

.gcs-apply-panel {
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
}
</style>

<script>
(function () {
'use strict';

/*
 * FPP-supported JSON endpoint (nopage=1 suppresses HTML wrapper)
 */
var ENDPOINT_BASE =
  'plugin.php?_menu=content&plugin=GoogleCalendarScheduler&page=content.php&nopage=1';

function getJSON(url, cb) {
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (t) {
            try { cb(JSON.parse(t)); }
            catch (e) { cb(null); }
        });
}

function isArr(v) {
    return Object.prototype.toString.call(v) === '[object Array]';
}

function countsFromDiff(diff) {
    var creates = isArr(diff.creates) ? diff.creates.length : 0;
    var updates = isArr(diff.updates) ? diff.updates.length : 0;
    var deletes = isArr(diff.deletes) ? diff.deletes.length : 0;
    return { creates: creates, updates: updates, deletes: deletes, total: creates + updates + deletes };
}

function renderBadges(container, c) {
    container.innerHTML =
        '<div class="gcs-diff-badges">' +
        '<span class="gcs-badge gcs-badge-create">+ ' + c.creates + ' Creates</span>' +
        '<span class="gcs-badge gcs-badge-update">~ ' + c.updates + ' Updates</span>' +
        '<span class="gcs-badge gcs-badge-delete">− ' + c.deletes + ' Deletes</span>' +
        '</div>';
}

function renderSections(container, diff) {
    function section(title, items) {
        if (!isArr(items) || items.length === 0) return;

        var s = document.createElement('div');
        s.className = 'gcs-section';

        var h = document.createElement('h4');
        h.textContent = title + ' (' + items.length + ')';

        var ul = document.createElement('ul');
        ul.style.display = 'none';

        for (var i = 0; i < items.length; i++) {
            var li = document.createElement('li');
            li.textContent = String(items[i]);
            ul.appendChild(li);
        }

        h.onclick = function () {
            ul.style.display = (ul.style.display === 'none') ? 'block' : 'none';
        };

        s.appendChild(h);
        s.appendChild(ul);
        container.appendChild(s);
    }

    section('Creates', diff.creates);
    section('Updates', diff.updates);
    section('Deletes', diff.deletes);
}

function setHidden(el, hidden) {
    if (!el) return;
    el.className = hidden ? (el.className.replace(/\s*gcs-hidden\s*/g, ' ') + ' gcs-hidden') : el.className.replace(/\s*gcs-hidden\s*/g, ' ');
}

var previewBtn = document.getElementById('gcs-preview-btn');
if (!previewBtn) return;

var diffSummary = document.getElementById('gcs-diff-summary');
var diffResults = document.getElementById('gcs-diff-results');

var applyContainer = document.getElementById('gcs-apply-container');
var applySummary = document.getElementById('gcs-apply-summary');
var applyBtn = document.getElementById('gcs-apply-btn');
var applyResult = document.getElementById('gcs-apply-result');

var lastCounts = { creates: 0, updates: 0, deletes: 0, total: 0 };
var hasPreview = false;
var confirmArmed = false;

function resetApplyUI() {
    confirmArmed = false;
    if (applyBtn) {
        applyBtn.disabled = true;
        applyBtn.textContent = 'Apply Changes';
    }
    if (applyResult) applyResult.textContent = '';
}

function showApplyPanelWithCounts(c) {
    if (!applyContainer) return;

    setHidden(applyContainer, false);

    if (applySummary) {
        applySummary.textContent =
            'Ready to apply: ' + c.creates + ' create(s), ' + c.updates + ' update(s), ' + c.deletes + ' delete(s).';
    }

    // Enabled only if non-empty diff
    if (applyBtn) {
        applyBtn.disabled = (c.total === 0);
        applyBtn.textContent = 'Apply Changes';
    }

    if (c.total === 0 && applyResult) {
        applyResult.textContent = 'Nothing to apply.';
    }
}

previewBtn.onclick = function () {
    hasPreview = false;
    resetApplyUI();

    if (diffResults) diffResults.textContent = 'Loading preview…';
    if (diffSummary) {
        diffSummary.textContent = '';
        setHidden(diffSummary, true);
    }
    if (diffResults) diffResults.innerHTML = '';
    setHidden(applyContainer, true);

    getJSON(ENDPOINT_BASE + '&endpoint=experimental_diff', function (data) {
        if (!data || !data.ok) {
            if (diffResults) diffResults.textContent = 'Preview unavailable.';
            setHidden(applyContainer, true);
            return;
        }

        hasPreview = true;

        var diff = data.diff || {};
        lastCounts = countsFromDiff(diff);

        if (diffSummary) {
            setHidden(diffSummary, false);
            renderBadges(diffSummary, lastCounts);
        }

        if (diffResults) {
            diffResults.innerHTML = '';

            if (lastCounts.total === 0) {
                diffResults.innerHTML = '<div class="gcs-empty">No scheduler changes detected.</div>';
            } else {
                renderSections(diffResults, diff);
            }
        }

        showApplyPanelWithCounts(lastCounts);
    });
};

/*
 * Phase 13.1 Step A: UI-only confirmation (NO backend call yet)
 * - First click arms confirmation.
 * - Second click confirms intent and shows message.
 */
if (applyBtn) {
    applyBtn.onclick = function () {
        if (!hasPreview) return;
        if (lastCounts.total === 0) return;

        if (!confirmArmed) {
            confirmArmed = true;
            applyBtn.textContent = 'Confirm Apply';
            if (applyResult) {
                applyResult.textContent = 'Click "Confirm Apply" to confirm intent. (No changes will be made in this step.)';
            }
            return;
        }

        // Confirmed intent — still no execution in Step A
        applyBtn.disabled = true;
        applyBtn.textContent = 'Apply (Not Wired Yet)';
        if (applyResult) {
            applyResult.textContent =
                'Intent confirmed. Apply execution will be wired in Phase 13.1 Step B.';
        }
    };
}

})();
</script>

</div>
