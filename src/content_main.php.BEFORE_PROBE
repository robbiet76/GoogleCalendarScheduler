<?php
require_once __DIR__ . '/bootstrap.php';

$cfg = GcsConfig::load();
?>
<h1>Google Calendar Scheduler</h1>

<p>Status: <b><?php echo htmlspecialchars($cfg['sync']['last_status']); ?></b></p>

<form method="post"
  action="plugin.php?plugin=GoogleCalendarScheduler&page=src/api_main.php">
  <input type="hidden" name="action" value="save" />

  <label>Google Calendar ICS URL</label><br/>
  <input type="text" name="ics_url" style="width:90%;"
    value="<?php echo htmlspecialchars($cfg['calendar']['ics_url']); ?>" /><br/><br/>

  <label>
    <input type="checkbox" name="dry_run" value="1"
      <?php echo $cfg['runtime']['dry_run'] ? 'checked' : ''; ?> />
    Dry-run (no scheduler writes)
  </label><br/><br/>

  <button type="submit">Save Settings</button>
</form>

<form method="post"
  action="plugin.php?plugin=GoogleCalendarScheduler&page=src/api_main.php"
  style="margin-top:15px;">
  <input type="hidden" name="action" value="sync" />
  <button type="submit">Sync Now (Dry-run)</button>
</form>
