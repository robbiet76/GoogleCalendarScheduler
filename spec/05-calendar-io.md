> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 05 — Calendar I/O Layer

## Purpose

The **Calendar I/O Layer** defines the system boundary between the scheduler domain and external calendar systems (e.g. Google Calendar via ICS).

This layer is responsible for **all communication with calendar providers**, regardless of direction or transport mechanism.

It is explicitly *non-semantic* and *non-planning*.

> The Calendar I/O Layer moves data **into** and **out of** the system. It does not interpret intent, enforce identity, or apply scheduling rules.

---

## Directional Model: Calendar I/O

Rather than treating ingestion and export as separate concerns, the system models calendar interaction as **bidirectional I/O**.

```
Calendar Provider
        ↑   ↓
   Calendar I/O Layer
        ↓   ↑
      Manifest
```

This abstraction allows flexibility across:

- Pull vs push models
- File-based ICS vs API-based providers
- Batch vs streaming updates

---

## Responsibilities

The Calendar I/O Layer **must**:

- Communicate with calendar providers
- Parse provider data into provider-neutral records
- Serialize provider-neutral records back into provider formats
- Preserve symbolic and structural intent
- Remain provider-aware but domain-agnostic

The Calendar I/O Layer **must not**:

- Derive semantic identity
- Apply scheduling guard rules
- Resolve symbolic times or dates
- Decide ordering, bundling, or dominance
- Mutate Manifest state

---

## Inbound I/O (Calendar → System)

### Purpose

Inbound Calendar I/O is responsible for *receiving* calendar data and translating it into a **provider-neutral event representation** suitable for resolution.

### Inbound Contract

```ts
ingestCalendar(source: CalendarSource): CalendarEvent[]
```

Where:

- `CalendarEvent` is a neutral, lossless representation
- All recurrence, symbols, and exceptions are preserved

### Inbound Rules

- No semantic interpretation is allowed
- No identity is computed
- No intent normalization is performed
- Provider quirks are isolated here

---

## Outbound I/O (System → Calendar)

### Purpose

Outbound Calendar I/O is responsible for *emitting* calendar-compatible representations from **Manifest intent**.

It is the inverse operation of inbound ingestion.

### Outbound Contract

```ts
exportCalendar(
  entries: ManifestEntry[],
  target: CalendarTarget
): CalendarArtifact
```

Where:

- Only `ManifestEntry.intent` is consumed
- The output is provider-specific (e.g. ICS, API payload)

### Outbound Rules

Outbound Calendar I/O **must**:

- Preserve symbolic intent where supported
- Encode recurrence faithfully
- Produce provider-valid artifacts

Outbound Calendar I/O **must not**:

- Resolve symbolic times or holidays
- Apply guard dates
- Modify identity or ownership
- Merge or split entries

---

## Symbolic Preservation

- Symbolic times (e.g. Dawn, Dusk) must remain symbolic if supported
- Symbolic dates (e.g. holidays) must remain symbolic if supported
- Unsupported symbolic constructs cause explicit export failure

Silent degradation is forbidden.

---

## Provider Abstraction

Calendar providers are implemented as adapters behind a common interface.

```ts
interface CalendarProvider {
  ingest(source): CalendarEvent[]
  export(entries): CalendarArtifact
}
```

Examples:

- Google Calendar (ICS)
- Generic ICS feed
- Future API-based providers

---

## Failure Semantics

Calendar I/O failures are **hard failures**.

- Invalid provider data
- Unsupported recurrence patterns
- Unsupported symbolic constructs

Failures surface immediately and do not partially apply.

---

## Non-Goals

- Semantic interpretation
- Scheduler-specific logic
- Identity reconciliation
- Backwards compatibility with malformed calendars

---

## Guarantees

- Calendar I/O is reversible where supported
- Manifest intent is never silently altered
- Provider-specific behavior is fully isolated

---

## Relationship to Other Layers

- **Manifest**: Calendar I/O reads from and writes to the Manifest but never mutates it
- **Resolution Layer**: Receives provider-neutral events
- **Planner / Diff / Apply**: Never interact directly with calendar providers

---

> Calendar I/O is the only layer that knows calendars exist.

