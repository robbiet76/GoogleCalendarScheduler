<?php
declare(strict_types=1);

/**
 * GoogleCalendarScheduler
 * content.php
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Phase 23 export support
require_once __DIR__ . '/src/ScheduleEntryExportAdapter.php';
require_once __DIR__ . '/src/IcsWriter.php';
require_once __DIR__ . '/src/SchedulerExportService.php';

// Phase 23 inventory support
require_once __DIR__ . '/src/SchedulerInventoryService.php';

// Experimental scaffolding
require_once __DIR__ . '/src/experimental/ExecutionContext.php';
require_once __DIR__ . '/src/experimental/ScopedLogger.php';
require_once __DIR__ . '/src/experimental/ExecutionController.php';
require_once __DIR__ . '/src/experimental/HealthProbe.php';
require_once __DIR__ . '/src/experimental/CalendarReader.php';
require_once __DIR__ . '/src/experimental/DiffPreviewer.php';

// Phase 23.1 — Scheduler inventory (read-only)
require_once __DIR__ . '/src/SchedulerInventory.php';

$cfg = GcsConfig::load();

/*
 * --------------------------------------------------------------------
 * POST handling (Save / Sync)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {

        if ($_POST['action'] === 'save') {
            // Empty URL is allowed (clears configuration)
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

    try {

        // Auto status check (plan-only)
        if ($_GET['endpoint'] === 'experimental_plan_status') {
            header('Content-Type: application/json');

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
            header('Content-Type: application/json');

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
            header('Content-Type: application/json');

            $result = DiffPreviewer::apply($cfg);
            $counts = DiffPreviewer::countsFromResult($result);

            echo json_encode([
                'ok'     => true,
                'counts' => $counts,
            ]);
            exit;
        }

        // Export unmanaged scheduler entries to ICS
        if ($_GET['endpoint'] === 'export_unmanaged_ics') {

            $result = SchedulerExportService::exportUnmanaged();

            if (empty($result['ics'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => false,
                    'error' => 'No unmanaged scheduler entries available for export.',
                ]);
                exit;
            }

            // IMPORTANT: no JSON headers here
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="gcs-unmanaged-export.ics"');
            header('Cache-Control: no-store');

            echo $result['ics'];
            exit;
        }

        // Scheduler inventory (read-only counts)
        if ($_GET['endpoint'] === 'experimental_scheduler_inventory') {
            header('Content-Type: application/json');

            try {
                $inv = SchedulerInventoryService::getInventory();

                echo json_encode([
                    'ok' => true,
                    'inventory' => $inv,
                ]);
                exit;

            } catch (Throwable $e) {
                echo json_encode([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ]);
                exit;
            }
        }

    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
        ]);
        exit;
    }
}

$icsUrl = trim($cfg['calendar']['ics_url'] ?? '');
$dryRun = !empty($cfg['runtime']['dry_run']);

function looksLikeIcs(string $url): bool {
    return (bool)preg_match('#^https?://.+\.ics$#i', $url);
}

$isEmpty    = ($icsUrl === '');
$isIcsValid = (!$isEmpty && looksLikeIcs($icsUrl));
$canSave    = ($isEmpty || $isIcsValid);

// Phase 23.1 — Read scheduler inventory
$schedulerInventory = SchedulerInventory::summarize();
?>

<div class="settings">

<div id="gcs-status-bar" class="gcs-status gcs-status--info">
    <span class="gcs-status-dot"></span>
    <span class="gcs-status-text">
        <?php
        if ($isEmpty) {
            echo 'Enter a Google Calendar ICS URL to begin.';
        } elseif (!$isIcsValid) {
            echo 'Please enter a valid Google Calendar ICS (.ics) URL.';
        } elseif ($dryRun) {
            echo 'Developer mode: changes will NOT be written to the scheduler.';
        } else {
            echo 'Ready — check calendar for changes.';
        }
        ?>
    </span>
</div>

<!-- Phase 23.1 — Scheduler Inventory Status -->
<div class="gcs-status gcs-status--info">
    <span class="gcs-status-dot"></span>
    <span class="gcs-status-text">
        <?php if (!empty($schedulerInventory['ok'])): ?>
            Managed scheduler entries:
            <strong><?php echo (int)$schedulerInventory['managed_count']; ?></strong>
            &nbsp;|&nbsp;
            Unmanaged scheduler entries:
            <strong><?php echo (int)$schedulerInventory['unmanaged_count']; ?></strong>
            <?php if (!empty($schedulerInventory['invalid_count'])): ?>
                &nbsp;|&nbsp;
                <span style="color:#b42318;">
                    Invalid:
                    <?php echo (int)$schedulerInventory['invalid_count']; ?>
                </span>
            <?php endif; ?>
        <?php else: ?>
            Unable to read scheduler inventory.
        <?php endif; ?>
    </span>
</div>

<form method="post">
    <input type="hidden" name="action" value="save">

    <div class="setting">
        <label><strong>Google Calendar ICS URL</strong></label><br>
        <input
            type="text"
            name="ics_url"
            size="100"
            id="gcs-ics-input"
            value="<?php echo htmlspecialchars($icsUrl, ENT_QUOTES); ?>"
        >
    </div>

    <button
        type="submit"
        class="buttons"
        id="gcs-save-btn"
        style="margin-top:8px;"
        <?php if (!$canSave) echo 'disabled'; ?>
    >
        Save Settings
    </button>

    <div class="gcs-dev-toggle">
        <label>
            <input type="checkbox" name="dry_run" <?php if ($dryRun) echo 'checked'; ?>>
            Developer mode: dry run
        </label>
    </div>
</form>

<div class="gcs-diff-preview">

    <button type="button" class="buttons gcs-hidden" id="gcs-preview-btn">
        Preview Changes
    </button>

    <div id="gcs-diff-summary" class="gcs-hidden" style="margin-top:12px;"></div>

    <div id="gcs-preview-actions" class="gcs-hidden" style="margin-top:12px;">
        <button type="button" class="buttons" id="gcs-close-preview-btn">Close Preview</button>
        <button type="button" class="buttons" id="gcs-apply-btn" disabled>Apply Changes</button>
    </div>
</div>

<div style="margin-top:16px;">
    <button
        type="button"
        class="buttons"
        id="gcs-export-unmanaged-btn"
    >
        Export Unmanaged Schedules
    </button>
</div>

<style>
/* Anchor container */
.settings {
    position: relative;
    padding-bottom: 36px; /* space for dev toggle */
}

