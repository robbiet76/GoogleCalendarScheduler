
# Google Calendar Scheduler – Architecture

## Overview

GoogleCalendarScheduler (GCS) integrates **FPP schedules**, **Google Calendar events**, and an internal **manifest layer**
to provide a controlled, auditable scheduling workflow.

This system is **not a bidirectional sync engine**.
It is a **planner + applier** architecture with explicit ownership boundaries.

---

## Core Systems

### 1. FPP (schedule.json)
- Source of truth for **what is currently running**
- Can contain:
  - Legacy entries (no manifest)
  - Managed entries (manifest-attached)
- Edited by:
  - User directly (FPP UI)
  - GCS Apply step

### 2. Google Calendar
- Source of truth for **user intent**
- Read-only from GCS perspective
- Writes require **manual ICS export/import**
- Events may or may not correspond to adopted FPP entries

### 3. Manifest Layer
- Glue between Google events and FPP entries
- Stores identity + semantic intent
- Enables safe comparison, update, and no-op detection

---

## Key Architectural Rules

- **UID is optional**
- Identity matching happens before semantic comparison
- No logic assumes full adoption
- Planner never writes
- Apply never decides

---

## Key Classes

| Layer | Class |
|-----|------|
| Planner | SchedulerPlanner |
| Identity | ManifestIdentity |
| Diff | SchedulerDiff |
| Compare | SchedulerComparator |
| Apply | SchedulerApply |
| Output | PreviewFormatter |

---

## Anti-Goals

- No live Google writes
- No silent adoption
- No implicit ownership inference

```mermaid
sequenceDiagram
    participant User
    participant UI as GCS UI
    participant Planner as SchedulerPlanner
    participant Manifest as Manifest Store
    participant FPP as FPP schedule.json
    participant GC as Google Calendar (ICS)

    User->>UI: Configure Google Calendar URL
    UI->>Planner: Build desired intents
    Planner->>GC: Read calendar events (ICS)
    Planner->>Manifest: Load manifest state
    Planner->>FPP: Read schedule.json
    Planner->>Planner: Match identities & semantics
    Planner-->>UI: PreviewResult (create/update/no-op)
  ```