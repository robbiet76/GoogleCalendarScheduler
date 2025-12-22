# GoogleCalendarScheduler – Development Workflow

This document defines how development is done on the
GoogleCalendarScheduler (GCS) plugin using Git, VS Code, and ChatGPT.

Following this workflow keeps development fast, safe, and recoverable.

---

## 1. Roles & Environment

### Mac + VS Code (Primary Dev Environment)
- All code edits happen locally
- Use VS Code with PHP Intelephense
- No editing directly on the FPP device

### GitHub (Source of Truth)
- All progress is committed and pushed
- Commits are the authoritative history
- Used for rollback and collaboration

### Falcon Player (FPP)
- Runtime environment only
- Receives updates via `git pull`
- Used for testing and validation

---

## 2. Daily Development Loop

1. Edit code locally in VS Code
2. Make **small, logical changes**
3. Commit with a clear message
4. Push to GitHub
5. Pull onto FPP
6. Test (dry-run unless explicitly enabled)

Repeat frequently.

---

## 3. Commit Guidelines

### Commit Often
- Prefer many small commits over large ones
- Commit before risky or experimental changes


### Commit Message Format

<area>: <what changed>

Examples:

parser: normalize DTSTART timezone handling
rrule: fix weekly week-alignment logic
scheduler: add delete protection for non-plugin entries
cleanup: remove deprecated code paths

---

### 4. Branching Strategy (Simple)

- `master` is always deployable
- Optional feature branches for risky work:

```bash
git checkout -b rrule-fix

Merge Back When Stable
No rebasing or complex workflows required.

---

### 5. ChatGPT Collaboration Workflow
When asking ChatGPT for help, always provide:
Current branch and commit hash
One clear goal
Minimal, precise context

Preferred Context Commands

git diff
git show HEAD
git show HEAD:src/IcsParser.php
git diff <old>..<new>

Avoid pasting entire logs or large histories unless requested.

---

### 6. Safety Rules (Non-Negotiable)
Never edit plugin code directly on FPP
Always commit before experimenting
Use dry-run mode unless explicitly testing writes
If something feels unstable, stop and checkpoint

---

### 7. Recommended Session Start Template
Use this when resuming work:

Project: GoogleCalendarScheduler
Branch: master
HEAD: <commit hash>

State:
- Stable dry-run pipeline working

Goal:
- <single task>

Constraints:
- Checkpoint mode
- Small, safe changes

---

Following this workflow ensures predictable progress,
easy recovery, and effective collaboration.