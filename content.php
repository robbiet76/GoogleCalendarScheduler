<?php
/**
 * GoogleCalendarScheduler
 * content.php
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

// Experimental scaffolding (explicitly required)
require_once __DIR__ . '/src/experimental/ExecutionContext.php';
require_once __DIR__ . '/src/experimental/ScopedLogger.php';
require_once __DIR__ . '/src/experimental/ExecutionController.php';
require_once __DIR__ . '/src/experimental/HealthProbe.php';
require_once __DIR__ . '/src/experimental/CalendarReader.php';
require_once __DIR__ . '/src/experimental/DiffPreviewer.php';

$cfg = GcsConfig::load();

/*
 * --------------------------------------------------------------------
 * EXPERIMENTAL ENDPOINTS (11.7 / 11.8)
 * --------------------------------------------------------------------
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['endpoint'])
    && $_GET['endpoint'] === 'experimental_diff'
) {
    header('Content-Type: application/json');

    if (empty($cfg['experimental']['enabled'])) {
        echo json_encode([
            'ok'    => false,
            'error' => 'experimental_disabled',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    try {
        $diff = DiffPreviewer::preview($cfg);

        echo json_encode([
            'ok'   => true,
            'diff' => $diff,
        ], JSON_PRETTY_PRINT);
        exit;

    } catch (Throwable $e) {
        echo json_encode([
            'ok'    => false,
            'error' => 'experimental_error',
            'msg'   => $e->getMessage(),
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

/*
 * --------------------------------------------------------------------
 * POST handling (normal UI flow)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    try {
        if ($action === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = isset($_POST['dry_run']);
            GcsConfig::save($cfg);
            $cfg = GcsConfig::load();
        }

        if ($action === 'sync') {
            $dryRun = !empty($cfg['runtime']['dry_run']);
            $horizonDays = GcsFppSchedulerHorizon::getDays();
            $runner = new GcsSchedulerRunner($cfg, $horizonDays, $dryRun);
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

        <button type="button" class="buttons" id="gcs-preview-btn" disabled>
            Preview Changes
        </button>

        <div id="gcs-diff-results" style="margin-top:10px;"></div>
    </div>

    <script>
    (function () {
        'use strict';

        function extractJsonObjectWithOk(text) {
            var matches = text.match(/\{[\s\S]*?\}/g);
            if (!matches) return null;

            for (var i = matches.length - 1; i >= 0; i--) {
                try {
                    var obj = JSON.parse(matches[i]);
                    if (obj && typeof obj.ok === 'boolean') {
                        return obj;
                    }
                } catch (e) {}
            }
            return null;
        }

        function countArray(v) {
            return (v && Object.prototype.toString.call(v) === '[object Array]') ? v.length : 0;
        }

        function onReady() {
            var btn = document.getElementById('gcs-preview-btn');
            var results = document.getElementById('gcs-diff-results');
            if (!btn || !results) return;

            btn.disabled = false;

            btn.addEventListener('click', function () {
                btn.disabled = true;
                results.textContent = 'Fetching diff preview (read-only)…';

                var url = new URL(window.location.href);
                url.searchParams.set('endpoint', 'experimental_diff');

                fetch(url.toString(), { credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (text) {
                        var data = extractJsonObjectWithOk(text);

                        if (!data) {
                            results.textContent = 'Unable to parse diff response from FPP.';
                            return;
                        }

                        if (data.ok !== true) {
                            if (data.error === 'experimental_disabled') {
                                results.textContent =
                                    'Experimental diff preview is currently disabled.';
                                return;
                            }
                            results.textContent =
                                'Diff preview error: ' + (data.error || 'unknown');
                            return;
                        }

                        var diff = data.diff || {};
                        var creates = countArray(diff.creates);
                        var updates = countArray(diff.updates);
                        var deletes = countArray(diff.deletes);

                        results.innerHTML =
                            '<strong>Creates:</strong> ' + creates + '<br>' +
                            '<strong>Updates:</strong> ' + updates + '<br>' +
                            '<strong>Deletes:</strong> ' + deletes + '<br>' +
                            '<em>Read-only preview. No changes applied.</em>';
                    })
                    .catch(function (e) {
                        results.textContent = 'Network error: ' + e.message;
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', onReady);
        } else {
            onReady();
        }
    })();
    </script>
</div>
