# ADR-0001: Pin Symfony to 7.4 LTS

**Status:** Accepted  
**Date:** 2026-04-01  
**Deciders:** moag1000 (sole maintainer)  
**Tags:** framework, symfony, versioning

---

## Context

Symfony follows a predictable release cadence: one minor per six months, with every even minor
(6.4, 7.4, 8.4, …) designated as Long-Term Support (LTS). LTS branches receive security fixes for
three years and bug-fixes for two years. Symfony 7.4 was released November 2024 and is LTS until
November 2027 (security) / November 2026 (bug-fixes).

At the time this decision was made:

- **Symfony 8.0** was available but is a "short-term" release with no LTS guarantee, expected to
  evolve rapidly before 8.4 LTS stabilises (estimated Q4 2026).
- The application depends on 143 services, 78 entities, 123 controllers, and integration packages
  (API Platform 4.3, Doctrine ORM 3.6, Doctrine Migrations Bundle 4.0) whose Symfony 8 compatibility
  was unproven or still carried deprecations.
- Customers in DACH-regulated sectors (BaFin, BSI, KRITIS) often impose contractual stability
  requirements. Running non-LTS framework versions on compliance-relevant software has been
  questioned during audits.
- PHP 8.5 compatibility was already confirmed against the 7.4 branch, so "latest PHP" was not a
  blocker.

A memory note (`project_symfony_lts_pin.md`) records an explicit maintainer preference: no 8.0
bump without a direct user mandate, and major dependency bumps should be evaluated independently.

---

## Decision

**Lock `symfony/framework-bundle` (and all `symfony/*` components) to `^7.4` in `composer.json`.**

No automatic upgrade to 8.0 or 8.x until:
1. Symfony 8.4 LTS is released and stabilised (estimated Q4 2026 / Q1 2027).
2. All tier-1 dependencies (API Platform, Doctrine, Stimulus UX) publish compatible releases.
3. A dedicated upgrade branch is prepared with full CI green on the new version.

The approach mirrors Red Hat's support model: take the LTS, stay until the next LTS, skip the
short-term releases entirely.

---

## Consequences

### Positive

- **Predictable security horizon:** 7.4 security support runs to November 2027 — three full years.
- **Audit defensibility:** LTS runtime is easier to justify to ISO 27001 internal auditors under
  Clause 8.1 (operational planning and control) than a rolling-release framework.
- **No surprise breakage:** The 7.x deprecation layer is stable; `deprecation-contracts` warnings
  are treated as opt-in signals, not forced migrations.
- **CI cost:** No need to maintain parallel matrix for 7.x and 8.x.

### Negative

- **Missed features:** Symfony 8.x introduces `AsAlias` attribute improvements, enhanced
  `MapRequestPayload` capabilities, and new autowiring heuristics. These are not available on 7.4.
- **Compounding delta:** The longer the pin, the larger the eventual upgrade diff. This is mitigated
  by keeping the codebase aligned with 7.4 best-practices (attribute-based routing/security) rather
  than relying on removed patterns.
- **Dependency ceiling:** Some community packages may drop 7.x support before the LTS window closes.
  Monitor `composer outdated` monthly.

---

## How to Reevaluate

Upgrade should be reconsidered when:

1. `composer show symfony/framework-bundle | grep "versions"` shows 7.4 outside active support.
2. A direct issue — security CVE not backported to 7.4, or a required upstream package dropping 7.x
   support — forces the hand.
3. Maintainer explicitly assigns time to a `chore/symfony-8-upgrade` sprint.

**Upgrade checklist** (when time comes):
```bash
composer require symfony/framework-bundle:^8.4 --no-update
composer update symfony/* --dry-run
php bin/console debug:container --deprecations   # review all warnings
php bin/phpunit                                  # full suite green required
```

---

## References

- [Symfony Release Policy](https://symfony.com/doc/current/contributing/community/releases.html)
- Memory note: `.claude/projects/…/memory/project_symfony_lts_pin.md`
- `composer.json` — `require` block, `symfony/*` constraints
- CLAUDE.md §"Stack" and §"Symfony 7.4 LTS-Pin"
