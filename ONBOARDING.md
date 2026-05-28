# Co-Maintainer Onboarding — Little ISMS Helper

## 30-Second Pitch

Little ISMS Helper is a self-hosted, multi-tenant Information Security Management System
(ISMS) platform built for ISO 27001:2022, EU-DORA, NIS2, GDPR, and BSI IT-Grundschutz
compliance. It covers the full compliance lifecycle: risk management, asset inventory,
controls (SoA), audit trails, business continuity, document lifecycle, and regulatory
workflow automation — all under one roof, without SaaS lock-in.

## Tech Stack

Symfony 7.4 LTS · PHP 8.4+ · Doctrine ORM 3.6 · MySQL 8+ · Twig 3 · Stimulus 3 / Turbo 8
· Bootstrap 5.3 · FairyAurora v4 design system · PHPUnit 13.1 · API Platform 4.3

## Hard Requirements

| Requirement | Minimum | Notes |
|---|---|---|
| PHP | 8.4 | 8.5 is tested and supported in CI |
| Database | MySQL 8.0+ or MariaDB 10.11+ | PostgreSQL 16+ also works |
| Composer | 2.x | `composer install` wires everything |

## Five-Step Quick Start

```bash
# 1. Clone and install dependencies
git clone <repo-url> little-isms-helper && cd little-isms-helper
composer install && php bin/console importmap:install

# 2. Configure environment
cp .env .env.local
# Edit .env.local: set DATABASE_URL, APP_SECRET, MAILER_DSN

# 3. Bootstrap the database
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction

# 4. Load reference data (ISO 27001 Annex A controls, BSI catalogues)
php bin/console isms:load-annex-a-controls
php bin/console app:generate-regulatory-workflows

# 5. Start the development server
symfony serve
# App is now at https://127.0.0.1:8000
```

See [docs/onboarding/01-environment-setup.md](docs/onboarding/01-environment-setup.md)
for Docker, native PHP-FPM, and shared-hosting paths.

## Learning Progression

Use this guide to pace your onboarding. These are suggestions, not gates — go at your own speed.

### Day 1 — Get oriented and run the app

1. Follow [01-environment-setup.md](docs/onboarding/01-environment-setup.md) — get a local instance running.
2. Read [02-architecture-tour.md](docs/onboarding/02-architecture-tour.md) — understand the 6-layer structure.
3. Read [09-anti-patterns.md](docs/onboarding/09-anti-patterns.md) — learn the 12 named pitfalls before touching code.
4. Complete [11-first-pr-walkthrough.md](docs/onboarding/11-first-pr-walkthrough.md) — ship a tiny change (a missing translation key) to verify your whole toolchain works.

### Day 7 — Navigate and contribute confidently

5. Read [03-where-things-live.md](docs/onboarding/03-where-things-live.md) — find any file in 30 seconds.
6. Read [04-hot-files.md](docs/onboarding/04-hot-files.md) — identify god-classes and files that need care.
7. Read [05-test-runbook.md](docs/onboarding/05-test-runbook.md) — know how to run targeted vs full test suites.
8. Read [06-quality-gates.md](docs/onboarding/06-quality-gates.md) — understand all 48 CI gates before you accidentally trip one.
9. Read [10-pr-template-guide.md](docs/onboarding/10-pr-template-guide.md) — fill in a PR review template correctly.

### Day 30 — Understand the WHY and take ownership

10. Read the [Architecture Decision Records](docs/adr/README.md) — understand why the codebase looks the way it does.
11. Read [07-personas-and-skills.md](docs/onboarding/07-personas-and-skills.md) — understand the 5 user personas and persona-driven review pattern.
12. Read [08-release-cadence.md](docs/onboarding/08-release-cadence.md) — take ownership of the weekly release cycle.
13. Read [12-maintainer-handoff.md](docs/onboarding/12-maintainer-handoff.md) — understand production topology, backup procedures, and the regulatory domain knowledge required.

---

## Documentation Map

