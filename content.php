<?php
declare(strict_types=1);

/**
 * GoogleCalendarScheduler — UI Controller + View
 *
 * RESPONSIBILITIES:
 * - Render plugin UI (HTML + JS)
 * - Handle POSTed UI actions (save, plan-only sync)
 * - Expose AJAX endpoints for:
 *   - plan status (PURE)
 *   - diff preview (PURE)
 *   - apply (WRITE via SchedulerApply only, dry-run guarded)
 *   - inventory (read-only)
 *   - export (read-only)
 *   - cleanup preview (read-only)
 *   - cleanup apply (explicit WRITE)
 *
 * HARD RULES:
 * - This file MAY render HTML
 * - This file MUST NOT directly modify schedule.json
 * - All scheduler writes MUST flow through Apply-layer services
 *
 * ARCHITECTURAL NOTE:
 * Falcon Player plugins intentionally combine controller + view
 * logic in content.php. This file is the ONLY place where that
 * coupling is allowed.
 */

// ---------------------------------------------------------------------
// Bootstrap (authoritative dependency map)
// ---------------------------------------------------------------------
require_once __DIR__ . '/src/bootstrap.php';

// ---------------------------------------------------------------------
// Core helpers (UI-only)
/// ---------------------------------------------------------------------
require_once __DIR__ . '/src/Core/FppSemantics.php';
require_once __DIR__ . '/src/Core/DiffPreviewer.php';
require_once __DIR__ . '/src/Core/ScheduleEntryExportAdapter.php';
require_once __DIR__ . '/src/Core/IcsWriter.php';
require_once __DIR__ . '/src/Core/HolidayResolver.php';
require_once __DIR__ . '/src/Core/SunTimeEstimator.php';

// ---------------------------------------------------------------------
// Planner services (PURE — no writes)
// ---------------------------------------------------------------------
require_once __DIR__ . '/src/Planner/ExportService.php';

$cfg = Config::load();

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

            Config::save($cfg);
            clearstatcache();
            $cfg = Config::load();
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

