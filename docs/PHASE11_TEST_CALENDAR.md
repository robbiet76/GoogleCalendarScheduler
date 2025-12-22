# GoogleCalendarScheduler – Phase 11 Canonical Test Events

**End Date for All Events:** January 10, 2026  
**Purpose:** Provide a stable, recurring Google Calendar test suite for deterministic Phase 11 development and regression testing.

---

## Group A – Single Events (Baseline)

### A1 – Single One-Time Playlist
- **Summary:** Playlist_A1_Single
- **Type:** Non-recurring
- **Time:** 18:00–18:30
- **Expected Result:** One FPP schedule created
- **Validates:** Basic intent → schedule mapping

---

## Group B – Recurring / Multi-Day

### B1 – Multi-Day Weekday Playlist
- **Summary:** Playlist_B1_MultiDay
- **RRULE:** Weekly, MO–FR
- **Time:** 19:00–20:00
- **Weekdays:** Monday–Friday only
- **Expected Result:** Single reusable schedule with weekday mask
- **Validates:** RRULE parsing, weekday mask correctness, intent consolidation

---

### B2 – Daily Recurring Playlist
- **Summary:** Playlist_B2_Daily
- **RRULE:** Daily
- **Time:** 21:00–21:30
- **Expected Result:** Daily schedule without duplication
- **Validates:** DAILY RRULE handling

---

## Group C – Overlap & Conflict Resolution

### C1 – Overlapping Events (Clean Window)

**Dedicated Time Window – No Other Tests Active**

#### C1-1
- **Summary:** Playlist_C1_Overlap_Early
- **RRULE:** Daily
- **Time:** 14:00–15:00

#### C1-2
- **Summary:** Playlist_C1_Overlap_Late
- **RRULE:** Daily
- **Time:** 14:30–15:30

**Expected Result**
- Both intents created
- Overlap detected
- Deterministic ordering preserved
- No interference from other test groups

**Validates**
- Overlap handling
- Deterministic diff output
- Conflict safety

---

## Group C2 – Adjacent (Non-Overlapping)

### C2-1
- **Summary:** Playlist_C2_Adjacent_Early
- **RRULE:** Daily
- **Time:** 16:00–16:30

### C2-2
- **Summary:** Playlist_C2_Adjacent_Late
- **RRULE:** Daily
- **Time:** 16:30–17:00

**Expected Result**
- Two separate schedules
- No overlap or merge
- Stable boundaries

---

## Group D – Ownership & Idempotency

### D1 – Google-Owned Schedule
- **Summary:** Playlist_D1_Owned
- **RRULE:** Daily
- **Time:** 22:00–22:15
- **Expected Result:** Managed and updated only by scheduler
- **Validates:** Ownership tagging and safe re-sync

---

## Phase 11 Notes

- All events terminate on **January 10, 2026** to cap log volume.
- YAML metadata is parsed but only enforced in Phase 11.4.
- Command targets are not required for Phase 11 success.
- This document is the **single source of truth** for expected behavior.

---
