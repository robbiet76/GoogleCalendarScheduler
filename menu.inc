<?php
/**
 * GoogleCalendarScheduler menu entry
 *
 * Purpose:
 * - Registers the plugin link in the FPP web UI sidebar
 *
 * Behavior:
 * - Render-only
 * - Executes ONLY when the active menu is "content"
 * - Produces a single <li> entry linking to content.php
 *
 * HARD RULES:
 * - No logic
 * - No side effects
 * - No assumptions about plugin state
 */

if ($menu !== 'content') {
    return;
}
?>
<li>
  <a href="plugin.php?plugin=GoogleCalendarScheduler&page=content.php">
    Google Calendar Scheduler
  </a>
</li>
