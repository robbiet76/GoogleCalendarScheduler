<?php
declare(strict_types=1);

/**
 * GoogleCalendarScheduler
 * content.php (Phase 19.2 UI – ready state fixed, no auto-plan)
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
 * POST handling (Save config only)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            GcsConfig::save($cfg);
            clearstatcache();
            $cfg = GcsConfig::load();
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

        if ($_GET['endpoint'] === 'experimental_diff') {
            if (empty($cfg['experimental']['enabled'])) {
                echo json_encode(['ok' => false]);
                exit;
            }

            $plan = SchedulerPlanner::plan($cfg);

            echo json_encode([
                'ok'   => true,
                'diff' => $plan,
            ]);
            exit;
        }

        if ($_GET['endpoint'] === 'experimental_apply') {
            if (!empty($_GET['dry_run'])) {
                $cfg['runtime']['dry_run'] = true;
            }

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
?>

<div id="gcs-root">

  <!-- Status Bar -->
  <div id="gcs-status-bar" class="gcs-status info"></div>

  <!-- Setup Panel -->
  <div id="gcs-setup-panel" class="gcs-panel hidden">
    <h3>Google Calendar Configuration</h3>

    <label for="gcs-calendar-url">Google Calendar ICS URL</label><br>
    <input
      type="text"
      id="gcs-calendar-url"
      size="100"
      value="<?php echo htmlspecialchars($cfg['calendar']['ics_url'] ?? '', ENT_QUOTES); ?>"
      placeholder="https://calendar.google.com/calendar/ical/…"
    />

    <div class="gcs-actions">
      <button id="gcs-save-btn" disabled>Save</button>
    </div>
  </div>

  <!-- Preview Panel -->
  <div id="gcs-preview-panel" class="gcs-panel hidden">
    <h3>Scheduler Change Preview</h3>
    <pre id="gcs-preview-table"></pre>

    <div class="gcs-actions">
      <button id="gcs-preview-btn">Refresh Preview</button>
    </div>
  </div>

  <!-- Apply Panel -->
  <div id="gcs-apply-panel" class="gcs-panel hidden">
    <h3>Apply Scheduler Changes</h3>

    <label>
      <input type="checkbox" id="gcs-dry-run-toggle">
      Dry-run (apply preview only — no scheduler changes written)
    </label>

    <div class="gcs-actions">
      <button id="gcs-apply-btn">Apply Changes</button>
    </div>
  </div>

</div>

<style>
.hidden { display:none; }

.gcs-status {
  padding:10px;
  margin-bottom:12px;
  border-radius:6px;
  font-weight:bold;
}
.gcs-status.info    { background:#eef5ff; border:1px solid #cfe2ff; }
.gcs-status.success { background:#e6f4ea; border:1px solid #b7e4c7; }
.gcs-status.warning { background:#fff3cd; border:1px solid #ffeeba; }
.gcs-status.error   { background:#f8d7da; border:1px solid #f5c2c7; }

.gcs-panel {
  margin-bottom:20px;
  padding:10px;
  border:1px solid #ddd;
  border-radius:6px;
}

.gcs-actions {
  margin-top:10px;
}
</style>

<script>
(function(){
'use strict';

/* ============================================================
 * State
 * ============================================================ */
const GCS_STATE = {
  config: {
    calendarUrl: '',
    calendarUrlValid: false
  },
  plan: {
    creates: 0,
    updates: 0,
    deletes: 0,
    checkedOnce: false
  },
  diffPayload: null,
  apply: {
    inProgress: false
  }
};

/* ============================================================
 * Helpers
 * ============================================================ */
function isValidIcsUrl(url) {
  return /^https?:\/\/.+\.ics(\?.*)?$/.test((url||'').trim());
}

