# CLAUDE.md

Guidelines for Claude Code in this repository.

## Pre-Commit/Push Checklist

**Before EVERY commit:**
- Update relevant documentation (prefer editing existing files over creating new ones)
- Run `php bin/console lint:twig templates/` for template validation
- Optional: Run `python3 scripts/quality/check_translation_issues.py` for translation quality check

**Before EVERY push, verify:**
1. No syntax errors: `find src -name "*.php" -print0 | xargs -0 -n1 php -l`
2. No database errors: `php bin/console lint:container`
3. All templates valid: `php bin/console lint:twig templates/`
4. No runtime errors: `php bin/phpunit` (all tests pass)

## Quick Reference

**Stack:** Symfony 7.4 LTS, PHP 8.4+ (8.5 tested), Doctrine ORM 3.6, Doctrine-Migrations-Bundle 4.0, Twig 3.24, Stimulus 3.2 / Turbo 8, Bootstrap 5.3, FairyAurora v4 Design System, PHPUnit 13.1, Chart.js 4, API Platform 4.3

**Multi-tenancy:** All entities use `tenant_id` field. `TenantContext` service manages context.

**RBAC:** USER → AUDITOR → MANAGER → ADMIN → SUPER_ADMIN, plus holding-level ROLE_GROUP_CISO and ROLE_KONZERN_AUDITOR. 50+ permissions.

## Essential Commands

```bash
# Development
composer install && php bin/console importmap:install
symfony serve

# Database
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:migrations:diff  # after entity changes

# Testing
php bin/phpunit
php bin/phpunit tests/Service/RiskServiceTest.php  # specific test

# Workflows (Regulatory Compliance)
php bin/console app:generate-regulatory-workflows  # Generate all workflows
php bin/console app:generate-regulatory-workflows --workflow=data-breach  # Specific workflow
php bin/console app:process-timed-workflows  # Process time-based auto-progression
php bin/console app:process-timed-workflows --dry-run  # Test without changes

# Quality
php -l src/Service/MyService.php  # syntax check single file
php bin/console cache:clear
php bin/console lint:container    # validate service wiring
php bin/console lint:twig templates/  # validate all templates
```

## Architecture Quick Guide

**Directory Layout:**
- `src/Entity/` - Doctrine entities (78 total, all with `tenant_id`)
- `src/Service/` - Business logic (143 services)
- `src/Controller/` - HTTP handlers (123 controllers)
- `src/Command/` - Console commands (91 total)
- `src/Security/Voter/` - Authorization voters
- `templates/` - Twig templates
- `assets/controllers/` - Stimulus JS controllers
- `translations/` - Translation files (180 YAML files organized by 90 domains)

**Key Services:**
- `RiskService`, `AssetService`, `ControlService` - Core CRUD
- `AuditLogger` - Compliance audit trail
- `BackupService`, `RestoreService` - Data backup/restore
- `TenantContext` - Multi-tenant scoping
- `WorkflowService` - Workflow instance management
- `WorkflowAutoProgressionService` - Event-driven workflow progression

**Core Entities:** Asset, Risk, Control (93 ISO 27001 controls), Incident, Document, ComplianceFramework, User, Tenant, Role, Permission, Workflow, WorkflowInstance, WorkflowStep, DataBreach

## Development Patterns

**Adding Features:**
1. Entity with `tenant_id` → `src/Entity/`
2. Migration: `php bin/console doctrine:migrations:diff`
3. Service for logic → `src/Service/`
4. Controller → `src/Controller/`
5. Templates → `templates/`
6. Translations → `translations/[domain].{de,en}.yaml` (see Translation Domains below)
7. Tests → `tests/`

**Modal Pattern (Important):**
Bootstrap loaded async via ES Module. For inline scripts:
```javascript
// Wait for Bootstrap availability
if (window.bootstrap && window.bootstrap.Modal) {
    const modal = new window.bootstrap.Modal(element);
    modal.show();
}
```

Custom modals (like command palette) use CSS classes instead of Bootstrap Modal JS for reliability.

**Turbo Navigation:**
- Use `turbo:load` event for page-specific JS initialization
- Set `<meta name="turbo-cache-control" content="no-cache">` for dynamic pages

**Routes:**
- Locale-prefixed: `/{locale}/dashboard` (de, en)
- Admin routes: `/admin/` or `/{locale}/admin/`

**Translation Domains:**
The application uses domain-specific translation files instead of monolithic `messages.*.yaml` files.

Structure:
- `translations/` contains 180 YAML files (90 domains × 2 languages)
- Each functional area has its own domain: `nav`, `mfa`, `tenant`, `role_management`, etc.
- `messages.{de,en}.yaml` remains as fallback for common/cross-domain terms

