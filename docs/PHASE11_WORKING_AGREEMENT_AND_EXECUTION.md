GoogleCalendarScheduler – Phase 11 Working Agreement & Execution Plan
Purpose
This document defines the clean, reset workflow for Phase 11 development. It establishes a clear,
repeatable process using local development, GitHub, and FPP testing, with Codex used only for code
visibility and reasoning — not as the source of truth for Git operations.
Canonical Principles
• Git is authoritative.
• VS Code on Mac is where code changes happen.
• Commits are created and pushed from the Mac.
• FPP pulls from Git for testing.
• Codex is read-only for understanding and planning.
Phase 11 Scope Reminder
Phase 11.1 focuses only on wiring the existing pipeline end-to-end in dry-run mode.
No YAML behavior changes.
No new features.
No Phase 12 work.
Daily Workflow
1. Planning
Discuss intent, architecture, and expected behavior in chat.
Confirm which files will be touched.
2. Local Development (Mac)
Edit code in VS Code.
Apply patches or full file replacements.
Run basic validation if applicable.
3. Git (Mac)
git status
git add
git commit -m "Phase 11.1: "
If branch is protected:
git push origin
Open PR in GitHub and merge.
4. FPP Testing
SSH into FPP.
cd /home/fpp/media/plugins/GoogleCalendarScheduler
git fetch origin
git checkout
git pull
Run plugin sync.
Collect logs.
Compare behavior against canonical test cases.
5. Validation
Confirm expected schedules.
Log discrepancies.
Repeat cycle.
Codex Usage Rules
• Codex is used only to inspect code and explain behavior.
• No commits or pushes from Codex.
• No branch switching inside Codex.
• Codex must always match the active Git branch.
Phase 11.1 Files Expected to Change
• src/SchedulerSync.php
• Possibly src/SchedulerState.php
• No UI or YAML changes
Canonical Test Reference
docs/PHASE11_TEST_CALENDAR.md
End State of Phase 11.1
• SchedulerRunner passes mapped schedules into SchedulerSync.
• SchedulerState loads real FPP schedules.
• SchedulerDiff computes create/update/delete 