| File | Contents |
|---|---|
| [01-environment-setup.md](docs/onboarding/01-environment-setup.md) | Docker / native / shared-hosting setup; DB bootstrapping; common pitfalls |
| [02-architecture-tour.md](docs/onboarding/02-architecture-tour.md) | 6-layer architectural tour; ASCII diagram; where to start reading |
| [03-where-things-live.md](docs/onboarding/03-where-things-live.md) | 30-namespace index; directory map; config file reference |
| [04-hot-files.md](docs/onboarding/04-hot-files.md) | God-classes and most-edited files; caution flags |
| [05-test-runbook.md](docs/onboarding/05-test-runbook.md) | Full suite; targeted subsets; fixtures; Playwright screenshots |
| [06-quality-gates.md](docs/onboarding/06-quality-gates.md) | 48+ CI gates explained; baseline workflow; how to add a gate |
| [07-personas-and-skills.md](docs/onboarding/07-personas-and-skills.md) | Persona-skills; RBAC persona-roles; multi-agent review pattern |
| [08-release-cadence.md](docs/onboarding/08-release-cadence.md) | Three release tracks; version bump; cadence discipline |
| [09-anti-patterns.md](docs/onboarding/09-anti-patterns.md) | 12 named pitfalls extracted from CLAUDE.md; gate that catches each |
| [10-pr-template-guide.md](docs/onboarding/10-pr-template-guide.md) | PR sections; Conventional Commits; co-authorship; house rules |
| [11-first-pr-walkthrough.md](docs/onboarding/11-first-pr-walkthrough.md) | Concrete first-contribution walkthrough: fork → fix → test → PR |
| [12-maintainer-handoff.md](docs/onboarding/12-maintainer-handoff.md) | Production topology; backup/restore; release rollback; domain expertise gaps; what the maintainer holds in their head |

### Architecture Decision Records

The `docs/adr/` directory contains 12 Architecture Decision Records (ADRs) explaining the WHY
behind key design choices. Start with the [ADR index](docs/adr/README.md).

Key ADRs for new contributors:
- [ADR-0001](docs/adr/0001-symfony-7-4-lts-pin.md) — Why Symfony 7.4 LTS (not 8.x)
- [ADR-0002](docs/adr/0002-multi-tenant-tenant-id-pattern.md) — Multi-tenancy: every entity has `tenant_id`
- [ADR-0003](docs/adr/0003-fairyaurora-v4-design-system.md) — FairyAurora v4: why a custom design system
- [ADR-0007](docs/adr/0007-module-gating-pattern.md) — 40+ module gates: why features are opt-in per tenant
- [ADR-0010](docs/adr/0010-tisax-byo-vs-pre-seeded.md) — TISAX BYO-Wizard: why VDA-ISA content is never shipped; see also [ENX VDA-ISA Licensing Analysis](docs/tisax/ENX_VDA_ISA_LICENSING_ANALYSIS.md)
- [ADR-0011](docs/adr/0011-hmac-chained-audit-log.md) — HMAC-chained audit log: tamper evidence for ISO 27001

## Glossary

| Term | Meaning |
|---|---|
| **ISMS** | Information Security Management System — the overarching framework (ISO 27001) |
| **ISB** | Informationssicherheitsbeauftragter — German term for Information Security Officer |
| **CISO** | Chief Information Security Officer — executive security role |
| **DPO** | Data Protection Officer — mandatory role under GDPR Art. 37 |
| **SoA** | Statement of Applicability — ISO 27001 Annex A control justification document |
| **BCM** | Business Continuity Management — ISO 22301 discipline |
| **DSGVO** | Datenschutz-Grundverordnung — German name for GDPR |
| **NIS2** | EU Network and Information Security Directive 2 (2022/2555) |
| **DORA** | EU Digital Operational Resilience Act (2022/2554) — financial-sector ICT rules |
| **BSI** | Bundesamt fuer Sicherheit in der Informationstechnik — German federal cybersecurity agency |
| **IT-Grundschutz** | BSI baseline protection methodology; see BSI 200-1/2/3/4 |
| **TISAX** | Trusted Information Security Assessment Exchange — automotive-sector standard |
| **RBAC** | Role-Based Access Control — permission model used throughout this application |
| **Tenant** | Isolated organisational unit in the multi-tenant data model (UI label: "Organisation") |
| **Module** | Optional feature gate (40 keys in `config/modules.yaml`) enabling compliance frameworks |
| **Lifecycle** | Symfony Workflow state machine for entity status transitions via `LifecycleService` |
| **Aurora** | FairyAurora v4 — the project's custom design system built on Bootstrap 5.3 |
| **Alva** | The in-app hint assistant ("Alva-Tipp") powered by rule-based `AlvaHintService` |