Usage in Twig templates:
```twig
{# ALWAYS specify the domain explicitly #}
{{ 'nav.dashboard'|trans({}, 'nav') }}
{{ 'tenant.title.edit'|trans({}, 'tenant') }}
{{ 'mfa.setup_totp.title'|trans({}, 'mfa') }}

{# messages domain for common terms #}
{{ 'common.save'|trans({}, 'messages') }}
```

Available domains (90 total):
- **Navigation:** nav, dashboard, ui, help, welcome
- **Access Control:** mfa, role_management, session, user, security, four_eyes
- **ISMS Core:** assets, risk, control, incident, audits, audit_log, audit_freeze, soa, context, risk_appetite, risk_treatment_plan
- **BCM:** bcm, bc_plans, bc_exercises, crisis_team, business_process
- **Compliance:** compliance, compliance_import, compliance_inheritance, compliance_policy, compliance_wizard, privacy, interested_parties, objective, consent, data_subject_request, dora, nis2
- **Management:** tenant, admin, management_review, analytics, reports, management_reports, kpi
- **Operations:** document, workflows, training, change_requests, patches, notifications, scheduled_reports, tags
- **Reports:** gap_report, group_report, portfolio_report, security_reports, report_builder
- **Resources:** suppliers, locations, people, physical_access, prototype_protection, industry_baseline
- **Technical:** monitoring, crypto, vulnerabilities, threat, bulk_delete, field, loader_fixer, emails
- **Setup:** setup, setup_wizard, wizard
- **Validators:** validators (form validation messages)

When adding new features:
1. Check if a relevant domain exists
2. If yes: add translations to that domain file
3. If no: create new `[feature].{de,en}.yaml` files
4. Use explicit domain parameter in all `|trans()` calls

Benefits:
- Faster translation lookups (no fallback searching)
- Better IDE autocomplete support
- Clearer organization and maintenance
- Reduced merge conflicts

Translation Quality:
- Use `scripts/quality/check_translation_issues.py` to find:
  - Hardcoded text that should be translated
  - Missing domain parameters in |trans calls
  - Untranslated HTML attributes (title, aria-label, placeholder)
  - Invalid or missing translation domains
- Run before commits to maintain translation quality
- See `scripts/README.md` for details

## Workflow System (Event-Driven Approvals)

**Overview:**
Event-driven workflow system where workflows automatically progress based on user actions. Instead of explicit approvals, workflows advance when relevant entity fields are completed.

**Generate Regulatory Workflows:**
```bash
# Generate all pre-configured regulatory workflows
php bin/console app:generate-regulatory-workflows

# Generate specific workflow (data-breach, incident-high, incident-low, risk-treatment, dpia)
php bin/console app:generate-regulatory-workflows --workflow=data-breach
```

**Available Workflows:**
- **GDPR Data Breach** (Art. 33/34) - 72h notification deadline, 6 steps with auto-progression
- **Incident Response** (High/Critical) - ISO 27001, 6 steps from CISO response to post-incident review
- **Incident Response** (Low/Medium) - ISO 27001, 4 steps standard incident handling
- **Risk Treatment** - ISO 27001 Clause 6.1.3, multi-tier approval based on risk value
- **DPIA** - GDPR Art. 35/36, 6-step data protection impact assessment

**Auto-Progression:**
Workflows auto-progress when entity fields are completed:

```php
// Example: DPO fills DataBreach fields
$dataBreach->setSeverity('high');
$dataBreach->setAffectedDataSubjectsCount(150);
$dataBreach->setDataCategories(['PII']);
$dataBreach->setNotificationRequired(true);

// On save → Step 1 "DPO Assessment" auto-approves
// → Workflow moves to Step 2 "Technical Assessment (CISO)"
```

**Advanced Features** (✅ Implemented):
- ✅ **AND/OR Logic**: Complex conditions like `(severity >= high AND count > 100) OR required = true`
- ✅ **Time-Based**: Auto-progress after time delay (e.g., "24 hours", "7 days")
- ✅ **Entity Coverage**: DataBreach, Incident, Risk, Asset, Control, DPIA, ProcessingActivity
- ✅ **Cron Support**: `app:process-timed-workflows` for time-based automation

**Adding Auto-Progression to Entities:**

1. Inject `WorkflowAutoProgressionService` into service/controller
2. Call after entity update:
   ```php
   $this->workflowAutoProgressionService->checkAndProgressWorkflow($entity, $user);
   ```
3. Define conditions in workflow step metadata (see command or docs)

**Documentation:**
- `docs/WORKFLOW_REQUIREMENTS.md` - Regulatory requirements and SLAs
- `docs/WORKFLOW_AUTO_PROGRESSION.md` - Complete auto-progression guide

