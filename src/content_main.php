<?php
/**
 * Render-only UI.
 * content.php handles POST and provides $cfg.
 */

if (!isset($cfg) || !is_array($cfg)) {
    $cfg = GcsConfig::load();
}
?>

<h1>Google Calendar Scheduler</h1>

<p>
    Status:
    <b><?php echo htmlspecialchars($cfg['sync']['last_status'] ?? 'Unknown'); ?></b>
</p>

<hr/>

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
        <input type="checkbox" name="dry_run" value="1"
            <?php echo !empty($cfg['runtime']['dry_run']) ? 'checked' : ''; ?>
        />
        Dry-run (no scheduler writes)
    </label>

    <br/><br/>
    <button type="submit">Save Settings</button>
</form>

<hr/>

<form method="post"
      action="plugin.php?plugin=GoogleCalendarScheduler&page=content.php">

    <input type="hidden" name="action" value="sync"/>
    <button type="submit">Sync Now (Dry-run)</button>
</form>
