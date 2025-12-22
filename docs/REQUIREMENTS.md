# Google Calendar Scheduler Integration for FPP
## Requirements Document (v1.5)

---

## 1. Purpose

The purpose of this project is to create a **Falcon Player (FPP) plugin** that allows a **Google Calendar** to function as the **primary scheduling interface** for FPP.

The plugin shall:
- Read scheduling data from a Google Calendar
- Translate calendar events into **native FPP scheduler entries**
- Preserve **full parity** with the built-in FPP scheduler
- Avoid direct playback or execution logic

---

## 2. Scope

### 2.1 In Scope
- Reading calendar data via **Google Calendar ICS**
- Parsing calendar events and metadata
- Creating, updating, and deleting FPP scheduler entries
- Supporting all FPP scheduler target types
- Maintaining scheduler state across reboots

### 2.2 Out of Scope
- Replacing the FPP scheduler engine
- Real-time triggering outside the scheduler
- Direct playback control
- Calendar write-back or bidirectional sync
- OAuth-based Google Calendar access (v1)

---

## 3. Target Environment

- **Application:** Falcon Player (FPP)
- **Plugin Type:** PHP-based FPP plugin
- **Scheduler Engine:** Native FPP scheduler
- **OS:** Linux-based FPP platforms

---

## 4. Architectural Principles

1. Google Calendar is the **authoritative scheduling source**
2. FPP Scheduler is the **execution engine**
3. Plugin is **idempotent**
4. Manual schedules may coexist
5. Plugin-managed schedules are clearly tagged
6. Scheduler behavior must match native UI behavior exactly
7. All scheduling uses the **FPP system timezone**

---

## 5. Calendar Integration

### 5.1 Calendar Source
- Google Calendar accessed via **private ICS URL**
- Calendar events are interpreted in **FPP system timezone**

### 5.2 Event Semantics
- Each calendar event represents **one scheduler entry**
- `DTSTART` defines schedule start
- `DTEND` defines stop time (where applicable)
- All-day events are ignored (logged)

---

## 6. Scheduler Target Types

The plugin shall support all FPP scheduler targets:

### 6.1 Playlist
- Starts at event start time
- Stops based on configured stop behavior
- Optional repeat behavior

### 6.2 Sequence
- Starts at event start time
- Stops based on configured stop behavior
- Optional repeat behavior

### 6.3 Command
- Triggered at event start time
- Includes:
  - Built-in commands
  - Plugin commands
  - Shell commands
  - Command Presets
- Optional arguments

---

## 7. Target Resolution

### 7.1 Source of Target Name
- The **Google Calendar event title** defines the scheduler target
- YAML metadata **must not define the target**

### 7.2 Extension Handling
- Event titles must **not require file extensions**
- Required extensions are appended automatically

| Schedule Type | Extension Handling |
|--------------|--------------------|
| Playlist | No extension |
| Sequence | `.fseq` appended if missing |
| Command | No extension |

---

## 8. Event Metadata Format (YAML)

### 8.1 Location
- YAML metadata is stored in the **event description**

### 8.2 Format
- YAML format
- Root key: `fpp`
- YAML defines **how**, not **what**, the scheduler runs

### 8.3 Base Schema

```yaml
fpp:
  type: playlist | sequence | command
  stop:
    type: graceful | graceful_loop | hard
  repeat: none | immediate | 5 | 10 | 15 | 20 | 30 | 60
```

---

## 9. Defaults & Auto-Detection

If YAML is missing or incomplete:

- Auto-detection order:
  1. Playlist
  2. Sequence
  3. Command
- Default stop type: `graceful`
- Default repeat: `none`

---

## 10. Recurring Events

- Google Calendar recurring events (`RRULE`) shall be expanded
- Expansion is limited to the **FPP scheduler window**
- Each occurrence becomes a discrete scheduler entry

---

## 11. Scheduler Entry Management

### 11.1 Tagging
All plugin-managed entries shall include:

```
managed-by:google-calendar
```

Optionally:
```
event-id:<calendar-uid>
```

### 11.2 Safety
- Only plugin-tagged schedules may be modified or deleted
- Manual schedules must never be altered

---

## 12. Scheduler Priority & Conflict Resolution

### 12.1 Manual vs Plugin Schedules
- **Manual FPP scheduler entries always take precedence** over plugin-managed entries
- Plugin-managed entries must **never override, reorder, or delete** manual schedules

### 12.2 Ordering Strategy
- Plugin-managed schedules are always inserted **after** all manual schedules
- Manual schedule order is preserved exactly as defined by the user

### 12.3 Overlapping Plugin Schedules
- When two or more plugin-managed schedules overlap:
  - Priority is determined by **start time**
  - Later-starting schedules take precedence over earlier-starting schedules
  - This matches native FPP scheduler behavior

### 12.4 Conflict Resolution
- No explicit conflict detection or suppression is performed
- FPP’s native scheduler logic determines runtime behavior
- Plugin does not attempt to:
  - Pause, resume, or restart schedules
  - Inject stop commands
  - Reorder schedules dynamically

---

## 13. Synchronization Behavior

### 13.1 Sync Process
1. Fetch calendar
2. Parse events
3. Expand recurrences
4. Resolve targets
5. Validate targets
6. Load existing schedules
7. Diff desired vs existing
8. Apply changes via FPP API
9. Log results

### 13.2 Sync Modes
- Scheduled (every 10 minutes)
- Manual (“Sync Now”)
- Startup

### 13.3 Idempotency
Repeated syncs must not create duplicate schedules.

---

## 14. Validation & Error Handling

- Invalid YAML → log and skip
- Missing or invalid target → log and skip
- Invalid stop or repeat value → log and skip
- Calendar unavailable → retain last valid schedule

---

## 15. Logging

- Log file:
  ```
  /home/fpp/media/logs/google-calendar-scheduler.log
  ```
- Log levels:
  - INFO
  - WARNING
  - ERROR
  - DEBUG

---

## 16. Plugin Setup GUI (v1 Scope)

The plugin GUI shall be **minimal**, exposing only essential controls:

- Google Calendar ICS URL
- Dry-Run Mode (temporary)
- Last Sync Status
- Sync Now
- Clear Plugin-Managed Schedules
- Link to Documentation

All other behavior is handled on the backend.

---

## 17. Configuration Storage

### 17.1 Location
```
/home/fpp/media/config/plugin.googleCalendarScheduler.json
```

### 17.2 Schema (v1)

```json
{
  "version": 1,
  "calendar": {
    "ics_url": ""
  },
  "runtime": {
    "dry_run": false
  },
  "sync": {
    "last_run": null,
    "last_status": "never",
    "last_error": null,
    "events_processed": 0,
    "schedules_added": 0,
    "schedules_updated": 0,
    "schedules_removed": 0
  }
}
```

---

## 18. Non-Functional Requirements

- Must not block FPP startup
- Must survive reboots
- Must not degrade scheduler UI performance
- Must be compatible with current and future FPP releases

---

## 19. Future Enhancements (Out of Scope)

- OAuth calendar access
- Multiple calendars
- Advanced GUI configuration
- Bidirectional sync
- Calendar preview UI

---

## 20. Acceptance Criteria

The solution is complete when:
- Calendar events appear as native FPP schedules
- Manual schedules always take precedence
- Overlapping calendar schedules behave consistently with FPP rules
- Plugin survives reboot and resync
- Logs clearly show sync activity

---

