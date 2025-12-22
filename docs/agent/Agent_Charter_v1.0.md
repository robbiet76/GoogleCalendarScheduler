# Agent Charter — GoogleCalendarScheduler (v1.0)

## Purpose

This charter defines the scope, authority, and guardrails for any agentic AI
operating on the **GoogleCalendarScheduler** repository.

The intent is to gain leverage from AI assistance while preserving correctness,
architectural intent, reversibility, and human oversight.

At no point should the agent be able to cause irreversible or opaque changes.

---

## Guiding Principles

1. Human remains the authority
2. All changes must be reversible
3. No silent behavior changes
4. Clarity over cleverness
5. Logs are the source of truth

---

## Allowed Capabilities

The agent MAY:

- Read the entire repository
- Analyze logs, diffs, and historical commits
- Propose design plans and refactors
- Generate full-file replacements
- Create commits on agent-scoped branches only
- Update documentation in `docs/`
- Assist with phase planning and checklists
- Suggest rollback or recovery actions

---

## Prohibited Actions (Hard Rules)

The agent MUST NOT:

- Commit directly to `main`
- Commit directly to long-lived feature branches
- Force-push (`git push --force`)
- Rewrite history (`rebase`, `amend`, squash)
- Modify git config, hooks, or remotes
- Delete tags or branches not created by the agent
- Introduce new external dependencies without approval
- Change YAML schemas, contracts, or semantics silently
- Make partial-file edits unless explicitly authorized

Violation of any hard rule terminates agent authority immediately.

---

## Branching Rules

- Agent work is restricted to branches matching:
  ```
  agent/*
  ```
- Each agent task uses a fresh branch
- Branches are disposable
- Merges are always human-initiated

---

## Commit Rules

Every agent commit must:

- Be atomic
- Contain a descriptive message prefixed with:
  ```
  Agent:
  ```
- Touch only files relevant to the stated goal
- Leave the working tree clean

The agent must never leave uncommitted changes.

---

## Required Workflow (Non-Negotiable)

Before writing code, the agent must:

1. Produce a Plan
   - Files to be touched
   - Why each file is affected
   - Expected behavior changes
2. Identify risk areas
3. Define rollback strategy
4. State expected log output changes

Only after human approval may code be generated.

---

## Rollback & Safety Guarantees

Before each agent session, a human must create a safety anchor:

```bash
git tag agent-safe-point-YYYYMMDD-HHMM
git push origin agent-safe-point-YYYYMMDD-HHMM
```

If anything goes wrong, recovery must be possible via:

```bash
git reset --hard <safe-point>
```

No exceptions.

---

## Verification Requirements

For any behavioral change, the agent must specify:

- Logs that must appear
- Logs that must not change
- Known edge cases
- Expected dry-run behavior

No feature is considered complete without log validation.

---

## Project-Specific Constraints

For GoogleCalendarScheduler, the agent must respect:

- Existing phase boundaries
- Stable YAML contracts
- Dry-run-first policy
- Scheduler parity with FPP
- Full-file-drop working agreement
- Mac → Git → FPP deployment pattern

The agent may not bypass the scheduler engine.

---

## Approved Initial Scope

Initial agent authority is limited to:

- Phase planning (e.g. Phase 12.2)
- Documentation updates
- Proposal-only refactors
- Read-only code analysis

Write access may be expanded later by explicit approval.

---

## Termination Clause

Agent authority may be revoked at any time, for any reason, without explanation.

All agent-created branches may be deleted without notice.

---

## Status

- Charter version: 1.0
- Status: Active
- Scope: Restricted
