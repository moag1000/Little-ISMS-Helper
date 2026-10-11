---
name: feedback_concurrent_agent_worktree
description: "Bei parallelem Agent im selben Repo (Branch-Switch unter dir) — eigene Commits via dediziertem git worktree isolieren, nie auf fremdem Branch committen."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8524ab1e-7912-42ad-be81-bb1c9410a3e9
---

Dieser User fährt oft mehrere Agents parallel im SELBEN Repo-Checkout. Ein anderer Agent kann `git checkout <branch>` machen während du arbeitest → HEAD wechselt unter dir → deine Edits/Commits landen auf SEINEM Branch, gemischt mit seinen uncommitted changes.

**Why:** Passierte 2026-05-30 dreimal in einer Session (Agent an `feat/eu-mapping-audit` mit design_system + mapping_audit WIP). Mein Commit landete auf seinem Branch über fremden Commits; später mischten sich meine Controller-Edits mit seinen uncommitted CSS-Changes. Jedes Mal aufwändig entwirrt.

**How to apply:**
- VOR jedem Commit `git branch --show-current` + `git status` prüfen — bin ich auf MEINEM Branch, sind fremde Changes drin?
- Saubere Isolation: `git stash push -m x <nur meine files>` → `git worktree add -b <mybranch> /tmp/<dir> origin/main` → dort `git stash pop` (stashes sind worktree-shared) → commit + push → `git worktree remove`. Fremder Worktree bleibt 100% unberührt.
- Achtung Branch-Alter: ein vor kurzem gemergtem PR gebranchter fremder Branch hat NICHT die gemergten Changes. Edits dort committen würde sie reverten. Immer Edits frisch auf `origin/main`-Base re-applizieren, nicht den alten working-tree-Stand committen.
- node_modules ist nicht in git → im Worktree symlinken zum Testen: `ln -sf <main>/node_modules node_modules`.
- **Über „origin/main"-Stand IMMER via git lesen, nie über Working-Tree-Files.** Der lokale
  Checkout sitzt evtl. auf einem Fremd-Branch — `cat`/`grep` auf einer Datei liest dann SEINEN
  Stand. 2026-06-20 analysierte ich „774 tote NIS2-Mapping-Refs" auf dem Working-Tree, der auf
  `docs/compliance-catalog-remediation-spec` (Parallel-Session) lag — falscher Branch + Framework-
  Code-Verwechslung (`NIS2` vs `nis2-art21`). Erst `git branch --show-current` prüfen, dann
  `git show origin/main:<file>` / `git grep <pat> origin/main` statt Datei-Reads.
- Siehe [[feedback_no_touching_wip]] — fremde WIP nie stash/mv/rm/reset.
