> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 02 — System Architecture Overview

This section describes the high-level architecture of the scheduler system, its major components, and how responsibilities are cleanly separated. This document is intentionally conceptual and technology-agnostic, focusing on behavior and data flow rather than implementation details.

---

## Design Goals

- **Single source of truth** via the Manifest
- **Strict separation of concerns** between planning, diffing, and applying
- **Provider-agnostic calendar ingestion** (no Google-specific logic outside adapters)
- **FPP-specific logic isolated** to a single semantic layer
- **Deterministic, testable behavior**
- **Strong observability** through structured logging and diagnostics
- **No backward-compatibility constraints** (unreleased plugin)

---

## High-Level Data Flow

```
Calendar Provider (ICS)
        ↓
Provider Adapter (ICS → Canonical Events)
        ↓
Planner (Events → Bundles → Desired Entries)
        ↓
Manifest (Identity, Intent, Provenance)
        ↓
Comparator / Diff Engine
        ↓
Apply Engine
        ↓
FPP Scheduler (schedule.json)
```

Each stage consumes the output of the previous stage and produces a strictly defined artifact.

---

## Core Architectural Layers

### 1. Provider Layer (Inbound)

**Responsibility**
- Fetch calendar data
- Parse provider-specific formats (ICS quirks, timezone rules)
- Emit canonical calendar events

**Key Rules**
- No FPP knowledge
- No scheduler logic
- No identity decisions

**Examples**
- Google Calendar ICS adapter
- Future: generic CalDAV, Outlook ICS

---

### 2. Planning Layer

**Responsibility**
- Convert canonical calendar events into scheduler intent
- Resolve unsupported constructs (exceptions, symbolic dates)
- Emit Bundles as the atomic planning unit

**Outputs**
- Ordered list of Bundles
- Each Bundle contains one or more Intent entries

**Key Rules**
- No reading or writing of schedule.json
- No diffing logic
- Deterministic output

---

### 3. Manifest Layer (Center of Truth)

**Responsibility**
- Define identity and ownership of scheduler entries
- Track intent, provenance, and status
- Enable comparison, adoption, and revert

**Key Rules**
- Manifest identity is immutable once created
- Manifest governs ownership, not schedule.json
- All scheduler reconciliation flows through Manifest

(See **03 — Manifest**)

---

### 4. Comparator / Diff Layer

**Responsibility**
- Compare Desired Entries (from Planner) with Existing Entries (from FPP)
- Classify changes into creates / updates / deletes
- Respect ownership and locking rules

**Key Rules**
- Never mutate inputs
- No scheduling semantics
- No calendar awareness

---

### 5. Apply Layer (Outbound)

**Responsibility**
- Translate desired scheduler entries into FPP-compatible format
- Write changes to schedule.json
- Support dry-run and apply modes

**Key Rules**
- schedule.json is write-only from this layer
- No planning or diffing logic
- Uses FPP Semantic Layer exclusively

---

### 6. FPP Semantic Layer

**Responsibility**
- Encapsulate all Falcon Player–specific behavior
- Translate abstract intent into valid FPP scheduler entries
- Handle symbolic time/date resolution if required

**Key Rules**
- No calendar logic
- No manifest logic
- Single choke-point for FPP changes

---

## Bundles as a First-Class Concept

- All planner output is expressed as Bundles
- Even single-entry events use a Bundle
- Bundles are atomic and ordered as a unit
- Bundles enable:
  - Date exceptions
  - Unsupported day masks
  - Future overrides and layering

(See **04 — Bundles & Ordering**)

---

## Observability & Diagnostics

- Global debug flags enabled at bootstrap
- Each layer logs independently
- Logs are structured and component-scoped
- Debug output never mutates runtime behavior

---

## Directory Structure (Conceptual)

```
src/
 ├─ Provider/
 │   └─ Ics/
 ├─ Planner/
 │   ├─ Planner
 │   └─ Bundles
 ├─ Manifest/
 ├─ Diff/
 ├─ Apply/
 ├─ Semantics/
 │   └─ Fpp
 └─ Core/
     └─ Logging, Utilities
```

---

## Architectural Non-Goals

- Backward compatibility
- In-place mutation of legacy scheduler data
- Provider-specific logic leaking into core layers
- UI-driven scheduling behavior

---

## Summary

This architecture favors clarity, determinism, and long-term maintainability. Each layer has a single responsibility, and the Manifest provides a stable contract between planning and execution. The system is designed to evolve—new providers, new schedulers, new semantics—without destabilizing existing behavior.
