<?php
declare(strict_types=1);

/**
 * GoogleCalendarScheduler UI Controller + View
 *
 * Responsibilities:
 * - Render plugin UI
 * - Handle POSTed UI actions (save, plan-only sync)
 * - Expose AJAX endpoints for:
 *   - plan preview
 *   - apply (guarded write)
 *   - inventory
 *   - export
 *   - cleanup
 *
 * HARD RULES:
 * - This file MAY render HTML
 * - This file MUST NOT directly write schedule.json
 * - All scheduler writes MUST flow through dedicated services
 *
 * Architectural note:
 * FPP plugins intentionally combine controller + view in content.php.
 * This file is the ONLY place where that coupling is allowed.
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Export support
require_once __DIR__ . '/src/ScheduleEntryExportAdapter.php';
require_once __DIR__ . '/src/IcsWriter.php';
require_once __DIR__ . '/src/SchedulerExportService.php';

// Inventory support
require_once __DIR__ . '/src/SchedulerInventoryService.php';

// WRITE-CAPABLE SERVICES (guarded; never auto-run)
require_once __DIR__ . '/src/cleanup/SchedulerCleanupPlanner.php';
require_once __DIR__ . '/src/cleanup/SchedulerCleanupApplier.php';

// Infrastructre support
require_once __DIR__ . '/src/Infrastructure/DiffPreviewer.php';


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
        // Intentionally discard result; UI status polling handles feedback
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
 * AJAX endpoints
 *
 * Contract:
 * - Each endpoint MUST:
 *   - be side-effect free unless explicitly documented
 *   - emit exactly one response
 *   - exit immediately after handling
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

        // Cleanup preview (read-only)
        if ($_GET['endpoint'] === 'experimental_cleanup_preview') {
            header('Content-Type: application/json');

            $plan = SchedulerCleanupPlanner::plan();

            echo json_encode([
                'ok' => !empty($plan['ok']),
                'plan' => $plan,
            ]);
            exit;
        }

        // Cleanup apply (guarded write)
        if ($_GET['endpoint'] === 'experimental_cleanup_apply') {
            header('Content-Type: application/json');

            $res = SchedulerCleanupApplier::apply();

            echo json_encode($res);
            exit;
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

<div id="gcs-unmanaged-section" class="gcs-hidden">

    <hr style="margin:20px 0;">

    <div id="gcs-unmanaged-status"
         class="gcs-status gcs-status--info">
        <span class="gcs-status-dot"></span>
        <span class="gcs-status-text">
            <!-- Filled dynamically -->
        </span>
    </div>

    <div style="margin-top:12px;">
        <button
            type="button"
            class="buttons"
            id="gcs-export-unmanaged-btn"
        >
            Export Unmanaged Schedules
        </button>
    </div>

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

function gcsSetUnmanagedStatus(level, message) {
    var bar = document.getElementById('gcs-unmanaged-status');
    if (!bar) return;

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

runPlanStatus();

// ------------------------------------------------------------------
// Unmanaged scheduler section
// ------------------------------------------------------------------

var unmanagedSection = document.getElementById('gcs-unmanaged-section');
var unmanagedStatus  = document.getElementById('gcs-unmanaged-status');
var unmanagedText    = unmanagedStatus.querySelector('.gcs-status-text');
var exportBtn        = document.getElementById('gcs-export-unmanaged-btn');

fetch(ENDPOINT + '&endpoint=experimental_scheduler_inventory')
    .then(r => r.json())
    .then(d => {
        if (!d || !d.ok || !d.inventory) return;

        var inv = d.inventory;
        if (typeof inv.unmanaged !== 'number' || inv.unmanaged <= 0) {
            unmanagedSection.classList.add('gcs-hidden');
            return;
        }

        var msg = 'You have ' + inv.unmanaged + ' unmanaged scheduler entr' +
                  (inv.unmanaged === 1 ? 'y' : 'ies') + '.';

        if (inv.unmanaged_disabled > 0) {
            msg += ' (' + inv.unmanaged_disabled + ' disabled)';
        }

        msg += ' These are not controlled by Google Calendar.';

        unmanagedText.textContent = msg;
        unmanagedSection.classList.remove('gcs-hidden');
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

if (exportBtn) {
    exportBtn.addEventListener('click', function () {

        gcsSetUnmanagedStatus(
            'info',
            'Preparing export of unmanaged scheduler entries…'
        );

        var url = ENDPOINT + '&endpoint=export_unmanaged_ics';
        window.location.href = url;

        setTimeout(function () {
            gcsSetUnmanagedStatus(
                'success',
                'Export complete. Import the downloaded file into Google Calendar.'
            );
        }, 800);
    });
}

})();
</script>

</div>