function deriveMode() {
  if (!GCS_STATE.config.calendarUrlValid) {
    return 'setup';
  }

  if (!GCS_STATE.plan.checkedOnce) {
    return 'ready';
  }

  const total =
    GCS_STATE.plan.creates +
    GCS_STATE.plan.updates +
    GCS_STATE.plan.deletes;

  if (GCS_STATE.apply.inProgress) {
    return 'applying';
  }

  if (total === 0) {
    return 'in_sync';
  }

  if (GCS_STATE.diffPayload) {
    return 'preview';
  }

  return 'changes_detected';
}

/* ============================================================
 * Render
 * ============================================================ */
function render() {
  const mode = deriveMode();
  const status = document.getElementById('gcs-status-bar');

  status.className = 'gcs-status';

  switch (mode) {
    case 'setup':
      status.classList.add('info');
      status.textContent =
        'Google Calendar is not configured. Please enter a valid ICS URL.';
      break;

    case 'ready':
      status.classList.add('info');
      status.textContent =
        'Google Calendar configured. Ready to check for scheduler changes.';
      break;

    case 'in_sync':
      status.classList.add('success');
      status.textContent =
        'Scheduler is in sync with Google Calendar.';
      break;

    case 'changes_detected':
      status.classList.add('warning');
      status.textContent =
        `Out of sync: ${GCS_STATE.plan.creates} create(s), ` +
        `${GCS_STATE.plan.updates} update(s), ` +
        `${GCS_STATE.plan.deletes} delete(s).`;
      break;

    case 'preview':
      status.classList.add('info');
      status.textContent =
        'Review the proposed scheduler changes below.';
      break;

    case 'applying':
      status.classList.add('info');
      status.textContent =
        'Applying scheduler changes…';
      break;
  }

  toggle('gcs-setup-panel', mode === 'setup');
  toggle('gcs-preview-panel',
    mode === 'changes_detected' || mode === 'preview');
  toggle('gcs-apply-panel',
    mode === 'changes_detected' || mode === 'preview');
}

function toggle(id, show) {
  document.getElementById(id)
    .classList.toggle('hidden', !show);
}

/* ============================================================
 * Event Wiring
 * ============================================================ */
const urlInput = document.getElementById('gcs-calendar-url');
const saveBtn  = document.getElementById('gcs-save-btn');
const previewBtn = document.getElementById('gcs-preview-btn');
const applyBtn = document.getElementById('gcs-apply-btn');
const dryRunToggle = document.getElementById('gcs-dry-run-toggle');

urlInput.addEventListener('input', () => {
  GCS_STATE.config.calendarUrl = urlInput.value;
  GCS_STATE.config.calendarUrlValid = isValidIcsUrl(urlInput.value);
  saveBtn.disabled = !GCS_STATE.config.calendarUrlValid;
  render();
});

saveBtn.addEventListener('click', () => {
  fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type':'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'save',
      ics_url: urlInput.value
    })
  }).then(() => {
    GCS_STATE.plan.checkedOnce = false;
    render();
  });
});

previewBtn.addEventListener('click', () => {
  fetch('?endpoint=experimental_plan_status')
    .then(r => r.json())
    .then(d => {
      GCS_STATE.plan.checkedOnce = true;
      GCS_STATE.plan.creates = d.counts.creates;
      GCS_STATE.plan.updates = d.counts.updates;
      GCS_STATE.plan.deletes = d.counts.deletes;
      render();
    });
});

applyBtn.addEventListener('click', () => {
  GCS_STATE.apply.inProgress = true;
  render();

  let url = '?endpoint=experimental_apply';
  if (dryRunToggle.checked) url += '&dry_run=1';

  fetch(url)
    .then(() => {
      GCS_STATE.apply.inProgress = false;
      GCS_STATE.plan.checkedOnce = false;
      render();
    });
});

/* ============================================================
 * Init
 * ============================================================ */
GCS_STATE.config.calendarUrl = urlInput.value;
GCS_STATE.config.calendarUrlValid = isValidIcsUrl(urlInput.value);
saveBtn.disabled = !GCS_STATE.config.calendarUrlValid;
render();

})();
</script>
