> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 07 — Events & SubEvents (Atomic Scheduling Units)

## Purpose

This section defines how **calendar events**, **manifest events**, and **FPP scheduler entries** relate to one another.

It establishes the atomic execution model used throughout the system and replaces the earlier term *bundle* with clearer, domain-aligned language.

The canonical relationship is:

```
1 Calendar Event
        ↓
1 Manifest Event
        ↓
{ SubEvents }
        ↓
FPP Scheduler Entries
```

---

## Core Definitions

### Calendar Event

A **Calendar Event** is a provider-level object (ICS, Google, etc.) that expresses user intent.

Rules:
- Calendar events are **inputs only**
- They may be symbolic, repeating, incomplete, or provider-specific
- They are never executed directly

---

### Manifest Event

A **Manifest Event** is the authoritative semantic representation of exactly one calendar event.

> **Invariant:** `1 Calendar Event → 1 Manifest Event`

Manifest Events are the unit of:
- Identity
- Diffing
- Ordering
- Apply
- Revert

A Manifest Event contains:
- **One IdentityObject** (semantic equality)
- **One IntentObject** (user desire)
- **One or more SubEvents** (executable decomposition)
- Ownership, status, provenance, and revert metadata

---

### SubEvents

A **SubEvent** is a deterministic, executable component derived from a Manifest Event.

SubEvents exist because:
- Not all scheduling intent can be expressed as a single FPP scheduler entry
- Exceptions, unsupported day masks, or date patterns require decomposition

Rules:
- SubEvents **always live inside** the Manifest Event
- SubEvents **do not have independent identity**
- **All SubEvents inherit the Manifest Event’s IdentityObject**
- SubEvents are never diffed, ordered, or reverted independently
- SubEvents are never persisted outside the Manifest

---

## SubEvent Roles

Every Manifest Event contains one or more SubEvents.

Exactly one SubEvent must have:

```ts
role: "base"
```

Zero or more SubEvents may have:

```ts
role: "exception"
```

---

### Base SubEvent

The **base SubEvent** represents the primary, continuous scheduling intent.

Characteristics:
- Always present
- Represents the dominant schedule
- Ordered last *within* the Manifest Event

---

### Exception SubEvents

**Exception SubEvents** represent deviations from the base behavior.

Examples:
- Date exclusions
- Unsupported day masks
- Calendar exception dates

Characteristics:
- Zero or more per Manifest Event
- Ordered *above* the base SubEvent
- Never reordered internally

---

## Identity Model Clarification

- **IdentityObject exists at the Manifest Event level**
- **SubEvents never have identity**
- Identity comparison, hashing, and reconciliation operate on Manifest Events only

This guarantees:
- Stable diffing
- Predictable ordering
- Safe revert
- No semantic fragmentation

---

## DatePattern Semantics (FPP-Aligned)

SubEvents use intent-level date patterns that align with FPP’s native scheduling model.

Each SubEvent contains:

```ts
start_date: DatePattern
end_date:   DatePattern
```

### DatePattern Rules

- `YYYY-MM-DD` → absolute date
- `0000` year → applies every year
- `00` month → applies every month
- Any field set to zero is treated as a wildcard by FPP

DatePattern is:
- Preserved verbatim in the Manifest
- Never resolved during planning
- Expanded only by the FPP semantics layer

### Examples

| Start Date | End Date | Meaning |
|-----------|----------|--------|
| `0000-02-14` | `0000-02-14` | Every February 14 |
| `0000-00-01` | `0000-00-07` | First week of every month |
| `0000-01-01` | `0000-12-31` | Entire year, every year |

---

## Atomicity Guarantees

SubEvents are **atomic as a group**.

This means:
- A Manifest Event moves as a unit during global ordering
- Internal SubEvent order is fixed and deterministic
- No SubEvent may be inserted, removed, or reordered independently

> There is no such thing as a partially applied Manifest Event.

---

## Ordering Relationship

Ordering operates at **two distinct levels**:

### 1. Internal Ordering (Within a Manifest Event)

Fixed and deterministic:

```
[ Exception SubEvents ]
        ↓
[ Base SubEvent ]
```

This ordering never changes.

---

### 2. Global Ordering (Across Manifest Events)

Rules:
- Manifest Events are ordered relative to one another
- SubEvents never participate directly
- Ordering rules are defined in **08 — Scheduler Ordering Model**

---

## Invariants

- 1 Calendar Event → 1 Manifest Event
- Every Manifest Event has ≥ 1 SubEvent
- Exactly one SubEvent has `role: "base"`
- SubEvents share the Manifest Event’s identity
- SubEvents are immutable once derived
- SubEvents never escape the Manifest

