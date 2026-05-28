# ADR-0008: 48 CI Quality Gates with Baseline Ratchet Pattern

**Status:** Accepted  
**Date:** 2026-02-01  
**Deciders:** moag1000  
**Tags:** ci, quality, phpstan, phpcs, god-class, baseline, ratchet

---

## Context

At the time the quality gates were consolidated, the codebase had accumulated several structural
debts:

- `DataIntegrityService.php` — 1863 LOC (god class with 60+ public methods)
- PHPStan level 5 would fail on ~400 issues in legacy code
- Translation key coverage was < 80% (many hardcoded strings in templates)
- Several controllers directly instantiated `Workflow` / `WorkflowStep` (since deprecated)

Two philosophically different CI approaches were considered:

**Option A: Hard fail on all violations**
Fail the build unless every file is perfectly clean. This is the ideal state but the immediate
cost would be: either (a) block all PRs until 400+ legacy issues are fixed (estimated 2–3 sprints),
or (b) suppress all issues until the fix is complete (effectively turning off the gate entirely).

**Option B: Baseline ratchet**
Record the current violation count as a baseline. New code that introduces new violations fails
the build. Existing violations are tracked but do not fail the build — they are reduced sprint-by-
sprint. The baseline is committed to the repo and bumped only when a violation is genuinely fixed
(ratchet forward) or a false-positive is accepted (ratchet accept, requires justification comment).

The ratchet pattern is established practice for large PHP legacy codebases (PHPStan baseline files,
PHPUnit coverage lower-bound, NPath/CSRS baseline suppression).

---

## Decision

**Implement 48 CI quality gates using the baseline ratchet pattern for legacy technical debt,
and hard-fail for new violations.**

### Gate categories

| Category | Count | Enforcement |
|---|---|---|
| PHP syntax | 1 | Hard-fail: `php -l` on all `src/` files |
| Container wiring | 1 | Hard-fail: `lint:container` |
| Twig template validity | 1 | Hard-fail: `lint:twig templates/` |
| PHPUnit test suite | 1 | Hard-fail: all tests pass |
| PHPStan static analysis | 1 | Baseline ratchet: `phpstan.baseline.neon` |
| PHP_CodeSniffer / CS-Fixer | 1 | Baseline ratchet (new violations = hard fail) |
| Translation coverage | 3 | Soft-warn (dev), hard-fail on regression |
| Twig macro scope (embed) | 1 | Hard-fail: `check_twig_macro_scope.py` |
| Form section parity | 1 | Hard-fail: `check_form_sections.py` |
| Translation domain usage | 1 | Hard-fail: `check_translation_issues.py` |
| God-class LOC baselines | 5 | Baseline ratchet (bump only on reduction) |
| Module gating audit | 1 | Hard-fail: `ModuleGatingAuditTest` |
| Lifecycle state-machine | 2 | Hard-fail: no direct `setStatus()` in `src/` |
| YAML workflow integrity | 1 | Hard-fail: all 15 workflows loadable |
| Misc structural | ~28 | Mix of hard-fail and ratchet |

### God-class baseline ratchet

For god-classes that cannot be split in a single sprint, the baseline LOC is recorded in CI config:

```yaml
# .github/workflows/ci.yml (excerpt)
- name: Check DataIntegrityService LOC
  run: |
    ACTUAL=$(wc -l < src/Service/DataIntegrityService.php)
    BASELINE=1863
    if [ "$ACTUAL" -gt "$BASELINE" ]; then
      echo "God-class grew: $ACTUAL > $BASELINE (baseline)"
      exit 1
    fi
```

The baseline is bumped in commits with message `fix(ci): bump DataIntegrityService god-class
baseline LOC=X→Y` — the commit message format is enforced in PR review.

### PHPStan baseline

`phpstan.baseline.neon` records all existing PHPStan ignores. New code that triggers a PHPStan
error not already in the baseline fails the CI run. The baseline is committed and reviewed in PR
diffs — a growing baseline is a smell.

---

## Consequences

### Positive

- **No big-bang refactor required:** Legacy technical debt does not block day-to-day feature
  development. New features are held to the same high standard as any greenfield code.
- **Visible debt:** Baselines are committed files — the size and trend of `phpstan.baseline.neon`
  and god-class LOC are visible in `git log`. Sprint planning can include deliberate debt
  reduction commits.
- **Ratchet prevents regression:** Once `DataIntegrityService` drops to 1800 LOC, the baseline
  is updated to 1800 and it cannot grow back without a deliberate baseline bump commit with
  justification message.

### Negative

- **Baseline discipline required:** The pattern only works if committers treat the baseline as
  a loan, not a write-off. A culture of "just bump the baseline" defeats the purpose. Commit message
  format convention and PR review enforce this.
- **48-gate maintenance cost:** Each gate has a condition, a threshold file, and possibly a Python
  script. When the project structure changes (new `src/` subdirectory, renamed template), some
  gates need updates. This maintenance load scales with contributor count.
- **False confidence risk:** A passing CI run means "no regressions against baselines" not "the
  codebase is clean". New contributors may misread green CI as an endorsement of legacy code
  quality.

---

## God-Class Reduction Roadmap

| File | Baseline LOC | Target | Strategy |
|---|---|---|---|
| `DataIntegrityService.php` | 1863 | < 600 | Extract `TenantDataRepairService`, `OrphanAssignmentService`, `DuplicateResolutionService` |
| (other god-classes tracked separately in `docs/onboarding/04-hot-files.md`) | — | — | — |

---

## References

- `.github/workflows/ci.yml` — all 48 gate definitions
- `phpstan.baseline.neon` — PHPStan suppression baseline
- `scripts/quality/check_twig_macro_scope.py` — Twig macro scope gate
- `scripts/quality/check_form_sections.py` — form section parity gate
- `scripts/quality/check_translation_issues.py` — translation domain gate
- `docs/onboarding/06-quality-gates.md` — gate explanations for contributors
- `docs/onboarding/04-hot-files.md` — god-class map with caution flags
