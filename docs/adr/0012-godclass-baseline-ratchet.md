# ADR-0012: God-Class Acceptance and Baseline Ratchet

**Status:** Accepted  
**Date:** 2026-04-01  
**Deciders:** moag1000  
**Tags:** architecture, god-class, refactoring, technical-debt, ci

---

## Context

Several service classes in the codebase grew significantly beyond the Single Responsibility
Principle during early rapid development. The most significant:

| File | LOC | Public methods | Description |
|---|---|---|---|
| `DataIntegrityService.php` | 1863 | 60+ | Tenant data repair, orphan assignment, duplicate resolution, schema drift detection |
| *(others tracked in `docs/onboarding/04-hot-files.md`)* | | | |

These classes are problematic for several reasons:
- Long methods that are difficult to unit-test in isolation
- Cross-cutting concerns mixed in one class (e.g., DataIntegrityService handles both tenant data
  repair and schema drift, which are logically separate concerns)
- High cognitive load for new contributors
- Changes to one concern risk regressions in another

### Why not split them immediately?

Splitting a 1863-LOC service class is a multi-sprint refactoring effort:
1. Identify clean split boundaries (not always obvious — many methods share private helpers)
2. Design the new class hierarchy (avoid circular dependencies)
3. Rewrite 143+ injection sites that depend on the original class
4. Write tests for the new classes that currently lack unit-test coverage
5. Verify that the split does not break the async job runner (some jobs inject the god class)

Doing this during active feature development risks introducing regressions and creating large,
hard-to-review diffs. On a solo/small team project, this is a particularly high risk.

### Why not simply accept the god class forever?

Allowing unlimited growth with no constraint produces the worst outcome: god-classes grow
indefinitely, making the codebase progressively harder to onboard into and harder to change.
Maintainers have observed that services exceeding 2000 LOC become effectively unreviewable — PRs
that touch them receive "LGTM" reviews without genuine understanding of the impact.

---

## Decision

**Accept existing god-classes temporarily under a LOC baseline ratchet enforced in CI, with a
documented reduction roadmap.**

### The ratchet mechanism

For each identified god-class, the current LOC is recorded as a baseline in the CI configuration.
The build fails if the file grows beyond the baseline. The baseline can only be reduced — commits
that reduce the LOC below the baseline update the baseline downward. Commits that attempt to
increase the baseline require an explicit justification in the commit message format:
`fix(ci): bump <ClassName> god-class baseline LOC=X→Y`.

This creates a ratchet: the god-class can only stay the same size or shrink, never grow, without
a deliberate and visible decision. Growth is still possible when genuinely necessary (e.g., a
compliance regulation adds new obligations) but it is not silent.

### Split decision criteria

When to split a class vs accept-and-ratchet:

**Split immediately if:**
- The class has two clearly independent concerns with no shared private state
- Unit test coverage can be written for the extracted class without complex fixtures
- The extraction is < 1 sprint effort (estimated < 2 days of focused work)

**Accept-and-ratchet if:**
- The extraction requires rewriting many injection sites across the codebase
- The class has heavily entangled private helpers that make a clean split unclear
- The class is in active feature development (splitting a moving target doubles the work)

**Never accept-and-ratchet if:**
- The class mixes security-critical logic with non-security logic
- The class has untested edge cases that a split would make testable
- The class is cited in audit findings as "complex and hard to verify"

### Reduction roadmap for `DataIntegrityService`

| Phase | Target | Extracted classes |
|---|---|---|
| Phase 1 | 1500 LOC | Extract `TenantDataRepairService` (~200 LOC of tenant-specific orphan repair) |
| Phase 2 | 1200 LOC | Extract `OrphanAssignmentService` (~150 LOC of orphan-to-tenant assignment logic) |
| Phase 3 | 900 LOC | Extract `DuplicateResolutionService` (~150 LOC) |
| Phase 4 | < 600 LOC | Remaining core — schema drift detection + repair coordination |

Each phase is a standalone PR, reviewable independently. Target: complete by v4.0.

---

## Consequences

### Positive

- **No surprise growth:** The CI baseline prevents silent accumulation. A PR that adds 100 lines
  to `DataIntegrityService` fails CI until the baseline is updated with a justification commit.
- **Visible debt trend:** The baseline values in `ci.yml` show the size trend over time. A
  reviewer looking at `git log -- .github/workflows/ci.yml` can see whether debt is being paid
  down or accumulated.
- **Deliberate split timing:** Extractions happen when there is dedicated sprint capacity, not
  during unrelated feature work.

### Negative

- **Ratchet can be gamed:** A contributor can set `BASELINE=2000` in a single commit and the
  check passes. The commit message format convention and PR review process are the only guards
  against this.
- **LOC is an imperfect metric:** A 1863-LOC file with clear separation and good test coverage
  is better than a 400-LOC file with tangled logic and no tests. LOC is a proxy for complexity,
  not complexity itself. But it is the simplest automatable metric.
- **Roadmap may slip:** Phase targets are estimates. Regulatory compliance work may take priority.
  The ratchet ensures the file at least does not grow, even if the reduction roadmap slips.

---

## How to Work Safely with God-Classes

When editing a god-class:
1. Read the entire class first — private helpers are often reused in non-obvious ways.
2. Write a regression test for the method you are touching before making changes.
3. Keep your change to the smallest possible scope — resist the temptation to "clean up while
   you're in there". Submit cleanup as a separate PR.
4. Check the CI baseline after your change — if your new feature requires adding lines, write
   a justification commit message.

---

## References

- `.github/workflows/ci.yml` — LOC baseline checks (search: "god-class")
- `docs/onboarding/04-hot-files.md` — full god-class map with per-file caution flags
- `src/Service/DataIntegrityService.php` — primary subject of this ADR
- Recent bump commit: `b3abf923 fix(ci): bump DataIntegrityService god-class baseline LOC=1861→1863`
- ADR-0008 — CI quality gates + baseline ratchet (general policy; this ADR is god-class specific)
