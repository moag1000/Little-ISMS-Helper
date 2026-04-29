<!--
Thanks for the contribution!

Conventional-Commits style for the PR title is preferred:
  feat(scope): ...
  fix(scope): ...
  refactor(scope): ...
  chore(scope): ...
-->

## Summary

<!-- 1–3 bullets, what changes and why. -->

-
-

## Type of change

- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Refactor (no functional change)
- [ ] Breaking change (fix or feature that would change existing behaviour)
- [ ] Documentation update
- [ ] CI / tooling

## How tested

<!-- Local tests run, manual UI walkthrough, screenshots if UI changes. -->

- [ ] `php bin/phpunit` (or relevant subset) passes locally
- [ ] `php bin/console lint:container` clean
- [ ] `php bin/console lint:twig templates/` clean (for template changes)
- [ ] Manual smoke-test in browser (for UI changes)

## Compliance / Security checklist

- [ ] No new entity without `tenant_id`
- [ ] CSRF token validated on form/JSON POST endpoints
- [ ] No secrets in code, env, or fixtures
- [ ] No `withoutTenantFilter()` bypass without justification in commit message
- [ ] Audit-log entry written for sensitive admin actions

## CHANGELOG

- [ ] Updated `CHANGELOG.md` in the `[Unreleased]` block
- [ ] Or: not user-visible (internal refactor / test-only)

## Related

<!-- Closes #123, refs #456 -->
