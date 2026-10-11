---
name: release-cadence-discipline
description: Manual release-trigger only for urgent fixes. All else waits Mo-Cycle (release-please-auto-merge weekly).
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 00707aab-5219-4853-97ae-8ba832ca1266
---

Manuelle Release-Trigger NUR für urgent fixes (production-bugs, security-incidents, blocker für anderen workflow).

**Why:** Cadence-Disziplin verhindert "Maschinengewehr-Tagging" und schafft predictable release-rhythm. release-please-auto-merge.yml läuft Mo 09:00 UTC + bündelt alle Commits seit letztem Tag.

**How to apply:**

| Release-Typ | Trigger | Cadence |
|---|---|---|
| **Urgent fix** (production-bug, security, blocker) | manuell sofort | on-demand |
| **Reguläre fixes** (`fix:` commits) | release-please-auto-merge | **Mo 09:00 UTC** |
| **Features** (`feat:` commits) | release-please-auto-merge | **Mo 09:00 UTC** |
| **Major/Breaking** | RC-Tag erst, dann manuell | mehrwöchig |

**Workflow nach PR-Merge:**
1. PR squash-merged → release-please-bot erstellt `chore(main): release X.Y.Z`-PR mit `autorelease: pending` label
2. **NICHT manuell `gh workflow run release-please-auto-merge.yml` triggern** (außer urgent)
3. Mo 09:00 UTC bündelt Cycle alle Release-PRs automatisch

**3.5.2 = urgent fix** (Defekte in 3.5.0 → manuell). 3.6.0 = Sprint-2 feature-bundle → **HÄTTE Mo-Cycle abwarten sollen**, ist aber irreversibel released. Lehre für Sprint 3+.

**Block via Label:** `release-blocked` oder `do-not-merge` auf Release-PR setzen wenn Mo-Cycle übersprungen werden soll (z.B. ungeklärter Bug noch reinmuss).

Related: [[feedback_release_workflow]] (3-Form-Decision-Pattern), [[project_certification_blockers]]
