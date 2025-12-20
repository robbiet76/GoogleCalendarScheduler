# Agent Sanity Check

This document confirms that agent-based access to this repository is correctly configured and operating under the approved guardrails.

---

## Repository Overview

- **Repository name:** GoogleCalendarScheduler
- **Visibility:** Public
- **Source of truth:** This GitHub repository
- **Purpose:** Google Calendar–driven scheduling for Falcon Player (FPP)

---

## Default Branch

- **Default branch:** `master`
- This branch is protected and requires human review for all changes.

---

## Branch Protection Summary

The following branch protection rules are in effect:

### Protected Branches
- `master`
- `feature/*`

Protections enforced:
- Pull request required for merge
- Human approval required
- No force-pushes
- No deletions
- No bypass of protections by collaborators

### Unprotected Branches
- `agent/*`

These branches are intentionally unprotected to allow:
- Experimental work
- Incremental commits
- Draft changes by the agent

No agent-owned branch may be merged without explicit human approval.

---

## Agent Access Model

- **Agent identity:** `repo-agent`
- **Access level:** Write (scoped by branch protection)
- **Write scope:** `agent/*` branches only
- **Merge authority:** Human only

The agent:
- MAY create and push to `agent/*` branches
- MAY open pull requests
- MAY commit incrementally
- MAY NOT merge pull requests
- MAY NOT modify protected branches
- MAY NOT force-push or rewrite history

---

## Sanity Check Confirmation

This file exists to confirm that:

- The agent can read the full repository
- The agent can create an `agent/*` branch
- The agent can add new files without impacting protected branches
- Branch protection rules are functioning as intended

No production code was modified as part of this sanity check.

---

## Status

✅ Agent access validated  
✅ Branch protections verified  
✅ Repository remains stable  

This confirms that the agent workflow is safe to use for future phases.