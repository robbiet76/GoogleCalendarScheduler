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

        <div id="gcs-diff-results" style="margin-top:12px;"></div>
    </div>

    <style>
        .gcs-diff-badges {
            display: flex;
            gap: 10px;
            margin: 8px 0;
            flex-wrap: wrap;
        }
        .gcs-badge {
            padding: 6px 10px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 0.9em;
        }
        .gcs-badge-create { background:#e6f4ea; color:#1e7e34; }
        .gcs-badge-update { background:#fff3cd; color:#856404; }
        .gcs-badge-delete { background:#f8d7da; color:#721c24; }

        .gcs-readonly-note {
            margin-top: 6px;
            font-style: italic;
            color: #555;
        }

        .gcs-section {
            margin-top: 10px;
            border-top: 1px solid #ddd;
            padding-top: 6px;
        }
        .gcs-section h4 {
            cursor: pointer;
            user-select: none;
            margin: 6px 0;
        }
        .gcs-section ul {
            margin: 6px 0 6px 18px;
        }
        .gcs-hidden {
            display: none;
        }

        .gcs-empty-state {
            padding: 10px;
            background: #eef5ff;
            border: 1px solid #cfe2ff;
            border-radius: 6px;
            color: #084298;
            font-weight: bold;
        }
    </style>

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

        function isArray(v) {
            return Object.prototype.toString.call(v) === '[object Array]';
        }

        function renderEmptyState(results) {
            results.innerHTML = '';
            var box = document.createElement('div');
            box.className = 'gcs-empty-state';
            box.textContent =
                'No scheduler changes detected. The calendar is already in sync.';
            results.appendChild(box);
        }

        function renderSection(parent, title, items) {
            if (!isArray(items) || items.length === 0) return;

            var section = document.createElement('div');
            section.className = 'gcs-section';

            var header = document.createElement('h4');
            header.textContent = title + ' (' + items.length + ')';
            section.appendChild(header);

            var list = document.createElement('ul');
            list.className = 'gcs-hidden';

            for (var i = 0; i < items.length; i++) {
                var li = document.createElement('li');
                li.textContent =
                    (typeof items[i] === 'string')
                        ? items[i]
                        : (items[i].name || items[i].title || items[i].id || JSON.stringify(items[i]));
                list.appendChild(li);
            }

            header.addEventListener('click', function () {
                list.className = list.className === 'gcs-hidden' ? '' : 'gcs-hidden';
            });

            section.appendChild(list);
            parent.appendChild(section);
        }

        function renderSummaryAndDetails(results, diff) {
            var creates = isArray(diff.creates) ? diff.creates.length : 0;
            var updates = isArray(diff.updates) ? diff.updates.length : 0;
            var deletes = isArray(diff.deletes) ? diff.deletes.length : 0;

            if (creates === 0 && updates === 0 && deletes === 0) {
                renderEmptyState(results);
                return;
            }

            results.innerHTML = '';

            var badges = document.createElement('div');
            badges.className = 'gcs-diff-badges';

            var c = document.createElement('span');
            c.className = 'gcs-badge gcs-badge-create';
            c.textContent = '+ ' + creates + ' Creates';
            badges.appendChild(c);

            var u = document.createElement('span');
            u.className = 'gcs-badge gcs-badge-update';
            u.textContent = '~ ' + updates + ' Updates';
            badges.appendChild(u);

            var d = document.createElement('span');
            d.className = 'gcs-badge gcs-badge-delete';
            d.textContent = '− ' + deletes + ' Deletes';
            badges.appendChild(d);

            results.appendChild(badges);

            var note = document.createElement('div');
            note.className = 'gcs-readonly-note';
            note.textContent = 'Read-only preview. No changes applied.';
            results.appendChild(note);

            renderSection(results, 'Creates', diff.creates);
            renderSection(results, 'Updates', diff.updates);
            renderSection(results, 'Deletes', diff.deletes);
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

                        renderSummaryAndDetails(results, data.diff || {});
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
