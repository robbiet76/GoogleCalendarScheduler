> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 17 — Evolution & Extension Model

## Purpose

This section defines how the system is allowed to evolve over time without
losing correctness, clarity, or architectural integrity.

It exists to prevent:
- Feature-driven architectural drift
- Accidental coupling
- Backward-compatibility traps
- Silent behavior changes

All rules in this section are binding.

---

## Core Principle

The system may evolve, but its behavioral contracts must remain explicit,
versioned, and intentional.

No component may grow capabilities implicitly.

---

## Extension Axes

The system may evolve only along the following axes.

### 1. Calendar Providers

Examples:
- Google Calendar (ICS)
- Outlook / Microsoft 365
- CalDAV-compatible systems
- Custom ICS feeds

Rules:
- Each provider must implement a calendar I/O interface
- Providers must not leak provider-specific concepts outside their boundary
- Providers must not:
  - Assign identity
  - Apply scheduler semantics
  - Write to FPP
  - Modify the Manifest directly

All provider differences must be normalized before reaching the Manifest.

---

### 2. Scheduler Backends (Future)

While FPP is the current backend, others may be added.

Rules:
- Backend-specific behavior lives exclusively in a semantic layer
- The Manifest remains backend-agnostic
- Planner output must not change based on backend

If a backend cannot support a manifest intent:
- The failure must be explicit
- Silent degradation is forbidden

---

### 3. Manifest Schema Evolution

Allowed:
- Adding optional fields
- Adding new intent attributes
- Adding diagnostic or provenance metadata

Forbidden:
- Changing identity semantics
- Changing the meaning of existing fields
- Making required fields optional

All schema changes must:
- Be versioned
- Include a migration strategy
- Be documented

---

## Versioning Strategy

### Manifest Version

The Manifest must include a version field:

manifest_version: 1

Rules:
- Version changes are rare
- Minor behavior changes do not bump version
- Semantic changes must bump version

---

### Behavioral Versioning

All behavior changes must be captured in:
- The specification
- Changelog notes
- Migration documentation (if applicable)

No undocumented behavior change is valid.

---

## Migration Rules

When evolving the system:
- Existing manifests must remain readable
- Automatic migration is preferred but not required
- Destructive migrations must:
  - Be explicit
  - Require user acknowledgment
  - Offer rollback where possible

Silent migrations are forbidden.

---

## Deprecation Policy

Features may be deprecated but not silently removed.

Deprecation requires:
- A clear warning
- A documented replacement (if applicable)
- A defined removal timeline

Deprecated behavior must continue to function until removal.

---

## Prohibited Evolution Patterns

The following are explicitly forbidden:
- Provider-specific logic in core layers
- Heuristic-based behavior changes
- Identity rules that vary by backend
- Compatibility shims inside core logic
- Reading schedule.json outside the semantic layer
- Planner or Diff logic becoming backend-aware

---

## Extension Checklist

Any proposed extension must answer:
1. Which component owns this behavior?
2. Does it change identity?
3. Does it affect ordering?
4. Does it introduce coupling?
5. Is it documented?

If any answer is unclear, the extension is rejected.

---

## Summary

The system evolves by addition, not mutation.

- The Manifest remains the center
- Identity remains sacred
- Semantics remain isolated
- Providers remain replaceable
- Behavior remains explainable