function gcsJsonHeader(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

// Normalize endpoint (prevents HTML fallthrough + array injection)
$endpoint = '';
if (isset($_GET['endpoint']) && is_string($_GET['endpoint'])) {
    $endpoint = $_GET['endpoint'];
}
if ($endpoint !== '') {
    try {

        // --------------------------------------------------------------
        // Plan status (plan-only): returns counts
        // --------------------------------------------------------------
        if ($endpoint=== 'plan_status') {
            gcsJsonHeader();

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

        // --------------------------------------------------------------
        // Diff (plan-only): returns formatted preview array
        // --------------------------------------------------------------
        if ($endpoint=== 'diff') {
            gcsJsonHeader();

            $plan = SchedulerPlanner::plan($cfg);

            $manifest = ManifestResult::fromPlannerResult($plan);
            $preview  = PreviewFormatter::format($manifest);

            echo json_encode([
                'ok'      => true,
                'preview' => $preview,
            ]);
            exit;
        }

        // --------------------------------------------------------------
        // Adopt existing scheduler entries (preview only — NO WRITES)
        // --------------------------------------------------------------
        if ($endpoint === 'adopt_preview') {
            gcsJsonHeader();

            try {
                error_log('[GCS DEBUG][ADOPT_PREVIEW] start');

                $plan = SchedulerPlanner::plan($cfg);
                error_log('[GCS DEBUG][ADOPT_PREVIEW] planner completed');

                $manifest = ManifestResult::fromPlannerResult($plan);
                error_log('[GCS DEBUG][ADOPT_PREVIEW] manifest built');

                $preview  = PreviewFormatter::format($manifest);
                error_log('[GCS DEBUG][ADOPT_PREVIEW] preview formatted');

                echo json_encode([
                    'ok'      => true,
                    'preview' => $preview,
                ]);
                exit;
            } catch (\Throwable $e) {
                error_log('[GCS ERROR][ADOPT_PREVIEW] ' . $e->getMessage());
                error_log($e->getTraceAsString());

                echo json_encode([
                    'ok'    => false,
                    'error' => $e->getMessage(),
                ]);
                exit;
            }
        }

        // --------------------------------------------------------------
        // Apply (WRITE path): blocked if dry-run is enabled
        // --------------------------------------------------------------
        if ($endpoint=== 'apply') {

            // Enforce persisted runtime dry-run
            $runtimeDryRun = !empty($cfg['runtime']['dry_run']);

            // Also honor explicit dry-run request flags (defensive)
            $dry = $_GET['dryRun'] ?? $_POST['dryRun'] ?? $_GET['dry_run'] ?? $_POST['dry_run'] ?? null;
            $requestDryRun = ($dry === '1' || $dry === 1 || $dry === true || $dry === 'true' || $dry === 'on');

            $isDryRun = ($runtimeDryRun || $requestDryRun);

            if ($isDryRun) {
                // Exact same behavior as diff (plan-only, NO WRITES)
                gcsJsonHeader();

                $plan = SchedulerPlanner::plan($cfg);

                echo json_encode([
                    'ok'   => true,
                    'mode' => 'dry-run',
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

            // Normal apply (writes allowed)
            // We return both the plan (for UI parity) and the apply result.
            $plan = SchedulerPlanner::plan($cfg);

            $creates = (isset($plan['creates']) && is_array($plan['creates'])) ? count($plan['creates']) : 0;
            $updates = (isset($plan['updates']) && is_array($plan['updates'])) ? count($plan['updates']) : 0;
            $deletes = (isset($plan['deletes']) && is_array($plan['deletes'])) ? count($plan['deletes']) : 0;

            if (($creates + $updates + $deletes) === 0) {
                gcsJsonHeader();
                echo json_encode([
                    'ok'   => true,
                    'noop' => true,
                    'result' => [
                        'plan'  => $plan,
                        'apply' => ['ok' => true, 'noop' => true],
                    ],
                ]);
                exit;
            }

            $applyResult = SchedulerApply::applyFromConfig($cfg);

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'result' => [
                    'plan'  => $plan,
                    'apply' => $applyResult,
                ],
            ]);
            exit;
        }

        // --------------------------------------------------------------
        // Export unmanaged scheduler entries (DEBUG — JSON)
        // --------------------------------------------------------------
        if ($endpoint === 'export_unmanaged_debug') {
            gcsJsonHeader();

            $entries = InventoryService::getUnmanagedEntries();

            if (empty($entries)) {
                echo json_encode([
                    'ok'      => true,
                    'empty'   => true,
                    'message' => 'No unmanaged scheduler entries found (InventoryService returned empty).',
                ]);
                exit;
            }

            $result = ExportService::export($entries);

            if (!is_array($result)) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Export failed: invalid export result.',
                ]);
                exit;
            }

            // Remove large ICS payload from debug output
            if (isset($result['ics'])) {
                $result['ics'] = '(omitted)';
            }

            echo json_encode($result, JSON_PRETTY_PRINT);
            exit;
        }

        // --------------------------------------------------------------
        // Export unmanaged scheduler entries to ICS
        // --------------------------------------------------------------
        if ($endpoint === 'export_unmanaged_ics') {

            $entries = InventoryService::getUnmanagedEntries();

            if (empty($entries)) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode([
                    'ok'      => true,
                    'empty'   => true,
                    'message' => 'No unmanaged scheduler entries found (InventoryService returned empty).',
                ]);
                exit;
            }

            $result = ExportService::export($entries);

            if (empty($result['ics'])) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode([
                    'ok'    => false,
                    'error' => 'ICS export failed (no ICS payload returned).',
                ]);
                exit;
            }

            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="gcs-unmanaged-export.ics"');
            header('Cache-Control: no-store');

            echo $result['ics'];
            exit;
        }

        // --------------------------------------------------------------
        // Scheduler inventory (read-only counts)
        // --------------------------------------------------------------
        if ($endpoint=== 'scheduler_inventory') {
            gcsJsonHeader();

            try {
                $inv = InventoryService::getInventory();

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
        gcsJsonHeader();
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
            echo 'Enter a Google Calendar ICS URL to get started.';
        } elseif (!$isIcsValid) {
            echo 'Please enter a valid Google Calendar ICS (.ics) URL.';
        } else {
            echo 'Ready — monitoring calendar for changes.';
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

    <div class="gcs-save-row">
        <button
            type="submit"
            class="buttons"
            id="gcs-save-btn"
            <?php if (!$canSave) echo 'disabled'; ?>
        >
            Save Settings
        </button>

        <a
            href="https://calendar.google.com"
            target="_blank"
            rel="noopener noreferrer"
            class="gcs-open-calendar-link"
        >
            Open Google Calendar ↗
        </a>
    </div>

    <div class="gcs-dev-toggle">
        <label>
            <input type="checkbox" id="gcs-dry-run" name="dry_run" <?php if ($dryRun) echo 'checked'; ?>>
            Dry Run
        </label>
    </div>
</form>

<div class="gcs-diff-preview">

    <button type="button" class="buttons gcs-hidden" id="gcs-preview-btn">
        Preview Changes
    </button>

    <div id="gcs-diff-summary" class="gcs-hidden" style="margin-top:12px;"></div>

    <div id="gcs-preview-actions" class="gcs-hidden" style="margin-top:12px;">
        <button type="button" class="buttons" id="gcs-close-preview-btn">Cancel</button>
        <button type="button" class="buttons" id="gcs-apply-btn" disabled>Apply Changes</button>
    </div>
    <div id="gcs-post-apply-actions" class="gcs-hidden" style="margin-top:12px;">
        <button
            type="button"
            class="buttons gcs-nav-btn"
            onclick="window.location.href='/scheduler.php';"
        >
            Open Schedule
        </button>
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

    <div style="margin-top:12px;">
        <button
            type="button"
            class="buttons"
            id="gcs-preview-adopt-btn"
        >
            Preview Adoption
        </button>
    </div>

</div>

<style>
/* Anchor container */
.settings {
    position: relative;
    padding-bottom: 36px; /* space for dev toggle */
}

.gcs-save-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 8px;
}

.gcs-open-calendar-link {
    color: #000;
    font-weight: normal;
    text-decoration: none;
    font-size: 0.95em;
}

.gcs-open-calendar-link:hover {
    text-decoration: underline;
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
var dryRunCheckbox = document.getElementById('gcs-dry-run');
var saveBtn = document.getElementById('gcs-save-btn');
var icsInput = document.getElementById('gcs-ics-input');

function looksLikeIcs(url) {
    return /^https?:\/\/.+\.ics$/i.test(url);
}

icsInput.addEventListener('input', function () {
    var val = icsInput.value.trim();
    saveBtn.disabled = !(val === '' || looksLikeIcs(val));
});

function syncApplyButtonWithDryRun() {
    if (!applyBtn) return;

    if (dryRunCheckbox && dryRunCheckbox.checked) {
        applyBtn.disabled = true;
        applyBtn.style.opacity = '0.5';
        applyBtn.style.cursor = 'not-allowed';
    } else {
        applyBtn.disabled = false;
        applyBtn.style.opacity = '';
        applyBtn.style.cursor = '';
    }
}

// Initial state on page load
syncApplyButtonWithDryRun();

// React immediately + persist Dry Run without reloading the page
if (dryRunCheckbox) {
    dryRunCheckbox.addEventListener('change', function () {
        // 1) Update UI immediately
        syncApplyButtonWithDryRun();

        // If preview UI is visible, update status message in sync with Apply enable/disable
        var previewVisible =
            previewActions &&
            !previewActions.classList.contains('gcs-hidden');

        if (previewVisible) {
            if (this.checked) {
                gcsSetStatus(
                    'warning',
                    'Dry run enabled — changes will not be written to the schedule.'
                );
            } else {
                // Go back to the canonical pending/sync message (with counts)
                runPlanStatus();
            }
        }

        // 2) Persist to config (no page reload)
        // We reuse the existing POST "action=save" path.
        var fd = new FormData();
        fd.append('action', 'save');

        // Preserve current ICS URL so we don’t accidentally wipe it
        fd.append('ics_url', (icsInput && icsInput.value) ? icsInput.value : '');

        // IMPORTANT: send 1/0 so PHP !empty() works (note: '0' is empty in PHP)
        fd.append('dry_run', this.checked ? '1' : '0');

        fetch(window.location.href, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).catch(function () {
            // Non-fatal: UI already updated; persistence just failed.
            // Optional: surface a warning if you want, but keep it quiet for v1 polish.
        });
    });
}

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

    return fetch(ENDPOINT + '&endpoint=plan_status')
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

// --------------------------------------------------
// Refresh status when returning focus to the page
// --------------------------------------------------

document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
        runPlanStatus();
    }
});

