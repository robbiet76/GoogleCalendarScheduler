<?php
declare(strict_types=1);

/**
 * GoogleCalendarScheduler — UI View (Render-Only)
 *
 * PURPOSE:
 * - Render the plugin UI
 * - Display current configuration state
 * - Provide HTML forms for user-initiated actions
 *
 * IMPORTANT BOUNDARIES:
 * - This file does NOT perform scheduler logic
 * - This file does NOT mutate scheduler.json directly
 * - POST handling and side effects are handled earlier in content.php
 *
 * DATA CONTRACT:
 * - `$cfg` is expected to be injected by the controller section
 * - If `$cfg` is missing (defensive fallback), it is loaded read-only
 *
 * DESIGN GOAL:
 * - Keep UI dumb
 * - Keep behavior elsewhere
 */

// Defensive fallback — normally provided by controller logic
if (!isset($cfg) || !is_array($cfg)) {
    $cfg = Config::load();
}
?>

<h1>Google Calendar Scheduler</h1>

<p>
    Status:
    <b><?php echo htmlspecialchars($cfg['sync']['last_status'] ?? 'Unknown'); ?></b>
</p>

<hr/>

<!-- ========================================================= -->
<!-- Configuration Form                                       -->
<!-- ========================================================= -->
<form method="post"
      action="plugin.php?plugin=GoogleCalendarScheduler&page=content.php">

    <input type="hidden" name="action" value="save"/>

    <label for="ics_url"><b>Google Calendar ICS URL</b></label><br/>
    <input
        id="ics_url"
        type="text"
        name="ics_url"
        style="width:95%;"
        value="<?php echo htmlspecialchars($cfg['calendar']['ics_url'] ?? ''); ?>"
    />
    <br/><br/>

    <label>
        <input type="checkbox"
               name="dry_run"
               value="1"
            <?php echo !empty($cfg['runtime']['dry_run']) ? 'checked' : ''; ?>
        />
        Dry-run (no scheduler writes)
    </label>

    <br/><br/>
    <button type="submit">Save Settings</button>
</form>

<hr/>

<!-- ========================================================= -->
<!-- Manual Sync Trigger (Dry-Run Only)                        -->
<!-- ========================================================= -->
<form method="post"
      action="plugin.php?plugin=GoogleCalendarScheduler&page=content.php">

    <input type="hidden" name="action" value="sync"/>
    <button type="submit">Sync Now (Dry-run)</button>
</form>
