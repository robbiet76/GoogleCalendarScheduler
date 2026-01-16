> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 13 — Logging, Debugging & Diagnostics

## Purpose

The Logging, Debugging & Diagnostics layer exists to ensure the system is:

- **Observable**
- **Auditable**
- **Diagnosable**
- **Safe to evolve**

This system is explicitly designed to handle complex scheduling semantics.
Silent failure, implicit behavior, and hidden state are unacceptable.

Logging and diagnostics are **first-class system requirements**, not optional tooling.

---

## Core Principles

### 1. Logging Is Not Debugging

- **Logging** is always on (at varying levels)
- **Debugging** is opt-in and scoped
- **Diagnostics** are structured, queryable outputs

Each serves a distinct purpose and must not be conflated.

---

### 2. Determinism Over Guessing

Logs must explain **what happened**, not speculate **why**.

Forbidden:
- “Probably”
- “Assumed”
- “Fallback applied” without context

Required:
- Explicit decision points
- Inputs → outputs
- Rule references when applicable

---

### 3. Layer-Scoped Responsibility

Each major layer is responsible for its own logging:

| Layer | Responsibility |
|-----|---------------|
| Calendar I/O | Provider data ingress / egress |
| Resolution | Semantic decisions, normalization |
| Manifest | Identity creation, state transitions |
| Planner | Ordering, event/subEvent generation |
| Diff | Comparison outcomes |
| Apply | Write actions and safeguards |
| UI / Controller | User-triggered lifecycle |

Cross-layer logging is **forbidden**.

---

## Log Levels

The system defines **five canonical log levels**:

| Level | Description |
|-----|------------|
| `ERROR` | Invariant violations, fatal failures |
| `WARN` | Recoverable issues, degraded behavior |
| `INFO` | High-level lifecycle events |
| `DEBUG` | Detailed execution traces |
| `TRACE` | Fine-grained step-by-step data |

Rules:
- `ERROR` and `WARN` are always emitted
- `INFO` is always emitted
- `DEBUG` and `TRACE` require explicit enablement

---

## Debug Flags & Runtime Control

Debugging must be **centrally controlled**.

### Required Debug Controls

Debug flags must be configurable via:

- Bootstrap configuration
- Environment variables
- (Optional) UI toggle for non-destructive modes

Example conceptual flags:

```
DEBUG_GLOBAL
DEBUG_CALENDAR_IO
DEBUG_RESOLUTION
DEBUG_MANIFEST
DEBUG_PLANNER
DEBUG_DIFF
DEBUG_APPLY
```

Rules:
- Flags are **additive**, never implicit
- No component may self-enable debugging
- Debug state must be discoverable at runtime

---

## Structured Logging

All logs must be **structured**.

Minimum required fields:

```json
{
  "timestamp": "ISO-8601",
  "level": "INFO | WARN | ERROR | DEBUG | TRACE",
  "component": "Planner | Manifest | Diff | Apply | ...",
  "operation": "short_machine_readable_name",
  "message": "human-readable summary",
  "context": { }
}
```

Rules:
- Context must be serializable
- Context must never include secrets
- Context should prefer identifiers over full payloads

---

## Diagnostic Artifacts

Some operations must emit **diagnostic artifacts**, not just logs.

### Required Diagnostic Outputs

| Scenario | Artifact |
|--------|---------|
| Planner ordering | Ordered event/subEvent listing |
| Diff preview | Full diff breakdown |
| Identity errors | Identity snapshot |
| Apply (dry-run) | No-write execution trace |

---

## Preview vs Apply Diagnostics

Preview mode must surface **more information**, never less.

| Mode | Behavior |
|----|---------|
| Preview | Full diagnostics, relaxed failures |
| Apply | Fail fast, minimal noise |

---

## Error Visibility Contract

All invariant violations must:

1. Be logged at `ERROR`
2. Include component + operation
3. Include enough context to reproduce
4. Surface to the caller (UI or API)

Silent failure is considered a **system defect**.
