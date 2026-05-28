# 10 — PR Template Guide

Full contributor guidelines are in [CONTRIBUTING.md](../../CONTRIBUTING.md).
This document summarises the PR-specific conventions and house rules
that are not immediately obvious from the template.

---

## PR Title

Follow the same Conventional Commits format as commit messages:

```
feat(risk): add automatic risk recalculation on asset change
fix(audit): correct date filtering in audit list
docs(onboarding): add co-maintainer guide
refactor(lifecycle): extract LifecycleConfigResolver
```

Keep titles under 72 characters. The title drives the CHANGELOG entry for
`feat:` and `fix:` types.

---

## Expected PR Description Sections

```markdown
## Description
One paragraph: what changed and why, from the user's perspective.

## Type of Change
- [ ] Bug fix (non-breaking)
- [ ] New feature (non-breaking)
- [ ] Breaking change (existing behaviour changes)
- [ ] Documentation update
- [ ] Refactor / performance (no behaviour change)

## Related Issues
Closes #123
<!-- or: Refs #456 if not fully resolved -->

## Changes Made
- Bullet list of significant additions / modifications
- Include entity changes, migration files, new services, template changes
- If you bumped a god-class baseline: explain why growth was justified

## Pre-PR Checklist (local)
- [ ] `find src -name "*.php" -print0 | xargs -0 -n1 php -l` — no syntax errors
- [ ] `php bin/console lint:container` — no DI wiring errors
- [ ] `php bin/console lint:twig templates/` — all templates valid
- [ ] Targeted tests pass: `php bin/phpunit tests/[affected-directory]/`
- [ ] `python3 scripts/quality/check_twig_macro_scope.py` — embed scope clean
- [ ] No competitor product names in changed files

## Testing
- [ ] Targeted unit/integration tests added or updated
- [ ] Manual testing completed (describe scenario briefly)
- [ ] Screenshots attached if UI changed

## Migration Notes (if applicable)
- Migration file: `migrations/Version20260601120000.php`
- `isTransactional(): false` added for DDL? Yes / No
- Baseline bumps: `scripts/quality/baselines/god_class_size.txt` — justified by [reason]
```

---

## Conventional Commits Enforcement

Commit types and their CHANGELOG visibility:

| Type | Version bump | CHANGELOG section | Visible to users |
|---|---|---|---|
| `feat:` | minor | "Features" | Yes |
| `fix:` | patch | "Bug Fixes" | Yes |
| `feat!:` / `BREAKING CHANGE:` | major | "Breaking Changes" | Yes |
| `refactor:` | none | hidden | No |
| `perf:` | none | hidden | No |
| `test:` | none | hidden | No |
| `docs:` | none | hidden | No |
| `chore:` | none | hidden | No |
| `ci:` | none | hidden | No |
| `style:` | none | hidden | No |

Use `feat:` only for genuinely user-visible additions. Use `fix:` only for
actual bug corrections. Misusing these types inflates version numbers
artificially.

---

## AI Assistance Co-Authorship

When a PR was partially or fully generated with AI assistance, add a
`Co-Authored-By` trailer to the squash commit message:

```
feat(bcm): add BCM officer persona dashboard

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

This is required for transparency and audit trail purposes under the project's
AI-usage disclosure policy.

---

## House Rules

### No Competitor Product Names

Do not name competing ISMS tools, GRC platforms, or ISMS-adjacent SaaS
products in source code, comments, documentation, or CHANGELOG entries.
Referencing standards and frameworks (ISO, BSI, NIST, NIS2, DORA) is fine.

CI gate: `check_no_competitor_names.sh` — direct fail on push.

### No WIP Commits in PRs

Squash WIP/temp/fixup commits before requesting review. Use
`git rebase -i HEAD~N` to clean up. Each commit in the PR should be
independently deployable (even if not merged individually).

### One Logical Change Per PR

Split large features into reviewable units:
- Entity + migration (one PR)
- Service + tests (one PR)
- Controller + templates (one PR)

This is a guideline, not a hard rule — use judgement for tightly coupled
changes.

### Rebase Before Merge

Always rebase onto `main` before requesting final review:
```bash
git fetch origin
git rebase origin/main
```

The maintainer will squash-merge into `main`. Do not merge `main` into your
feature branch (creates noise in the linear history).

### Auto-Merge on Green CI

PRs from co-maintainers with fully green CI may be squash-merged by the
author without waiting for a second review, unless the PR touches:
- Security voters or authentication
- Multi-tenant isolation logic
- Database migrations with destructive DDL

Those categories require at least one explicit maintainer approval.

---

## Cross-Links

- Full contributing guide: [CONTRIBUTING.md](../../CONTRIBUTING.md)
- Release cadence: [docs/onboarding/08-release-cadence.md](08-release-cadence.md)
- Quality gates: [docs/onboarding/06-quality-gates.md](06-quality-gates.md)
- Anti-patterns: [docs/onboarding/09-anti-patterns.md](09-anti-patterns.md)
- Module gating guide: [docs/MODULE_GATING_GUIDE.md](../MODULE_GATING_GUIDE.md)
