> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 04 — Manifest Identity Model

## Purpose

The **Manifest Identity Model** defines how semantic equality is determined within the system.

It answers the single most important comparison question:

> **“Are these two entries the same scheduler intent?”**

Identity is the foundation for:
- Diffing (create / update / delete)
- De-duplication
- Multi-event convergence
- Long-term schedule stability

If identity is wrong, *everything downstream is wrong*.

---

## Core Principles

1. **Semantic, not operational**  
   Identity represents *meaning*, not execution details.

2. **Provider-agnostic**  
   Identity must not depend on Google, ICS quirks, or FPP internals.

3. **Stable across time**  
   Identity must remain stable year-over-year, even when concrete dates change.

4. **Derived, never edited**  
   Identity is derived from intent + semantics. It is never user-authored.

5. **Deterministic and canonical**  
   The same inputs must always produce the same identity hash.

---

## What Identity Is

Identity is a **canonical semantic signature** of a scheduler entry.

It describes:
- *What* runs
- *When* it runs (semantically)
- *On which days*

It does **not** describe:
- How FPP executes it
- Whether it is enabled today
- How it is ordered relative to others

---

## IdentityObject (Canonical)

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

### Field Semantics

- **type**  
  Scheduler action category. Sequence is a first-class type and must not be folded into playlist.

- **target**  
  Playlist name, command name, or sequence identifier.

- **days**  
  Canonical day mask (e.g. `SuMoTuWeThFrSa`). Always normalized.

- **start_time / end_time**  
  Symbolic or absolute time intent. Never resolved inside identity.

- **date_pattern**  
  Structured date intent (annual, monthly, full-year, symbolic, etc.).

---

## Explicitly Excluded from Identity

The following fields **must never** participate in identity:

- `stopType`
- `repeat`
- `enabled`
- `sequence number`
- guard dates
- resolved dates
- provider UID
- calendar metadata
- UI state
- ordering position

### Why?

Including these would:
- Cause unnecessary churn
- Break year-over-year stability
- Tie identity to FPP implementation details
- Make future refactors impossible

Identity answers *“what is this?”*, not *“how does FPP run it?”*.

---

## UID vs Identity

| Concept | Purpose |
|------|--------|
| **UID** | Provider traceability only |
| **Identity** | Semantic equality |

Rules:
- UID is never used for equality
- UID may change without affecting identity
- Multiple UIDs may map to one identity

---

## Hashing Rules

Each IdentityObject produces:
- a **canonical string**
- a **stable hash**

### Canonicalization Requirements

Before hashing:
- Fields are ordered deterministically
- Day masks are normalized
- DatePattern is serialized structurally
- TimeTokens are serialized symbolically

Two IdentityObjects that are semantically equal **must hash identically**.

---

## Multi-Event → Single Identity

Multiple calendar events may intentionally converge to one identity.

Examples:
- Multiple holidays mapping to the same seasonal schedule
- Duplicate provider events

Rules:
- All such entries share the same identity hash
- Provenance records multiple sources
- Deletion requires removal of all contributing intents

---

## Identity Stability Guarantees

Identity must remain stable across:

- Year boundaries
- Calendar provider changes
- FPP version changes
- Guard-date shifts

Only **semantic intent changes** may alter identity.

---

## Identity vs Intent Boundary

| Aspect | Identity | Intent |
|------|--------|-------|
| Editable | ❌ | ✅ |
| Symbolic | ✅ | ✅ |
| Provider-aware | ❌ | ✅ |
| Execution-specific | ❌ | ✅ |
| Used for diff | ✅ | ❌ |

> Intent expresses desire.  
> Identity defines sameness.

---

## Guarantees

- Identity is deterministic
- Identity is provider-agnostic
- Identity is minimal and stable
- Identity drift is a bug, not a feature

---

## Non-Goals

- Backward compatibility
- Encoding execution defaults
- Representing UI preferences
- Supporting undocumented scheduler behavior

---

**Next Section:** `05 — Calendar Ingestion Layer`

