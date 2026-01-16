> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 15 — Error Handling & Invariants

## Purpose

This section defines:

- **Hard invariants** the system must never violate
- **Soft failures** the system may tolerate (with explicit behavior)
- A consistent **error reporting contract** across UI, API endpoints, logs, and debug artifacts
- Which components are allowed to **fail fast** vs. must **degrade safely**
- Explicitly **forbidden behaviors** (silent repair, guessing, mutation during preview, etc.)

> **Policy:** This system is intentionally strict. If upstream data is invalid or internal invariants are violated, we prefer **loud failure** over silent drift.

---

## Definitions

### Hard Invariant
A rule that must always hold. Violation is a **fatal error**.

Examples:
- Missing semantic identity on a managed entry in apply mode
- Planner emitting an invalid schedule entry shape

### Soft Failure
A condition that may be tolerated **only** if:
- It is **explicitly classified** here, and
- It results in a predictable **degraded output**, and
- The condition is **observable** (logs + surfaced in preview results)

Examples:
- Calendar provider transient failure (network/timeouts)
- Individual event parse failure while others succeed (depending on mode)

### Fatal Error
An error that must stop the pipeline for the current run. The UI must show it and no apply must be attempted.

### Recoverable Error
An error that can be continued past **only** where explicitly allowed (see “Soft failures”).

---

## Error Surfaces

All errors must appear in **at least two** places:

1. **Response envelope** (controller endpoint output)
2. **Logs** (always)
3. **Diagnostics artifacts** (only when debug enabled)

### Required: Response Envelope

Every endpoint that performs work must return an envelope like:

```json
{
  "ok": false,
  "error": {
    "type": "invariant_violation",
    "code": "MANIFEST_IDENTITY_MISSING",
    "message": "Missing semantic identity on managed entry",
    "component": "Planner",
    "severity": "fatal",
    "context": {
      "uid": "provider:google:abc123",
      "manifest_id": null
    }
  }
}
```

#### Fields

- `type`: high-level family (see taxonomy)
- `code`: stable machine-readable identifier
- `message`: human-readable, concise
- `component`: where the error originated
- `severity`: `fatal` | `recoverable`
- `context`: small JSON object for debugging (no secrets)

---

## Error Taxonomy

All errors must map to one of these families:

1. **config_error** — missing/invalid runtime config, credentials, paths
2. **provider_error** — calendar provider I/O, parse, format mismatch
3. **resolution_error** — event cannot be normalized into intent
4. **planner_error** — bundle/event construction or ordering invariants
5. **identity_error** — identity build invalid/unstable/duplicate
6. **diff_error** — reconciliation duplicates, invalid managed state
7. **apply_error** — write failures, partial apply, schema mismatch
8. **invariant_violation** — internal contract broken (always fatal)

---

## Mode-Specific Behavior (Preview vs Apply)

### Preview Mode (Dry Run)

Preview is allowed to surface incomplete or invalid situations **without mutating FPP**.

Rules:

- Preview MAY include error records in output
- Preview MUST NOT write to FPP
- Preview MUST NOT write to the manifest store
- Preview MUST NOT “fix” anything automatically

Preview may return:

- `ok: true` with warnings (soft failures)
- `ok: false` (fatal errors)

### Apply Mode

Apply is strict:

- Any missing identity on managed entries → **fatal**
- Any duplicate identities (desired or existing) → **fatal**
- Any planner invariant violation → **fatal**
- Any partial apply attempt must be prevented (preflight) or surfaced (transaction failure)

---

## Core Invariants

### Global Invariants (System-Wide)

1. **Manifest is authoritative for managed intent**
   - No other data source may override intent.

2. **Planner output is deterministic**
   - Same inputs → same desired output (including order).

3. **No hidden state**
   - No implicit persistence in schedule.json or external files (except explicit debug artifacts).

4. **Unmanaged entries are never modified**
   - Never deleted
   - Never reordered
   - Never updated

5. **No silent repair**
   - The system must not “guess” missing fields, infer identity, or auto-correct upstream mistakes.

---

## Component Invariants

### Calendar I/O Layer (Provider + Ingest + Export)

Hard invariants:

- Never emits partially-parsed events as valid
- Never mutates intent or identity
- Never writes to FPP
- Never writes to manifest store

Soft failures (allowed if explicitly surfaced):

- Provider unavailable / timeout
- Single-event parse failure (when policy is “best effort” preview)

### Resolution & Normalization

Hard invariants:

- Output intent must be complete enough for identity build **or explicitly marked unresolved**
- Normalization must use shared helpers (no duplicate logic drift)
- Type must be one of: `playlist | command | sequence`

Soft failures:

- Unsupported recurrence shape (must yield “unresolved” with reason)
- Unsupported symbolic dates/times (must yield “unresolved” with reason)

### Planner

Hard invariants:

- Each Manifest Event yields:
  - 1+ SubEvents (atomic group)
  - Every SubEvent produces exactly one FPP Entry
- Planner must not output default/empty scheduler entries
- Planner must not leak metadata into scheduler schema
- Bundle/Event atomicity is preserved (internal ordering stable)

### Identity

Hard invariants:

- Identity is built from **identity fields only**
- `stopType` and `repeat` are **not** identity fields
- Identity build must be canonical and stable across runs
- No duplicate identity IDs in desired state

### Diff

Hard invariants:

- Match entries **only** by identity ID
- Never match by index/position
- Never delete unmanaged entries
- Duplicate identities in existing or desired state → fatal

### Apply

Hard invariants:

- Applies only create/update/delete emitted by Diff
- Never performs partial updates
- Never reorders unmanaged entries
- Must be idempotent: re-running apply after success should produce no changes

---

## Soft Failures Policy (When Allowed)

Soft failures must:

- Include `severity: recoverable`
- Include `context.reason`
- Be visible in UI as warnings

Recommended list of soft failures:

- Calendar provider I/O failure in preview (no apply)
- Best-effort parse failures (some events skipped) in preview
- Export formatting downgrade (fallback to basic formatting) **only** if no mutation occurs

Explicitly forbidden as “soft”:

- Missing identity during apply
- Planner emitting incomplete scheduler entry
- Duplicate identity IDs
- Any mutation in preview mode

---

## Diagnostic Requirements

When debug flags are enabled:

- Write structured JSONL to `/tmp` for:
  - ingestion summary
  - resolution decisions (per event)
  - planner ordering passes
  - identity build inputs/outputs (redacted)
  - diff summary
  - apply actions

Diagnostics must:

- Never include secrets (tokens, credentials)
- Be bounded in size (truncate with clear marker)
- Be correlated with a `run_id`

---

## Forbidden Behaviors (Non-Negotiable)

The system MUST NOT:

- Auto-generate identity when required fields are missing
- Substitute defaults for missing schedule fields and proceed silently
- Repair schedule entries “on the fly” during diff/apply
- Reorder or delete unmanaged entries
- Use schedule.json as authoritative input
- Allow planner output to contain “template defaults” that were never derived from intent

---

## Summary

This system uses **strict contracts** and **observable failures** to prevent drift:

- Preview may surface issues without changing state
- Apply must fail fast on invariant violations
- Identity is the only reconciliation key
- Unmanaged entries are preserved exactly
- Silent fixes are forbidden by design
