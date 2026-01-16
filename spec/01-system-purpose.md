> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 01 — System Purpose & Design Principles

## Status

**Authoritative**  
Changes to this document are rare, intentional, and versioned.

This document defines **why the system exists**, **what problems it solves**, and **the principles that govern all design decisions**.  
If any future implementation conflicts with this document, the implementation is wrong.

---

## System Purpose

The Scheduler exists to:

> **Maintain a deterministic, auditable, and intentional synchronization between calendar-based scheduling intent and the FPP scheduler.**

It translates **human scheduling intent**, expressed via calendar events, into **precise, executable scheduler entries** in FPP — without ambiguity, drift, or hidden state.

The system must allow users to:
- Define schedules in a familiar calendar interface
- Trust that those schedules are reflected correctly in FPP
- Preview changes safely before applying them
- Revert or reason about changes after the fact

---

## Core Problem Being Solved

This system solves the following fundamental problems:

1. **Intent vs Execution Gap**  
   Calendar events express *intent*; FPP requires *explicit execution rules*.  
   The system bridges this gap without losing meaning.

2. **Identity & Drift**  
   Scheduler entries must be identifiable across time, edits, reorders, and rebuilds.  
   Identity must be stable and semantic, not positional.

3. **Deterministic Ordering**  
   FPP scheduling behavior depends on entry order.  
   Ordering must be computed intentionally — never accidentally.

4. **Safe Reconciliation**  
   Existing scheduler state must be reconciled without destroying unmanaged entries or guessing user intent.

5. **Auditability**  
   Every scheduler entry created by the system must be explainable in terms of:
   - Where it came from
   - Why it exists
   - What intent it represents

---

## Non-Goals (Explicit Exclusions)

The system **intentionally does NOT** attempt to:

- Be a general-purpose calendar editor
- Be a replacement for the FPP UI
- Infer intent from incomplete or ambiguous data
- Automatically “fix” invalid schedules
- Support backward compatibility during development
- Preserve legacy architecture or experimental approaches
- Support multiple conflicting sources of truth

These exclusions are deliberate.  
Simplicity and correctness take priority over convenience.

---

## Single Source of Truth

The system enforces a strict hierarchy of truth:

1. **Manifest** — authoritative representation of scheduling intent
2. **Planner Output** — authoritative desired execution state
3. **FPP Scheduler** — current execution state only

`schedule.json` is **never authoritative**.

No component is allowed to invent, infer, or persist state outside this model.

---

## Design Constraints

All implementations must respect the following constraints:

- **Deterministic Output**  
  Same inputs must always produce the same outputs.

- **No Hidden State**  
  All meaningful state must be observable via the Manifest or diagnostics.

- **Clear Phase Boundaries**  
  Ingestion, normalization, planning, diffing, and apply phases must remain isolated.

- **Fail Fast on Invariants**  
  Silent failure, guessing, or partial application is forbidden.

- **Minimal Coupling**  
  Calendar providers and FPP semantics are isolated behind explicit interfaces.

---

## Guiding Architectural Principles

### 1. Manifest-Centric Design

All intent, identity, and ownership flows through the Manifest.

Nothing bypasses it.  
Nothing mutates it implicitly.

---

### 2. Separation of Intent and Execution

- **Intent** answers: *What should happen?*
- **Execution** answers: *How FPP must be configured to make it happen.*

These concerns must never be mixed.

---

### 3. Explicitness Over Convenience

If behavior cannot be explained clearly, it is wrong.

The system prefers:
- Clear errors over silent corrections
- Explicit configuration over inference
- Predictable behavior over “smart” behavior

---

### 4. Atomicity of Meaning

A single calendar event represents a **single unit of intent**, even if it expands into multiple executable entries.

That intent must remain cohesive across all transformations.

---

### 5. Reversibility and Auditability

Every managed scheduler entry must be:
- Traceable back to intent
- Reproducible from the Manifest
- Safe to remove or rebuild

---

## Summary

This system exists to provide **trustworthy scheduling**.

It values:
- Correctness over cleverness
- Clarity over abstraction
- Intent over convenience
- Determinism over flexibility

All future decisions must align with these principles.

---