.gcs-hidden { display:none; }
.gcs-summary-row { display:flex; gap:24px; margin-top:6px; }
.gcs-summary-item { white-space:nowrap; }

.gcs-status {
    display:flex;
    align-items:center;
    gap:8px;
    padding:6px 10px;
    margin:8px 0 12px 0;
    border-radius:6px;
    font-weight:600;
}
.gcs-status-dot { width:10px; height:10px; border-radius:50%; background:currentColor; }
.gcs-status--info    { background:#eef4ff; color:#1d4ed8; }
.gcs-status--success { background:#e6f6ea; color:#1e7f43; }
.gcs-status--warning { background:#fff4e5; color:#9a5b00; }
.gcs-status--error   { background:#fdecea; color:#b42318; }

.gcs-dev-toggle {
    position: absolute;
    bottom: 8px;
    right: 8px;
    font-size:0.85em;
    opacity:0.85;
}
</style>

<script>
(function(){
'use strict';

var ENDPOINT =
  'plugin.php?_menu=content&plugin=GoogleCalendarScheduler&page=content.php&nopage=1';

var previewBtn = document.getElementById('gcs-preview-btn');
var diffSummary = document.getElementById('gcs-diff-summary');
var previewActions = document.getElementById('gcs-preview-actions');
var applyBtn = document.getElementById('gcs-apply-btn');
var closePreviewBtn = document.getElementById('gcs-close-preview-btn');
var saveBtn = document.getElementById('gcs-save-btn');
var icsInput = document.getElementById('gcs-ics-input');
var exportBtn = document.getElementById('gcs-export-unmanaged-btn');

function looksLikeIcs(url) {
    return /^https?:\/\/.+\.ics$/i.test(url);
}

icsInput.addEventListener('input', function () {
    var val = icsInput.value.trim();
    saveBtn.disabled = !(val === '' || looksLikeIcs(val));
});

/* Phase 19 status precedence logic */

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

function hidePreviewUi() {
    diffSummary.classList.add('gcs-hidden');
    diffSummary.innerHTML = '';
    previewActions.classList.add('gcs-hidden');
    applyBtn.disabled = true;
    closePreviewBtn.disabled = false;
}

function showPreviewButton() { previewBtn.classList.remove('gcs-hidden'); }
function hidePreviewButton() { previewBtn.classList.add('gcs-hidden'); }

function runPlanStatus() {
    var val = icsInput.value.trim();

    if (val === '') {
        gcsSetStatus('info', 'Enter a Google Calendar ICS URL to begin.');
        hidePreviewButton();
        hidePreviewUi();
        return;
    }

    if (!looksLikeIcs(val)) {
        gcsSetStatus('warning', 'Please enter a valid Google Calendar ICS (.ics) URL.');
        hidePreviewButton();
        hidePreviewUi();
        return;
    }

    return fetch(ENDPOINT + '&endpoint=experimental_plan_status')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) return;

            var t = d.counts.creates + d.counts.updates + d.counts.deletes;

            if (t === 0) {
                gcsSetStatus('success', 'Scheduler is in sync with Google Calendar.');
                hidePreviewButton();
                hidePreviewUi();
            } else {
                gcsSetStatus('warning', t + ' pending scheduler change(s) detected.');
                showPreviewButton();
                hidePreviewUi();
            }
        })
        .catch(() => {
            gcsSetStatus('error', 'Error communicating with Google Calendar.');
            hidePreviewButton();
            hidePreviewUi();
        });
}

function appendInventoryMessage(inv) {
    if (!inv || typeof inv.unmanaged !== 'number') return;

    if (inv.unmanaged <= 0) return;

    var msg = 'Scheduler contains ' + inv.unmanaged + ' unmanaged entr' +
              (inv.unmanaged === 1 ? 'y' : 'ies');

    if (inv.unmanaged_disabled > 0) {
        msg += ' (' + inv.unmanaged_disabled + ' disabled)';
    }

    msg += '.';

    var bar = document.getElementById('gcs-status-bar');
    var text = bar.querySelector('.gcs-status-text');

    // Append — do not replace existing status text
    text.textContent += '  ' + msg;
}

runPlanStatus();

// Fetch scheduler inventory (read-only)
fetch(ENDPOINT + '&endpoint=experimental_scheduler_inventory')
    .then(r => r.json())
    .then(d => {
        if (d && d.ok && d.inventory) {
            appendInventoryMessage(d.inventory);
        }
    })
    .catch(() => {
        // Inventory is informational only — ignore failures
    });


previewBtn.addEventListener('click', function () {

    hidePreviewButton();

    fetch(ENDPOINT + '&endpoint=experimental_diff')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) {
                hidePreviewUi();
                gcsSetStatus('error', 'Error communicating with Google Calendar.');
                return;
            }

            var creates = d.diff.creates.length;
            var updates = d.diff.updates.length;
            var deletes = d.diff.deletes.length;

            diffSummary.classList.remove('gcs-hidden');
            diffSummary.innerHTML = `
                <div class="gcs-summary-row">
                    <div class="gcs-summary-item">➕ Creates: <strong>${creates}</strong></div>
                    <div class="gcs-summary-item">✏️ Updates: <strong>${updates}</strong></div>
                    <div class="gcs-summary-item">🗑️ Deletes: <strong>${deletes}</strong></div>
                </div>
            `;

            previewActions.classList.remove('gcs-hidden');
            applyBtn.disabled = false;
        });
});

closePreviewBtn.addEventListener('click', function () {
    hidePreviewUi();
    runPlanStatus();
});

applyBtn.addEventListener('click', function () {

    applyBtn.disabled = true;
    closePreviewBtn.disabled = true;
    gcsSetStatus('info', 'Applying scheduler changes…');

    fetch(ENDPOINT + '&endpoint=experimental_apply')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) {
                gcsSetStatus('error', 'Error communicating with Google Calendar.');
                applyBtn.disabled = false;
                closePreviewBtn.disabled = false;
                return;
            }

            runPlanStatus();
        })
        .catch(() => {
            gcsSetStatus('error', 'Error communicating with Google Calendar.');
            applyBtn.disabled = false;
            closePreviewBtn.disabled = false;
        });
});

exportBtn.addEventListener('click', function () {

    gcsSetStatus('info', 'Preparing export of unmanaged scheduler entries…');

    // Trigger download via direct navigation (RELIABLE)
    var url = ENDPOINT + '&endpoint=export_unmanaged_ics';
    window.location.href = url;

    // Status update (best-effort UX)
    setTimeout(function () {
        gcsSetStatus(
            'success',
            'Export complete. Import the downloaded file into Google Calendar.'
        );
    }, 800);
});

})();
</script>

</div>
