---
name: auto-merge-on-green
description: User permits auto-squash-merge of own PRs as soon as CI/CD turns fully green. No confirmation prompt needed.
metadata: 
  node_type: memory
  type: feedback
  originSessionId: f94a1c13-01b9-4ace-8321-f7d76cfa1b21
---

When a PR I created reaches all-green CI (Code Quality + Security + Tests + License all PASS), squash-merge it immediately with `--delete-branch`. Do not ask first.

**Why:** User confirmed explicitly 2026-05-23 after merging #611 — wants the iterative-merge cadence without confirmation friction.

**How to apply:**
- Only my own PRs (not other agents' work)
- Only when ALL required checks are `pass` (skipping `auto-merge` + `Docker Build & Push` is OK)
- Use `gh pr merge <N> --squash --delete-branch`
- Worktree-cleanup may fail with "branch used by worktree" warning — that's expected, the remote merge still succeeds; clean local worktrees when convenient

**Edge cases that still need confirmation:**
- Force-push or destructive git ops
- Non-trivial main-merges (multi-conflict resolution) — show what was chosen
- Cross-cutting changes affecting >20 files where I'm uncertain about an intent call