window.addEventListener('focus', function () {
    runPlanStatus();
});

// ------------------------------------------------------------------
// Unmanaged scheduler section
// ------------------------------------------------------------------

var unmanagedSection = document.getElementById('gcs-unmanaged-section');
var unmanagedStatus  = document.getElementById('gcs-unmanaged-status');
var unmanagedText    = unmanagedStatus.querySelector('.gcs-status-text');
var exportBtn        = document.getElementById('gcs-export-unmanaged-btn');
var previewAdoptBtn  = document.getElementById('gcs-preview-adopt-btn');

fetch(ENDPOINT + '&endpoint=scheduler_inventory')
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

    var postApply = document.getElementById('gcs-post-apply-actions');
    if (postApply) {
        postApply.classList.add('gcs-hidden');
    }

    hidePreviewButton();

    fetch(ENDPOINT + '&endpoint=diff')
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) {
                hidePreviewUi();
                gcsSetStatus('error', 'Error communicating with Google Calendar.');
                return;
            }

            var creates = d.preview.creates.length;
            var updates = d.preview.updates.length;
            var deletes = d.preview.deletes.length;

            diffSummary.classList.remove('gcs-hidden');
            diffSummary.innerHTML = `
                <div class="gcs-summary-row">
                    <div class="gcs-summary-item">➕ Creates: <strong>${creates}</strong></div>
                    <div class="gcs-summary-item">✏️ Updates: <strong>${updates}</strong></div>
                    <div class="gcs-summary-item">🗑️ Deletes: <strong>${deletes}</strong></div>
                </div>
            `;

            previewActions.classList.remove('gcs-hidden');

            // Dry Run handling — only relevant AFTER preview
            var dryRunCb = document.getElementById('gcs-dry-run');
            var isDryRun = !!(dryRunCb && dryRunCb.checked);

            if (isDryRun) {
                applyBtn.disabled = true;
                gcsSetStatus(
                    'warning',
                    'Dry run enabled — changes will not be written to the schedule.'
                );
            } else {
                applyBtn.disabled = false;
            }
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

    var dryRunCb = document.getElementById('gcs-dry-run');
    var isDryRun = dryRunCb && dryRunCb.checked;

    var ep = isDryRun ? 'diff' : 'apply';
    var url = ENDPOINT + '&endpoint=' + ep;

    if (isDryRun) {
        url += '&dryRun=1';
    }

    fetch(url)
        .then(r => r.json())
        .then(d => {
            if (!d || !d.ok) {
                gcsSetStatus('error', 'Error communicating with Google Calendar.');
                applyBtn.disabled = false;
                closePreviewBtn.disabled = false;
                return;
            }

            // Apply completed successfully — transition UI state

            // Hide apply controls
            applyBtn.classList.add('gcs-hidden');
            closePreviewBtn.classList.add('gcs-hidden');

            // Show post-apply actions (Open Schedule)
            var postApply = document.getElementById('gcs-post-apply-actions');
            if (postApply) {
                postApply.classList.remove('gcs-hidden');
            }

            // Refresh status after write
            runPlanStatus();
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
                'Export ready. Your unmanaged schedules have been downloaded.'
            );
        }, 800);
    });
}

if (previewAdoptBtn) {
    previewAdoptBtn.addEventListener('click', function () {

        gcsSetStatus(
            'info',
            'Previewing adoption of existing scheduler entries…'
        );

        fetch(ENDPOINT + '&endpoint=adopt_preview')
            .then(r => r.json())
            .then(d => {
                if (!d || !d.ok) {
                    gcsSetStatus('error', 'Failed to generate adoption preview.');
                    return;
                }

                diffSummary.classList.remove('gcs-hidden');
                diffSummary.innerHTML =
                    (d.preview && d.preview.html)
                        ? d.preview.html
                        : '<em>No adoptable entries found.</em>';

                previewActions.classList.add('gcs-hidden');
            })
            .catch(() => {
                gcsSetStatus('error', 'Error communicating with scheduler.');
            });
    });
}

})();
</script>

</div>