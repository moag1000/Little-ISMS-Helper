---
name: feedback_ci_gate_on_success
description: "Auto-Merge MUSS auf conclusion==SUCCESS gaten (nicht completion); vor Push die vollen CI-Gates lokal laufen, nicht nur Targeted-Tests."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8524ab1e-7912-42ad-be81-bb1c9410a3e9
---

Dieses Repo hat **keine Branch-Protection** → `gh pr merge` und `gh pr checks --watch` mergen auch bei ROTEM Code-Quality/Tests (Watch exitet 0 bei *completion*, nicht *success*).

**Why:** 2026-05-30 mergte ein PR (#757) mit rotem Tests+Code-Quality durch → main kaputt, alle Folge-PRs gestapelt. Kostete eine lange Fix-Kaskade.

**How to apply:**
- **Auto-Merge nur bei `conclusion=="SUCCESS"`** explizit prüfen, nie auf `--watch`-Exitcode verlassen:
  `F=$(gh pr view N --json statusCheckRollup -q '[.statusCheckRollup[]|select(.conclusion=="FAILURE")]|length'); [ "$F" = "0" ] && gh pr merge N`. Erst pollen bis alle Checks `COMPLETED`.
- **Lokal ≠ CI.** Targeted-Tests + `lint:container` reichen NICHT. Vor Push für Code/Entity-Änderungen die echten Gates fahren:
  - `php bin/phpunit` (FULL suite — Gate 43 Backup-Coverage, Gate-Tests sind nur in der Full-Suite)
  - `python3 scripts/quality/check_no_generic_throws.py --baseline scripts/quality/baselines/no_generic_throws.txt --quiet` (Gate 22)
  - `python3 scripts/quality/check_backup_entity_coverage.py --quiet` (Gate 43 — neue Entity → `BackupService::PRODUCTIVE_ENTITIES`)
  - `vendor/bin/phpstan analyse --configuration phpstan.dist.neon` (mit Baseline)
  - Die `check_*.py`-Gates IMMER mit `--baseline ...` laufen, sonst zeigen sie alle grandfathered Violations.
- **Gate 22** (generic throws): Guard-Throw → Zeile drüber `// @intentional-assertion: ...` ODER Domain-Exception (anders benannt als Generic).
- **Gate 28** (`#[CurrentUser]` arg-count): Heuristik flaggt JEDES `->apply()/->reset()` mit <3 Args als Controller-Action-False-Positive. Service-Methoden → Test-Call-Sites in `scripts/quality/baselines/currentuser_test_args.txt` baselinen (Pattern hat 50+ Einträge).
- **PHPStan: per-File ≠ Full-Project.** `phpstan analyse <changed-files>` ist grün, aber CI fährt
  full-project und failt auf Cross-File-Konsumenten einer geänderten Method/Shape (#988 2026-06-20:
  `?? $code` redundant + unused Test-Helper erst im Full-Run aufgetaucht). Vor Push immer
  `vendor/bin/phpstan analyse --configuration phpstan.dist.neon` (ganzes Projekt), nie nur die Diff-Files.
- Siehe [[feedback_concurrent_agent_worktree]] (worktree-Isolation) und [[feedback_pre_pr_local_checks]].
