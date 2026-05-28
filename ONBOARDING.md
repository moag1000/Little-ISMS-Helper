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
