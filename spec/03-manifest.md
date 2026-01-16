> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 03 — Manifest

## Purpose

The **Manifest** is the central, authoritative semantic model for the system.

It represents the *truth* about:

- What exists in the scheduler
- Why it exists
- Where it came from
- How it should be compared, ordered, reverted, and explained

All inbound (calendar → scheduler) and outbound (scheduler → calendar) flows are mediated through the Manifest. No other file or data structure is allowed to encode identity or ownership semantics.

Backwards compatibility is **explicitly not a goal**. The Manifest is free to evolve based on correct understanding.

---

## Core Principles

1. **Manifest-first architecture**  \
   The Manifest is the single source of truth. Scheduler entries and calendar events are projections.

2. **Event-centric model**  \
   Every calendar event produces exactly one Manifest Event. All execution details are derived from it.

3. **No persistence in FPP**  \
   The Manifest is *not* stored in `schedule.json`. FPP stores only executable scheduler entries.

4. **Symbolic preservation**  \
   Symbolic dates and times (Dawn, Dusk, Holidays, DatePatterns) are preserved semantically and resolved only at the FPP interface layer.

5. **Atomic execution units**  \
   Execution details are grouped into SubEvents that are always applied and ordered atomically.

---

## Manifest Event (Top-Level Unit)

A **Manifest Event** is the authoritative semantic representation of exactly one calendar event.

> **Invariant:** `1 Calendar Event → 1 Manifest Event`

Manifest Events are the unit of:

- Identity
- Diffing
- Ordering
- Apply
- Revert

```ts
ManifestEvent {
  uid: UID,
  id: string,
  hash: string,
  identity: IdentityObject,
  intent: IntentObject,
  subEvents: SubEvent[],
  ownership: OwnershipObject,
  status: StatusObject,
  provenance: ProvenanceObject,
  revert?: RevertObject
}
```

---

## UID (Provider Trace Identifier)

```ts
UID {
  provider: string, // e.g. "google", "ics", "manual"
  value: string
}
```

Rules:

- UID exists for **traceability only**
- UID is never used for equality or diffing
- UID may collide across providers
- UID may be absent for manually created Manifest Events

---

## IdentityObject (Semantic Equality)

The **IdentityObject** defines semantic equality for Manifest Events.

It answers:

> **“Are these two Manifest Events the same scheduler intent?”**

Identity is:

- Deterministic
- Provider-agnostic
- Stable across time
- Year-invariant

```ts
IdentityObject {
  type: "playlist" | "command" | "sequence",
  target: string,
  days: string,
  start_time: TimeToken,
  end_time: TimeToken,
  date_pattern: DatePattern
}
```

Rules:

- Identity excludes operational settings (e.g. stopType, repeat)
- Identity excludes resolved dates
- Identity excludes provider artifacts
- Changing any Identity field creates a *new* Manifest Event identity

---

## IntentObject (User Desire)

The **IntentObject** captures what the user expressed.

It answers:

> **“What behavior did the user intend?”**

```ts
IntentObject {
  type: "playlist" | "command" | "sequence",
  target: string,
  args?: string[],
  sequence?: number,
  enabled: boolean,
  timing: TimingObject
}
```

Rules:

- Intent may be symbolic or open-ended
- Intent may differ from Identity during preview or revert
- Intent is preserved for UI and calendar round-tripping

---

## SubEvents (Executable Decomposition)

A **SubEvent** is an executable component derived from a Manifest Event.

SubEvents exist because not all calendar intent maps cleanly to a single FPP scheduler entry.

```ts
SubEvent {
  role: "base" | "exception",
  entry: FppScheduleEntry
}
```

Rules:

- Every Manifest Event has **one or more SubEvents**
- Exactly one SubEvent has `role: "base"`
- Zero or more SubEvents may have `role: "exception"`
- SubEvents have **no independent identity**
- SubEvents are never diffed, ordered, or applied independently

---

## DatePattern (Intent-Level Date Semantics)

The Manifest stores *date intent*, not concrete dates.

```ts
DatePattern =
  | { kind: "absolute", year: number, month: number, day: number }
  | { kind: "annual_range", start_month: number, start_day: number,
                             end_month: number, end_day: number }
  | { kind: "annual_day", month: number, day: number }
  | { kind: "monthly_day", day: number }
  | { kind: "full_year" }
```

Rules:

- No sentinel dates (e.g. `0000-00-00`) are stored
- DatePattern is year-invariant
- Expansion occurs only during Apply

---

## OwnershipObject

```ts
OwnershipObject {
  managed: boolean,
  controller: "calendar" | "manual" | "unknown",
  locked: boolean
}
```

---

## StatusObject

```ts
StatusObject {
  enabled: boolean,
  deleted: boolean
}
```

---

## ProvenanceObject

```ts
ProvenanceObject {
  source: "ics",
  provider: string,
  imported_at: string // ISO-8601
}
```

---

## RevertObject (Single-Level Undo)

```ts
RevertObject {
  previous_identity: IdentityObject,
  previous_intent: IntentObject,
  reverted_at: string // ISO-8601
}
```

Rules:

- Revert restores the last applied semantic state
- Revert is non-recursive
- Revert does not imply calendar mutation

---

## Guarantees

- Manifest Events are deterministic
- SubEvents are atomic
- Scheduler state is reproducible from the Manifest alone

