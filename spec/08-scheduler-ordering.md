> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 08 — Scheduler Ordering Model

## Purpose

This section defines **how scheduler entries are ordered** before being written to FPP.

Ordering is not cosmetic. In FPP, **ordering determines runtime dominance** when schedules overlap. A correct ordering model is therefore required to ensure that:

- Later-intended schedules actually run
- Seasonal overrides behave correctly
- Higher-priority intent is not starved by background entries

This document defines *what ordering must do* — not how it is implemented.

---

## Fundamental Ordering Constraints

1. **Ordering is global**  \
   Scheduler entries are evaluated top-to-bottom by FPP.

2. **Earlier entries have higher priority**  \
   When two entries overlap, the one appearing earlier in the schedule takes precedence.

3. **Ordering must be deterministic**  \
   Given the same Manifest, ordering must always produce the same result.

4. **Ordering must be explainable**  \
   Every ordering decision must be traceable to an explicit rule.

---

## Managed vs Unmanaged Entries

The scheduler consists of two conceptual groups of entries:

### Unmanaged Entries

Unmanaged entries are scheduler entries that:

- Are not represented in the Manifest
- Were created manually or by another system
- Are not controlled by the calendar integration

**Ordering rules for unmanaged entries:**

- All unmanaged entries appear **before** managed entries
- Unmanaged entries maintain their **existing relative order**
- Unmanaged entries are treated as a single, immutable block
- The planner **must never reorder unmanaged entries**

> **Invariant:** Unmanaged entries always take priority over managed entries.

---

### Managed Entries

Managed entries are derived from Manifest Events and their SubEvents.

- Only managed entries participate in ordering logic
- Managed entries may be reordered relative to each other
- Managed entries are appended *after* the unmanaged block

---

## Atomic Ordering Units

### SubEvents Are Atomic

Ordering is applied at the **Manifest Event** level, not individual scheduler entries.

- A Manifest Event produces one or more SubEvents
- SubEvents **must never be reordered internally**
- SubEvents move as a single atomic unit during ordering

> **Invariant:** If any SubEvent moves, all SubEvents move together.

---

## Why Reordering Is Required

Calendar intent does not map directly to FPP execution semantics.

Examples that require reordering:

- A later-starting nightly show must override an earlier ambient playlist
- A seasonal schedule must override a year-round baseline
- A holiday exception must override its base schedule

Simple chronological ordering is insufficient.

---

## Ordering Model Overview

Ordering proceeds in **two distinct phases**:

1. **Baseline Chronological Ordering**
2. **Dominance Resolution Passes**

This ensures clarity, determinism, and correctness.

---

## Phase 1 — Baseline Chronological Ordering

Managed Manifest Events are first ordered by:

1. Start date (earlier first)
2. Daily start time (earlier first)
3. Type (playlist / command / sequence)
4. Target (lexical)

This establishes a stable and predictable baseline.

> This phase does *not* attempt to resolve conflicts.

---

## Phase 2 — Dominance Resolution

After baseline ordering, dominance rules are applied iteratively.

Dominance rules may move a Manifest Event **above** another *only if they overlap*.

### Overlap Definition

Two Manifest Events overlap if:

- Their date ranges intersect (exclusive of touching edges)
- Their day masks intersect
- Their daily time windows intersect (including overnight wrap)

If no overlap exists, **ordering must not change**.

---

## Dominance Rules (in priority order)

### Rule 1 — Later Daily Start Time Wins

If two overlapping events occur on the same day:

- The event with the **later daily start time** dominates

Rationale:
- Later schedules are intentional overrides
- Early schedules represent background layers

---

### Rule 2 — Later Calendar Start Date Wins (Same Start Time)

If two overlapping events have:

- The same daily start time
- Different calendar start dates

Then:

- The event that **starts later in the calendar** dominates

Rationale:
- Seasonal overrides must replace earlier seasons

---

### Rule 3 — Prevent Start-Time Starvation

If placing Event A above Event B would prevent Event B from ever starting at its intended first occurrence:

- Event A dominates

Rationale:
- Events must be able to start at least once

---

## Iterative Stabilization

Dominance resolution is applied repeatedly until:

- No further swaps occur, or
- A maximum pass limit is reached

The final order must be:

- Stable
- Deterministic
- Repeatable

---

## Explicitly Forbidden Heuristics

The following are **not allowed**:

- Reordering based on scheduler index
- Reordering based on creation time
- Reordering based on UID
- Random or hash-based ordering
- Provider-specific ordering rules
- Manual user ordering overrides

---

## Guarantees

- Unmanaged entries always remain first
- Managed entries never override unmanaged entries
- Manifest Events remain atomic
- Ordering decisions are deterministic
- Ordering is derived solely from Manifest semantics

---

## Non-Goals

- Allowing users to manually reorder managed entries
- Preserving historical ordering artifacts
- Optimizing for minimal diff size

Correctness always outweighs minimal change.