## Coding Standards

- PSR-12, Symfony conventions
- 4-space indent, max 120 char lines
- Type hints and return types required
- Commit format: `feat(scope): message` / `fix(scope): message`

## Release-Workflow (since 2026-04-30)

**NIE direkt `git tag vX.Y.Z` + `git push`.** Releases laufen ausschliesslich
ueber drei definierte Kanaele — wenn der User "Release machen" / "publish" /
"taggen" sagt OHNE Form, IMMER zurueckfragen welche der drei Optionen:

| Form | Trigger | Docker-Tags | composer.json bumped? |
|---|---|---|---|
| **Stable** | release-please-PR (`chore(main): release X.Y.Z`) reviewen + mergen | `:vX.Y.Z`, `:X.Y`, `:latest` | ja, automatisch via release-please |
| **Dev** | GitHub Actions → *"Dev Release (manual)"* → bump-Input (patch/minor/major) | `:vX.Y.Z-dev.N`, `:dev` (rolling) | nein |
| **RC** | manuell `git tag vX.Y.Z-rc.1 && git push --tags` | `:vX.Y.Z-rc.N`, `:rc` | nein |

Conventional-Commits auf `main` sammeln; release-please haelt PR
automatisch aktuell. `fix:` → patch, `feat:` → minor, `feat!:` → major.
`chore/ci/test/style/docs` sind hidden im Changelog. CHANGELOG.md NICHT
manuell editieren (ausser fuer Backfill-Edge-Cases) — release-please
verwaltet `[Unreleased]` + Sections.

Cadence-Disziplin: Stable-Releases idealerweise gebuendelt (Mo/Do oder
sobald sinnvoller Batch beisammen) — kein "Maschinengewehr-Tagging" mit
mehreren Releases pro Tag. Hot-Fix nur bei Production-Breaker, sonst PR
sammeln lassen.

Config-Files: `release-please-config.json`, `.release-please-manifest.json`,
`.github/workflows/release-please.yml`, `.github/workflows/dev-release.yml`,
Tag-Regeln in `.github/workflows/ci.yml` (`steps.meta.tags` Block).
Vollstaendige Doku in `CONTRIBUTING.md` § *Release Cadence* + § *Dev /
Pre-Release Builds*.

## Symfony 7.4 Best Practices (Audit Apr 2026)

**Routing:** ALL routes via `#[Route]` attributes — no YAML route definitions.
**Security:** Use `#[IsGranted('ROLE_X')]` attribute, not `denyAccessUnlessGranted()`.
**CSRF:** Use `#[IsCsrfTokenValid('token_id')]` attribute (Symfony 7.1+) where possible.
**Controllers:** Constructor injection with `private readonly`. All extend `AbstractController`.
**Entities:** PHP 8 attributes (`#[ORM\Entity]`), no annotations. Typed properties. `Collection<int, T>` generics.
**Repositories:** `ServiceEntityRepository` pattern. Autowired.
**Forms:** Single-action with `handleRequest()`. Translation domain via FormType.
**Testing:** PHPUnit 13.1. Use `#[Test]` attribute. `WebTestCase` for HTTP, `KernelTestCase` for services.
**Templates:** FairyAurora v4 macros (`_fa_page_header`, `_fa_empty_state`, `_fa_alert`). `_breadcrumb.html.twig` for navigation. `trans_default_domain` at top of every template.
**Translations:** 90 domains x 2 languages. Check `debug:translation --only-missing` before commit. No YAML duplicate keys.
**PHP 8.5:** `readonly` properties, `match` expressions, `enum` types where appropriate.

## Security Checklist

- [ ] Entity has `tenant_id` for tenant isolation
- [ ] CSRF token validated for forms
- [ ] Input sanitized via `InputValidationService`
- [ ] File uploads checked via `FileUploadSecurityService`
- [ ] Sensitive data excluded from audit logs

## Common Pitfalls

1. **EntityManager closes after constraint violation** - Wrap in try-catch and check `$em->isOpen()`
2. **Unique constraint conflicts** - Check both by ID and unique fields before insert
3. **Foreign key order** - Restore/delete entities in dependency order
4. **Bootstrap not loaded** - Check `window.bootstrap` before using modals
5. **Turbo cache issues** - Clear cache or disable for problematic pages
6. **Migrations: avoid `PREPARE/EXECUTE`-pattern** — 17 existing migrations (Phase 8,
   Versions `20260418*`, `20260419*`, `20260420140000`) use dynamic SQL with
   `SET @sql := IF(...) ; PREPARE stmt FROM @sql ; EXECUTE stmt ; DEALLOCATE`
   for "idempotent" ALTER/CREATE. This pattern silently fails in Doctrine
   Migrations: the migration is recorded as `executed` but the actual DDL never
   runs. Symptoms: `Column not found` errors, missing tables.
   - **New migrations**: use plain `ALTER TABLE` / `CREATE TABLE IF NOT EXISTS`
     statements directly. Do NOT wrap in PREPARE/EXECUTE.
   - **Recovery**: run `php bin/console app:schema:reconcile --dry-run` then
     `php bin/console app:schema:reconcile` to bring schema in sync with
     entity metadata. Non-destructive for additive changes.
