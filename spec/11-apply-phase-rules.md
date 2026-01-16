> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 11 — Apply Phase Rules

## Purpose

The **Apply Phase** is responsible for **executing the DiffResult** against the FPP scheduler.

It answers one question only:

> **“Given an approved diff, how do we write it to FPP safely, deterministically, and idempotently?”**

The Apply phase is **procedural**, **write-only**, and **non-decisional**.

---

## Scope & Authority

The Apply phase operates under strict constraints:

- **Input:** A validated `DiffResult`
- **Output:** Mutated FPP scheduler state
- **Authority:** Execution only — never interpretation

The Apply phase does **not** decide *what* should change — only *how* to apply changes already decided.

---

## Inputs

The Apply phase accepts:

- `DiffResult` (creates / updates / deletes)
- Runtime flags:
  - `dry_run`
  - `debug`

The Apply phase must **never** accept:

- Manifest data directly
- Planner output directly
- Calendar data
- Identity construction inputs

---

## Write-Only Interaction with FPP

Apply interacts with FPP in **write-only mode**.

Rules:

- `schedule.json` must be treated as a **write target only**
- Apply must not scan, inspect, or re-parse existing scheduler entries
- All reconciliation decisions are finalized before Apply begins

If Apply needs to read scheduler state to perform a write, that is a design error upstream.

---

## Execution Order

Apply MUST process operations in the following strict order:

1. **Deletes**
2. **Updates**
3. **Creates**
4. **Final ordering enforcement**

Rationale:

- Deletes remove obsolete managed entries
- Updates preserve identity continuity
- Creates introduce new entries cleanly
- Ordering is applied last to avoid transient instability

---

## Managed vs Unmanaged Enforcement

Apply MUST respect managed boundaries:

- Managed entries:
  - May be created
  - May be updated
  - May be deleted
  - May be reordered
- Unmanaged entries:
  - Must never be deleted
  - Must never be updated
  - Must never be reordered
  - Must retain original relative order

If an operation would affect an unmanaged entry, Apply must fail fast.

---

## Ordering Enforcement

Ordering enforcement is the **final step** of Apply.

Rules:

- Managed entries are written in Planner-defined order
- Unmanaged entries remain grouped at the top
- Relative unmanaged order is preserved
- No ordering heuristics are permitted

Ordering must be:

- Explicit
- Deterministic
- Repeatable

---

## Dry Run Behavior

When `dry_run` is enabled:

- **No writes** to FPP may occur
- All operations are simulated
- The full execution plan must still be generated

Dry run must:

- Exercise the exact same code paths
- Surface the same errors
- Differ only in the absence of side effects

---

## Idempotency Guarantees

Apply MUST be idempotent.

Given the same `DiffResult`:

- Applying twice must yield the same scheduler state
- No duplicate entries may be created
- No identity drift may occur

Violations of idempotency are critical defects.

---

## Error Handling

Apply MUST fail fast on:

- Invalid DiffResult structure
- Missing identity on managed entries
- Attempts to modify unmanaged entries
- Partial write failures

Apply MUST NOT:

- Continue after a fatal write error
- Attempt recovery heuristics
- Mask or downgrade errors

All errors must be explicit and surfaced to the controller.

---

## Logging & Diagnostics

When debugging is enabled, Apply MUST log:

- Each operation (create / update / delete)
- Entry identity and target
- Final ordering snapshot
- Any write failures

Logs must:

- Be non-invasive
- Never affect behavior
- Never mutate data

---

## Forbidden Behaviors

The Apply phase MUST NOT:

- Recompute identity
- Infer intent
- Reorder entries heuristically
- Read calendar data
- Read Manifest data
- Repair invalid entries
- Perform compatibility shims

If Apply “fixes” something, it is a bug upstream.

---

## Summary

The Apply phase is:

- Procedural
- Write-only
- Idempotent
- Deterministic
- Strictly bounded

It is the **last step** in the pipeline and must never contain business logic.

