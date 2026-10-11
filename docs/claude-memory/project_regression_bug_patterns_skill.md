---
name: project-regression-bug-patterns-skill
description: "Skill `regression-bug-patterns` ist Living-Checklist mit allen Bug-Mustern aus 2026-05 Sweep (Status-Drift, FormType-Status-Hijack, FK-Order, JS-Syntax-Error in Twig, etc.). Tester/Reviewer-Tool zum Smoke-Test und PR-Review."
metadata: 
  node_type: memory
  type: project
  originSessionId: df58c764-60e6-489c-9ac8-c65766309351
---

Skill `.claude/skills/regression-bug-patterns/SKILL.md` enthält 50+ Bug-Patterns
aus heutiger Session (PRs #466-#502), kategorisiert:

1. Lifecycle/Status-Drift (FormType-Hijack, FourEyes-Edge-Cases)
2. RBAC/Role-Scope (ROLE_ADMIN vs SUPER, Persona-Scope-Widening)
3. Test-Fixture-Leaks (setUp ohne tearDown, findAll-Lookup)
4. Twig+JS (JS-Syntax-Error, Form ohne action, Bootstrap-Race, Turbo-Reset)
5. Twig embed scope (Macro + trans_default_domain)
6. Backup/Restore Completeness (Gate 43)
7. Routing/Locale (locale-prefix, methods:)
8. Migrations (isTransactional, PREPARE/EXECUTE-Trap)
9. Aurora vs Bootstrap CSS
10. CI Baseline-Drift (Line-shift Pattern)
11. Workflow/Lifecycle YAML-Setup
12. PHP/Symfony Constraints (LTS-Pin, Competitor-Ban, WIP-Disziplin)
13. Branch + Worktree Hygiene

**Aktivieren via**: `/regression-bug-patterns` ODER User-Trigger
"regression check"/"PR review checklist"/"was kann hier kaputt sein".

**Anwendung**: Living document. Wenn neuer Bug bitet → eintragen mit Symptom +
Anti-/Correct-Pattern + Probe + Referenz-PR. Bonus: passende CI-Gate dazu
schreiben damit Pattern nicht silent zurückkommt.

Verwandt: [[project_persona_skills]] (Persona-Tool-Tester nutzt diese
Patterns), MEMORY.md feedback-Einträge (LTS-Pin, Competitor-Ban,
WIP-Disziplin sind dort ebenfalls).
