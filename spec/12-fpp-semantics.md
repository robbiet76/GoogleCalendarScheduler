> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 12 — FPP Semantic Layer

## Purpose

The **FPP Semantic Layer** is the *only* component that understands Falcon Player (FPP) scheduler behavior, constraints, defaults, and quirks.

It acts as a strict translation boundary between:

> **Manifest Intent → Concrete FPP Scheduler Entries**

No other layer may contain FPP-specific logic.

---

## Core Responsibilities

The FPP Semantic Layer is responsible for:

1. Translating **Manifest SubEvents** into valid FPP scheduler entries
2. Applying **FPP defaults and constraints**
3. Enforcing **guard rules**
4. Normalizing **date and time semantics**
5. Preserving **ordering exactly as provided**
6. Providing **round-trip safety** (future export support)

It must be:

- Deterministic
- Stateless
- Pure (no I/O)
- Idempotent

---

## What This Layer Owns (Authoritative)

This layer is the *single authority* for:

### Scheduler Defaults
- Default `stopType`
- Default `repeat`
- Default `enabled` behavior
- Required fields that FPP does not default safely

### Scheduler Constraints
- Required date fields
- Valid date formats
- Time wrapping rules
- Repeat semantics
- Guard date behavior

### Date Pattern Semantics
- `0000` year behavior
- `00` month behavior
- Partial date matching rules
- FPP’s “ignore field if zero” behavior

### Time Semantics
- Absolute times (`HH:MM:SS`)
- Symbolic times resolved *before* reaching this layer
- Overnight wrapping (`end <= start`)

---

## Explicit Non-Responsibilities

The FPP Semantic Layer MUST NOT:

- Read or write `schedule.json`
- Decide *what* should exist
- Compare entries
- Resolve calendar data
- Infer intent
- Generate identities
- Reorder entries
- Handle preview vs apply logic

Those responsibilities belong elsewhere.

---

## Input Contract

### Input Type

```
FppSemanticInput {
  manifest_event_id: string
  sub_event_id: string
  intent: IntentObject
  timing: TimingObject
}
```

Inputs are assumed to be:

- Fully normalized
- Fully resolved
- Valid per Manifest rules

Invalid inputs are programmer errors.

---

## Output Contract

### Output Type

```
FppScheduleEntry {
  enabled: boolean
  type: "playlist" | "command" | "sequence"
  target: string
  args?: string[]
  sequence?: number

  startDate: YYYY-MM-DD
  endDate: YYYY-MM-DD
  days: string

  startTime: HH:MM:SS
  endTime: HH:MM:SS

  repeat: number
  stopType: number
}
```

The output MUST be a **valid FPP scheduler entry**, ready for persistence.

---

## Guard Date Rules

FPP requires all scheduler entries to have an end date.

### Rules

- Open-ended intent **does not** mean open-ended FPP entry
- Guard date is applied **only here**
- Guard date is never stored in the Manifest

### Behavior

| Manifest Intent | FPP Output |
|-----------------|-----------|
| Open-ended      | End date = Guard Date |
| End before guard | Unchanged |
| End after guard | Truncated to guard |

The guard date must be configurable but deterministic.

---

## DatePattern → FPP Mapping

The semantic layer translates **DatePattern** to FPP’s partial-date model.

### Supported Patterns

| Pattern | Meaning |
|-------|--------|
| `0000-MM-DD` | Every year on that date |
| `YYYY-00-DD` | That day every month in a year |
| `0000-00-DD` | That day every month, every year |
| `0000-01-01 → 0000-12-31` | All year, every year |

FPP ignores any field set to `0`.

The semantic layer must not “fix” or expand patterns.

---

## Time Semantics

### Absolute Time

Passed through unchanged:

```
19:00:00 → 19:00:00
```

### Overnight Windows

If:

```
endTime <= startTime
```

Then the entry is considered to wrap into the next day.

The semantic layer must preserve this behavior exactly.

---

## Enabled / Disabled Behavior

- `enabled` comes from intent
- No implicit enabling or disabling
- Disabled entries are still written (FPP-native behavior)

---

## Ordering Contract

The semantic layer:

- Receives entries in final order
- Emits entries in the same order
- Performs **no reordering**
- Has **no awareness** of unmanaged entries

Ordering is sacred.

---

## Error Handling

This layer must fail fast if:

- Required fields are missing
- Date formats are invalid
- Time formats are invalid
- Unsupported scheduler types are requested

Silent correction is forbidden.

---

## Future Compatibility

All future FPP changes must be handled by:

- Updating this layer
- Updating its tests

No other layer should require modification.

---

## Summary

The FPP Semantic Layer is:

- A translation boundary
- A constraint enforcer
- A future-proofing mechanism

It is **not** a planner, a fixer, or a decision-maker.

If behavior depends on FPP quirks, it belongs here — and only here.

