> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 14 — UI & Controller Contract

## Purpose

This section defines the **strict contract** between:

- The **User Interface (UI)**
- The **Controller**
- The **Planner and downstream pipeline**

It exists to ensure:
- Determinism
- Predictability
- Zero hidden logic
- Zero accidental coupling

The UI is *not* part of the scheduling system.  
The Controller is *not* part of the planning system.

---

## High-Level Responsibilities

### UI (User Interface)

The UI is responsible for **presentation and user intent capture only**.

It MUST:
- Accept configuration input (calendar connection, flags)
- Trigger preview and apply actions
- Display planner output and diff summaries
- Display errors exactly as returned

It MUST NOT:
- Infer intent
- Modify planner output
- Read or write scheduler data
- Perform reconciliation logic
- Perform identity logic
- Parse or mutate schedule.json

---

### Controller

The Controller is the **orchestration boundary**.

It MUST:
- Accept requests from the UI
- Load configuration and runtime flags
- Invoke the Planner deterministically
- Route output to Preview or Apply paths
- Surface errors without modification

It MUST NOT:
- Modify planner output
- Repair invalid data
- Skip invariant checks
- Implement scheduling logic
- Read schedule.json directly

---

## Preview vs Apply Lifecycle

### Preview Mode

Preview mode:
- Executes the full planning pipeline
- Produces desired state and diff
- Performs **no writes**
- Is safe to run repeatedly

Preview MUST:
- Use identical logic to Apply
- Fail on the same invariants
- Surface identity issues clearly

---

### Apply Mode

Apply mode:
- Executes the same pipeline as Preview
- Applies the diff to FPP
- Is write-only with respect to FPP

Apply MUST:
- Be idempotent
- Fail fast on invariant violations
- Never re-plan or re-diff independently

---

## Dry Run Semantics

Dry run is a **controller-level flag**.

Rules:
- Dry run = Preview + Apply UI flow, but no persistence
- Planner output must be identical
- Diff output must be identical
- Only the final write step is suppressed

The UI must visually indicate dry-run mode.

---

## Data Flow Contract

```
UI
  ↓
Controller
  ↓
Planner
  ↓
Diff
  ↓
Apply (optional)
```

Rules:
- Data only flows downward
- No component reads upstream state
- No circular dependencies
- No implicit state sharing

---

## Error Handling

Errors:
- Must propagate upward unchanged
- Must never be swallowed
- Must never be “fixed” in the UI or Controller

The UI:
- Displays errors
- Does not interpret them

The Controller:
- Routes errors
- Does not transform them

---

## Forbidden Behaviors

The UI MUST NOT:
- Generate scheduler entries
- Assign identities
- Resolve times or dates
- Apply guard logic

The Controller MUST NOT:
- Read schedule.json
- Compare scheduler entries
- Modify ordering
- Synthesize identities

---

## Summary

The UI & Controller contract enforces:

- Clean separation of concerns
- Deterministic planning
- Debuggable behavior
- No hidden state

All intelligence lives **below** this boundary.

Violating this contract invalidates the system design.
