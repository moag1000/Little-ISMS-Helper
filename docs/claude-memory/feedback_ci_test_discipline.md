---
name: feedback_ci_test_discipline
description: "CI/Test-Disziplin: voller check_*.py-Sweep lokal vor Push; Integrationstests nie schwere echte Kataloge laden; lange feat-Branches früh rebasen."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 69475b3e-e0e1-42e0-9a07-b613585f0984
---

Hart erlernte CI/Test-Regeln (Juni 2026, mehrere PRs #982-#989):

**1. Voller Gate-Sweep lokal vor JEDEM Push** — nicht nur die offensichtlichen Gates. CI „Code Quality" läuft ALLE `scripts/quality/check_*.py`. Selektives lokales Prüfen ließ `aurora_icon_names` (Icon nicht im Canon `assets/styles/fairy-aurora-icons.css`) + `aurora_icons_only` durchrutschen → CI-rot. Rezept:
```
for s in scripts/quality/check_*.py; do
  n=$(basename "$s" .py|sed 's/^check_//'); b="scripts/quality/baselines/$n.txt"
  [ -f "$b" ] && python3 "$s" --baseline "$b" --quiet || python3 "$s" --quiet
done
```
Pre-existing Debt (z.B. `aurora_icons_only` in persona-cockpit-card/tisax) ist NICHT meins — nur eigene neue Verstöße fixen.

**Why:** Jeder CI-Zyklus ist ~20min (Tests-Job allein ~18min) + feat/* triggert ci.yml nur via `gh workflow run "CI/CD Pipeline" --ref <branch>`. Rote Runde = teuer.

**2. Integrationstests dürfen KEINE schweren echten Kataloge laden.** `LoadStarterPackJobTest` lud echte BSI-Grundschutz (1834 Controls)+ISO+GDPR, 3× (Idempotenz-Re-Run + Sync-Job im Controller-Test) → Tests-Job ~35min. Fix: Pack/Codes injizierbar machen (Constructor-Param mit prod-Default), Test injiziert leichtes `['ISO27001']` (~93 Controls) ODER stubbt den Loader; Pure-Logic separat unit-testen. Controller-Test: `InRequestJobRunner` stubben (non-final) + `$client->disableReboot()` — Test-Container-Override erreicht den per-Class-String aus dem `app.async_job`-Locator aufgelösten Job sonst NICHT. Ergebnis: 35min→~2s. [[feedback_inrequest_runner_failonwarning]]

**3. Lange feat-Branches FRÜH + oft gegen origin/main rebasen.** main läuft schnell weiter (während eines Features mergten #981/#983/#986/#987/#988). 2× Merge-Konflikt am Ende (immer in Line-Baselines: translation_nesting, currentuser_test_args). Fix: bei Konflikt `git checkout --theirs <baseline.txt>` → continue → DANN frisch `--write-baseline` gegen gemergtes Ergebnis. Besser: nach jedem fremden main-Merge sofort rebasen, nicht erst beim PR-Merge. [[feedback_gate26_line_baseline_shift]]

**4. `failOnWarning`/`failOnNotice`=true, aber NICHT `failOnDeprecation`** in phpunit.dist.xml — PHPUnit-Deprecations brechen CI nicht (exit 0), Warnings/Notices schon.

Siehe [[project_junior_mapping_profit]] für den konkreten Fall.
