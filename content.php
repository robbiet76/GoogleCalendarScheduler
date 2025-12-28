<?php
declare(strict_types=1);

/**
 * GoogleCalendarScheduler
 * content.php (Phase 19.3 – config always visible)
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
    GcsConfig::save($cfg);
    clearstatcache();
    $cfg = GcsConfig::load();
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
            echo json_encode([
                'ok'   => true,
                'diff' => SchedulerPlanner::plan($cfg),
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

  <!-- Status Bar (ALWAYS visible) -->
  <div id="gcs-status-bar" class="gcs-status info"></div>

  <!-- Configuration Panel (ALWAYS visible) -->
  <div id="gcs-setup-panel" class="gcs-panel">
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
      <button id="gcs-preview-btn">Preview / Refresh</button>
    </div>
  </div>

  <!-- Apply Panel -->
  <div id="gcs-apply-panel" class="gcs-panel hidden">
    <h3>Apply Scheduler Changes</h3>

    <label>
      <input type="checkbox" id="gcs-dry-run-toggle">
      Dry-run (preview only — no scheduler writes)
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
const STATE = {
  calendarValid: false,
  checkedOnce: false,
  counts: { creates:0, updates:0, deletes:0 },
  diff: null,
  applying: false
};

/* ============================================================
 * Helpers
 * ============================================================ */
function isValidIcsUrl(url) {
  return /^https?:\/\/.+\.ics(\?.*)?$/.test((url||'').trim());
}

function mode() {
  if (!STATE.calendarValid) return 'setup';
  if (!STATE.checkedOnce) return 'ready';

  const t = STATE.counts.creates + STATE.counts.updates + STATE.counts.deletes;
  return t === 0 ? 'in_sync' : 'changes';
}

/* ============================================================
 * Render
 * ============================================================ */
function render() {
  const m = mode();
  const bar = document.getElementById('gcs-status-bar');

  bar.className = 'gcs-status';

  if (m === 'setup') {
    bar.classList.add('info');
    bar.textContent = 'Google Calendar is not configured. Enter a valid ICS URL.';
  } else if (m === 'ready') {
    bar.classList.add('info');
    bar.textContent = 'Google Calendar configured. Ready to check for scheduler changes.';
  } else if (m === 'in_sync') {
    bar.classList.add('success');
    bar.textContent = 'Scheduler is in sync with Google Calendar.';
  } else {
    bar.classList.add('warning');
    bar.textContent =
      `Out of sync: ${STATE.counts.creates} create(s), ` +
      `${STATE.counts.updates} update(s), ` +
      `${STATE.counts.deletes} delete(s).`;
  }

  toggle('gcs-preview-panel', m !== 'setup');
  toggle('gcs-apply-panel', m === 'changes');
}

function toggle(id, show) {
  document.getElementById(id).classList.toggle('hidden', !show);
}

/* ============================================================
 * Events
 * ============================================================ */
const urlInput = document.getElementById('gcs-calendar-url');
const saveBtn  = document.getElementById('gcs-save-btn');
const previewBtn = document.getElementById('gcs-preview-btn');
const applyBtn = document.getElementById('gcs-apply-btn');
const dryRun = document.getElementById('gcs-dry-run-toggle');

urlInput.addEventListener('input', () => {
  STATE.calendarValid = isValidIcsUrl(urlInput.value);
  saveBtn.disabled = !STATE.calendarValid;
  render();
});

saveBtn.addEventListener('click', () => {
  fetch(window.location.href, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({ action:'save', ics_url:urlInput.value })
  }).then(() => {
    STATE.checkedOnce = false;
    render();
  });
});

previewBtn.addEventListener('click', () => {
  fetch('?endpoint=experimental_plan_status')
    .then(r => r.json())
    .then(d => {
      STATE.checkedOnce = true;
      STATE.counts = d.counts;
      render();
    });
});

applyBtn.addEventListener('click', () => {
  let url='?endpoint=experimental_apply';
  if (dryRun.checked) url+='&dry_run=1';

  STATE.applying=true;
  render();

  fetch(url).then(() => {
    STATE.checkedOnce=false;
    STATE.applying=false;
    render();
  });
});

/* ============================================================
 * Init
 * ============================================================ */
STATE.calendarValid = isValidIcsUrl(urlInput.value);
saveBtn.disabled = !STATE.calendarValid;
render();

})();
</script>
