> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 16 — Non-Goals & Explicit Exclusions

## Purpose

This section defines what the system **intentionally does not do**.

These exclusions are **design decisions**, not missing features.  
They protect system clarity, prevent scope creep, and preserve determinism.

Anything listed here must **not** be “fixed” later without revisiting the core architecture and updating the behavioral specification.

---

## 1. No Backward Compatibility Guarantees

- The system does **not** guarantee backward compatibility with:
  - Earlier development iterations
  - Experimental schema designs
  - Internal prototypes

This is an **unreleased plugin**.

Correctness, clarity, and architectural soundness take priority over preserving earlier approaches.

---

## 2. No Implicit Data Repair

The system does **not**:

- Repair malformed calendar events
- Guess missing intent
- Normalize broken identities
- Auto-correct invalid schedules

Invalid input results in:
- Explicit failure, or
- Explicit exclusion with surfaced diagnostics

Silent correction is forbidden.

---

## 3. No Heuristic-Based Guessing

The system does **not** infer intent from:

- Event names
- Playlist naming conventions
- Time ranges alone
- Past behavior

All behavior must be:
- Explicit
- Deterministic
- Traceable through the Manifest

---

## 4. No schedule.json Extensions

The system does **not**:

- Add metadata fields to `schedule.json`
- Store manifest data in the FPP scheduler
- Persist identity outside the Manifest

FPP scheduler entries remain **pure FPP-native structures**.

---

## 5. No Direct Calendar-to-FPP Writes

The system does **not**:

- Write calendar data directly into FPP
- Bypass the Manifest
- Apply changes without diff reconciliation

All changes must flow through:

Calendar → Manifest → Planner → Diff → Apply

---

## 6. No Multi-Manifest State

The system does **not** support:

- Multiple active manifests
- Partial manifests
- Per-calendar manifests

There is **one authoritative manifest** at all times.

---

## 7. No UI-Driven Logic

The UI does **not**:

- Decide scheduling rules
- Modify ordering
- Influence identity
- Perform reconciliation

The UI is a **control surface**, not a logic layer.

---

## 8. No Scheduler Optimization Beyond Ordering Rules

The system does **not**:

- Optimize schedule length
- Collapse overlapping entries
- Merge compatible schedules

Scheduler output reflects **intent**, not optimization.

---

## 9. No Automatic Conflict Resolution

If two calendar events create conflicting intent:

- Both are preserved
- Both are surfaced
- Ordering rules apply deterministically

The system does **not** auto-resolve conflicts by deletion or merging.

---

## 10. No Hidden State

The system does **not** maintain:

- Hidden caches
- Implicit memory of prior runs
- Undocumented state transitions

All state is explicit and observable.

---

## 11. No Provider Lock-In

While Google Calendar is supported initially, the system does **not**:

- Encode Google-specific assumptions into core logic
- Require Google UIDs outside provider adapters

Calendar providers are pluggable by design.

---

## Summary

This system intentionally avoids:

- Guessing
- Repairing
- Auto-correcting
- Silently adapting

Its strength is **explicitness**, **determinism**, and **traceability**.

Anything outside that scope is a non-goal by design.
