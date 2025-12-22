# ChatGPT + Git – Quick Development Checklist

Use this before and during development sessions.

---

## Before Coding
- [ ] Working locally in VS Code
- [ ] Repo clean (`git status`)
- [ ] Dry-run mode enabled (unless testing writes)

---

## While Coding
- [ ] Make small, focused changes
- [ ] Commit before risky edits
- [ ] Use clear commit messages

---

## Before Testing on FPP
- [ ] `git push` from Mac
- [ ] `git pull` on FPP
- [ ] Verify expected logs appear

---

## When Asking ChatGPT for Help
- [ ] Provide current commit hash
- [ ] State one clear goal
- [ ] Paste only relevant diffs or files

Preferred commands:

git diff
git show HEAD:src/File.php
git show <commit>

---

## Safety Checks
- [ ] No SSH editing on FPP
- [ ] Dry-run still enabled
- [ ] Checkpoint commit exists

---

## If Something Breaks
- Stop
- Revert to last known-good commit
- Resume calmly