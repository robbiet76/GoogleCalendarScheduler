> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 09 — Planner Responsibilities

## Purpose

The **Planner** is the deterministic transformation engine that converts **Manifest Events** into an ordered, executable plan for the scheduler.

It answers one question only:

> **“Given the current Manifest, what scheduler entries *should* exist, and in what order?”**

The Planner does **not** write to FPP, does **not** mutate the Manifest, and does **not** interpret existing scheduler state as authoritative.

---

## Core Responsibilities

The Planner **MUST**:

1. Ingest the current Manifest as the *only* source of truth
2. Expand each Manifest Event into its SubEvents
3. Preserve Manifest Event atomicity at all times
4. Apply the Scheduler Ordering Model
5. Produce a deterministic desired state
6. Support preview and apply modes without behavior drift

The Planner **MUST NOT**:

- Read from `schedule.json`
- Infer intent from existing scheduler entries
- Persist any state
- Modify Manifest Events or identities
- Apply compatibility shims for legacy behavior

---

## Inputs

The Planner accepts **only**:

- A complete, validated Manifest
- Runtime configuration flags (e.g. preview, debug)

It must **never**:

- Read from FPP directly
- Read calendar data
- Perform provider-specific logic

---

## Outputs

The Planner produces a **pure data result**:

```ts
PlannerResult {
  creates: FppScheduleEntry[]
  updates: FppScheduleEntry[]
  deletes: FppScheduleEntry[]
  desiredEntries: FppScheduleEntry[]
}
```

Rules:

- Output is fully deterministic
- Two identical Manifests MUST yield identical PlannerResults
- Output contains **no side effects**

---

## Manifest Event Expansion

For each **Manifest Event**:

1. SubEvents are expanded in their defined internal order
2. Exactly one SubEvent is designated as the `base`
3. Zero or more SubEvents may be `exceptions`

Rules:

- SubEvents are **never merged across Manifest Events**
- SubEvents are **never reordered internally**
- All SubEvents move together as an atomic unit

---

## Ordering Responsibilities

The Planner is responsible for **all ordering decisions** for managed entries.

It MUST:

- Apply the Scheduler Ordering Model exactly
- Preserve unmanaged entry ordering and grouping
- Ensure deterministic ordering across runs

It MUST NOT:

- Use hash order, insertion order, or array index as a heuristic
- Perform partial ordering
- Defer ordering decisions to later phases

---

## Preview vs Apply Behavior

The Planner behaves **identically** in preview and apply modes with one exception:

- In preview mode, invalid or incomplete identities MAY be surfaced
- In apply mode, invalid identities MUST fail fast

Rules:

- Preview MUST NOT mask logic errors
- Preview output MUST be a truthful representation of apply output

---

## Identity Handling

The Planner:

- Builds Manifest identities **once** per Manifest Event
- Attaches identity metadata to desired entries
- Treats identity as immutable

The Planner MUST NOT:

- Recompute identity during diff or apply
- Patch identity fields conditionally

---

## Determinism Guarantees

The Planner guarantees:

- Stable ordering given identical input
- No dependency on runtime timing
- No dependency on external state

Violations of determinism are considered **critical defects**.

---

## Error Handling

The Planner MUST fail fast on:

- Invalid Manifest structure
- Missing required identity fields
- Violations of atomicity

The Planner MUST surface:

- Clear invariant violations
- Structured error messages

Silent failures are forbidden.

---

## Debugging & Diagnostics

When debugging is enabled, the Planner MUST:

- Emit ordering traces
- Expose pre- and post-order states
- Clearly label dominance decisions

Debug output MUST:

- Never affect behavior
- Be fully optional

---

## Summary

The Planner is:

- Pure
- Deterministic
- Manifest-driven
- Ordering-authoritative

Any deviation from these principles is a design error.
