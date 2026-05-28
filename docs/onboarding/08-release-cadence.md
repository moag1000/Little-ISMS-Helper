# 08 — Release Cadence

Releases are managed by `release-please` and two supplementary GitHub Actions
workflows. **Never create tags manually** unless specifically cutting an RC.
Always ask which track is intended when the request is ambiguous.

---

## Three Release Tracks

| Track | Trigger | Docker tags produced | `composer.json` bumped? |
|---|---|---|---|
| **Stable** | `release-please` PR auto-merges Monday 09:00 UTC | `:vX.Y.Z`, `:X.Y`, `:latest` | Yes — automatically |
| **Dev** | GitHub Actions → "Dev Release (manual)" → choose patch/minor/major | `:vX.Y.Z-dev.N`, `:dev` (rolling) | No |
| **RC** | Manual: `git tag vX.Y.Z-rc.1 && git push --tags` | `:vX.Y.Z-rc.N`, `:rc` | No |

---

## Stable Release (Weekly, Automated)

Conventional Commits on `main` accumulate throughout the week. `release-please`
maintains an open PR that updates `CHANGELOG.md` and bumps `composer.json`
`version` automatically.

Commit type → version bump:
- `fix:` → patch (3.x.Y → 3.x.Y+1)
- `feat:` → minor (3.x.Y → 3.x+1.0)
- `feat!:` or `BREAKING CHANGE:` → major (3.x.Y → 4.0.0)

Hidden in CHANGELOG (do not use these for user-visible changes):
`chore`, `ci`, `test`, `style`, `docs`

**Monday cycle:** The `release-please-auto-merge.yml` workflow automatically
merges the release-please PR at Monday 09:00 UTC.

To **skip** a Monday release: add the label `release-blocked` or `do-not-merge`
to the release-please PR before Monday 09:00 UTC.

To **force** a release before Monday: trigger `release-please-auto-merge.yml`
via `workflow_dispatch` in GitHub Actions.

---

## Dev Release (Manual, On-Demand)

For pre-release builds shared with beta testers or for testing deployment
pipelines:

1. Go to GitHub Actions → "Dev Release (manual)"
2. Click "Run workflow"
3. Select bump: `patch` / `minor` / `major`
4. The workflow tags `vX.Y.Z-dev.N` and pushes `:dev` Docker image

Do not use dev releases as a workaround for the weekly cadence. They are
intended for infrastructure testing and early-access builds.

---

## RC Release (Pre-Certification)

When preparing for an ISO 27001 audit or a major partner rollout:

```bash
# Tag and push (triggers Docker build for :rc and :vX.Y.Z-rc.N)
git tag v3.9.0-rc.1
git push origin v3.9.0-rc.1
```

RC tags do not bump `composer.json`. Multiple RC iterations are normal:
`-rc.1`, `-rc.2`, etc.

---

## Version as Single Source of Truth

`composer.json` `"version"` field is the single source of truth for the
application version. It is consumed by:

- `app_version` Twig global (displayed in footer, emails, branding)
- Docker image labels
- CHANGELOG headers (via release-please)

**Before any manual tag** (RC or otherwise), verify `composer.json` `"version"`
reflects the intended release version. For stable releases, release-please
handles this automatically.

---

## Key Configuration Files

| File | Purpose |
|---|---|
| `release-please-config.json` | release-please project configuration |
| `.release-please-manifest.json` | Current version manifest (managed by release-please) |
| `.github/workflows/release-please.yml` | release-please bot workflow |
| `.github/workflows/release-please-auto-merge.yml` | Monday auto-merge trigger |
| `.github/workflows/dev-release.yml` | Manual dev release workflow |
| `.github/workflows/ci.yml` | CI including Docker tag strategy |

---

## Cadence Discipline

- **No "machine-gun tagging"** — releasing more than once per week dilutes
  the changelog and confuses users tracking `CHANGELOG.md`.
- **Hot-fix before Monday:** Use `workflow_dispatch` on `release-please-auto-merge.yml`
  to force a release. Document the urgency reason in the PR.
- **CHANGELOG.md is managed by release-please** — do not edit it manually
  except for backfill edge-cases (document the reason in the PR if you do).

---

## CHANGELOG Format Reference

release-please generates sections automatically based on Conventional Commits:

```markdown
## [3.9.0] — 2026-06-02

### Features
- feat(bcm): add BCM officer persona dashboard

### Bug Fixes
- fix(risk): correct treatment plan approval redirect

### Breaking Changes
- feat!: remove legacy WorkflowStep direct instantiation
```

Hidden commit types (`chore`, `ci`, `docs`, `test`, `style`) do not appear in
CHANGELOG. Use `feat:` for user-visible additions and `fix:` for bug fixes.