7. **Bootstrap vs Aurora class precedence** — Bootstrap selectors with higher
   specificity (`.card > .card-footer` = 0,2,0) beat our Aurora `.card-footer`
   (0,1,0). Aurora component CSS must use `.card > .card-{header,footer}`
   patterns to match Bootstrap's specificity. Token-level overrides
   (`--bs-card-*`) are preferred where Bootstrap uses them internally.
8. **Bootstrap utility classes need `--bs-*-rgb` companions** — Bootstrap 5.3
   utilities like `.bg-body-secondary` render as `rgba(var(--bs-secondary-bg-rgb),
   var(--bs-bg-opacity))`. Without the `-rgb` twin of a mapped token, Bootstrap
   falls back to hardcoded defaults and ignores Aurora mapping entirely. All
   color/bg tokens need both `--bs-X` and `--bs-X-rgb` in light AND dark forks.
9. **Do NOT use Bootstrap `bg-*` / `text-white` on `.card` or `.card-header`** —
   Aurora's `.card { background: var(--surface) }` and
   `.card > .card-header { background: var(--surface-2) }` (in
   `fairy-aurora-components.css`) win by load-order + equal specificity. The
   utility class is silently ignored — dev intends a blue hero tile, users see a
   neutral gray card. For KPI/hero tiles use `variant: 'kpi', borderColor:
   '<primary|success|warning|danger|info>'` + `.kpi-card-value` /
   `.kpi-card-label` inside + `text-<color>` on the icon. Utilities on smaller
   elements (`.badge bg-*`, `.progress-bar bg-*`, `.btn btn-*`, `.alert
   alert-*`, spacing/flex) still work. Full anti-pattern list in
   `templates/_components/_CARD_GUIDE.md` §"Anti-Patterns".

## Aurora v4 Components (prefer these for new UI)

Macro library under `templates/_components/_fa_*.html.twig`. Live preview +
copyable snippets at `/dev/design-system` (dev env only).

| Component | Use for | Macro file |
|---|---|---|
| `fa-page-header` | Module landing-page header (badge + title + subtitle + actions) | `_fa_page_header.html.twig` |
| `fa-section` | Section wrapper with title + tools + footer | `_fa_section.html.twig` |
| `fa-feature-card` | KPI tile (replaces legacy `.kpi-card` / `variant:'kpi'`) | `_fa_feature_card.html.twig` |
| `fa-empty-state` | Empty state with Alva mood + CTA | `_fa_empty_state.html.twig` |
| `fa-hero` | Welcome-banner + module intro | `_fa_hero.html.twig` |
| `fa-filter-chip` | Filter chip + chip-group | `_fa_filter_chip.html.twig` |
| `fa-entity-card` | Listen-Item-Card with entity-icon, title, meta, status — for Findings/Risks/Incidents/Audits/NCs | `_fa_entity_card.html.twig` |
| `fa-entity-badge` | ISMS-entity marker (10 types: finding/nonconformity/risk/control/evidence/policy/asset/incident/audit/training) | `_fa_entity_badge.html.twig` |
| `.fa-aurora-surface` | Opt-in page-level Aurora atmosphere (CSS utility, not macro) | — |

Import pattern: `{% import '_components/_fa_feature_card.html.twig' as _fa_feature_card %}`.
Legacy `.kpi-card` / `variant:'kpi'` still works for backward-compat but emits
a dev-env console deprecation warning.

Stylelint (`npm run stylelint`) bans raw hex in 14 color-valued properties
app-wide; use Aurora tokens only. Allow-list: `fairy-aurora.css` (SoT),
`alva.css` (SVG brand fills), vendor bootstrap*.css.

## Configuration Files

- `config/services.yaml` - Service definitions
- `config/packages/security.yaml` - Auth/authz config
- `config/modules.yaml` - Feature module definitions
- `config/active_modules.yaml` - Enabled modules

## When to Consult External Docs

Only fetch additional documentation when:
- Working with unfamiliar Symfony bundles
- Implementing new API Platform features
- Complex Doctrine mapping scenarios
- Security-critical implementations

For standard CRUD, forms, templates, and services - this guide plus codebase exploration should suffice.
