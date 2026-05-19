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

**RBAC:** USER → AUDITOR → MANAGER → ADMIN → SUPER_ADMIN, plus holding-level
ROLE_GROUP_CISO + ROLE_KONZERN_AUDITOR, plus persona-roles
ROLE_CISO / ROLE_RISK_MANAGER / ROLE_DPO / ROLE_COMPLIANCE_MANAGER (each
gates own dashboard at `/dashboards/<persona>`). 50+ permissions.

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
python3 scripts/quality/check_twig_macro_scope.py  # embed-macro-import scope (CI-gate since v3.5)
python3 scripts/quality/check_translation_issues.py  # i18n quality
```

## Operator-UI (CLI-Vermeidung)

Self-hosted-Operator hat `/quick-fix` als Web-UI fuer:
- Pending-Migrations anwenden (mit Auto-Chain Reconcile bei non-destructive Drift)
- Schema-Drift reconcilen (mit destructive-Confirm-Checkbox bei DROP/TRUNCATE)
- DataRepair: Orphans assignen, Tenant-Mismatches fixen, Duplikate bereinigen
- "Alles-Sicher-Reparieren" Convenience-Button

CLI nur fuer destructive-edge-cases wenn UI-Auto-Recovery scheitert. Doku:
`docs/user-guide/QUICK_FIX.md`.

## Async Admin Jobs

Long-running admin tasks (> PHP-FPM 30 s limit) run asynchronously and report
status via `var/jobs/<uuid>.json`. The frontend polls
`GET /admin/jobs/{uuid}/status` every 3 s (Stimulus `async-job` controller).

**Two execution strategies, chosen by the `app.async_job.runner` parameter:**

| Strategy            | Use when                              | Worker needed? |
|---------------------|---------------------------------------|----------------|
| `in_request` (default) | Shared hosting / no shell access     | No — uses `fastcgi_finish_request()` |
| `messenger` (opt-in) | Multi-server / dedicated worker box  | Yes — `messenger:consume async` |

Switch via env: `APP_ASYNC_JOB_RUNNER=messenger`.

### In-request runner (default — shared-hosting friendly)

The runner flushes the response to the browser, calls
`fastcgi_finish_request()` (or `litespeed_finish_request()`), then keeps the
PHP-FPM worker executing the job until completion. Falls back to synchronous
execution under CLI / non-FPM SAPIs so phpunit + console commands work
unchanged.

`InRequestJobRunner` lives at `src/Service/Job/InRequestJobRunner.php`.
The detach helper is shared with the setup-wizard via
`App\Controller\Trait\DetachableResponseTrait`.

### Adding a new async admin job

1. Create `src/Job/MyJob.php` implementing `App\Job\AsyncJobInterface`
2. Inject services via constructor (autowired automatically)
3. Implement `run(JobContext $ctx)` — call `$ctx->progress()` and `$ctx->message()`
4. In the controller — render the progress page **before** dispatch:
   ```php
   $jobId = $this->jobStatusService->create('admin.my_job', $payload);
   $response = $this->render('...progress.html.twig', [
       'jobId' => $jobId, 'cancelUrl' => '...',
   ]);
   return $this->jobDispatcher->dispatch(
       MyJob::class,
       ['myArg' => $val],
       $jobId,
       $response,
       $request->getSession(),  // released early so polling does not block
   );
   ```
5. Include `_async_job_progress.html.twig` in the progress template

**Critical ordering invariant:** `$this->render(...)` MUST come before
`$jobDispatcher->dispatch()` — the response body must exist when the runner
flushes it.

**Jobs must NOT depend on request-bound services** (Session, Request,
FlashBag, TenantContext-via-request). After `fastcgi_finish_request()` the
session is read-only and the original request is gone. Pass everything you
need (tenantId, userId, …) through `$args` at dispatch time — see
`ExportRisksJob` as the reference pattern. The shared EntityManager
keeps working after detach.

### Advanced — Messenger worker (opt-in, for dedicated infra)

Set `APP_ASYNC_JOB_RUNNER=messenger` to route `ExecuteJobMessage` through
the doctrine async transport. Worker start:

```bash
# Dev
php bin/console messenger:consume async --time-limit=300 -vv

# Production — systemd unit
# /etc/systemd/system/isms-worker.service
[Unit]
Description=Little ISMS Helper Messenger Worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/isms-helper
ExecStart=/usr/bin/php bin/console messenger:consume async --time-limit=3600 -vv
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable isms-worker && systemctl start isms-worker
```

### Key files

- `src/Service/Job/JobDispatcher.php` — facade controllers inject
- `src/Service/Job/InRequestJobRunner.php` — default strategy (FCGI detach)
- `src/Service/Job/MessengerJobRunner.php` — opt-in Messenger strategy
- `src/Service/Job/JobStatusService.php` — file-based status store
- `src/Job/AsyncJobInterface.php` + `JobContext.php` — contract + context
- `src/Message/Job/ExecuteJobMessage.php` — Messenger envelope (opt-in path)
- `src/MessageHandler/Job/ExecuteJobHandler.php` — worker handler
- `src/Controller/Admin/JobStatusController.php` — polling endpoint
- `src/Controller/Trait/DetachableResponseTrait.php` — buffer-drain + FCGI helper
- `templates/_components/_async_job_progress.html.twig` — progress partial
- `assets/controllers/async_job_controller.js` — Stimulus polling controller

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
- `WorkflowService` - Workflow instance management (facade — stable public API)
- `FieldCompletionAutoTransition` - Event-driven auto-progression listener (canonical since Y.1)
- ~~`WorkflowAutoProgressionService`~~ - @deprecated since Y.1; bridges to FieldCompletionAutoTransition
- `ModuleConfigurationService` - Module activation check (per tenant)

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

**Status fields are first-class lifecycle fields.** When an entity has a
`status` column (e.g. `draft`, `in_review`, `approved`, `published`,
`archived`), wire bulk-status-change support via the canonical
`_bulk_action_bar.html.twig` with `actions: ['status_change', 'approve']`
(emits `.fa-bulk-btn` BEM classes; add `variant: 'brand'` for hero lists).
The server enforces a 5-transition matrix: `draft→in_review`,
`in_review→approved`, `in_review→draft`, `approved→published`,
`published→archived`, `archived→published`. Do not allow arbitrary
status targets; validate the transition server-side.

**SectionPolicy (FormType convention, S4 Foundation P-2).** Non-trivial
FormTypes (anything with >6 fields, or any FormType where some fields are
regulatorily critical) implement `App\Form\SectionMapInterface` and declare
an explicit `getSectionMap()` returning `section-key => [field, ...]`. The
`_auto_form.html.twig` renderer uses this map to group fields into named
sections (`overview`, `recovery`, `team`, `communication`, `resources`,
`activation`, `testing`, `audit_metadata`, `results`, `lessons_learned`,
`details`, `contact`). Section labels resolve to `form.section.<key>` in
the `messages` domain. Fields not covered by any section leak into a
deprecation-flagged "Sonstiges" bucket in dev — `scripts/quality/check_form_sections.py`
CI-gates parity between builder and section-map. The convention exists
because the previous catch-all silently buried ISO 22301 Cl. 8.2.2 RTO/RPO
fields and BC-Exercise result-fields among unrelated data.

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
- **Compliance:** compliance, compliance_import, compliance_inheritance, compliance_policy, compliance_wizard, policy_wizard, policy_approval, privacy, interested_parties, objective, consent, data_subject_request, dora, nis2
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

## Lifecycle (Symfony Workflow Foundation P-4b)

Every entity with a `status` field that goes through staged transitions uses **Symfony Workflow** as the canonical state-machine engine. The `LifecycleService` facade exposes `transition($entity, $workflowName, $transitionName, $user, $reason)` — call-sites MUST go through this facade, NOT through direct `setStatus()`.

**Two-layer config:**
- Static baseline: `config/workflows/<entity>.yaml` (places + transition wiring — immutable at runtime)
- Tenant overrides: `lifecycle_config` DB-table (admin UI at `/admin/lifecycle-overrides`) — overrides `roles`, `reason_required`, `four_eyes`, `module` metadata per tenant

**Effective metadata resolution:** `LifecycleConfigResolver::resolve()` merges YAML + DB-overrides. Voter / Guards / Listeners ALWAYS read via Resolver, never from YAML directly.

**Existing workflows** (as of 2026-05-17):
- `document_lifecycle` (5 stages: draft → in_review → approved → published → archived)
- `processing_activity_lifecycle` (5 stages, GDPR-gated — `privacy` module required)
- `isms_objective_lifecycle` (5 custom stages: in_progress / achieved / delayed / cancelled)
- `policy_template_lifecycle` (5 stages, isActive-sync on publish/archive)
- `asset_lifecycle` (7 stages: active / in_use / returned / retired / disposed + 4-eyes on disposal)

**Adding a new entity to the lifecycle:**
1. `config/workflows/<entity>.yaml` — workflow definition (copy document.yaml as template)
2. Add to `config/packages/workflow.yaml` imports
3. Add slug to `src/Lifecycle/EntityTypeRegistry.php` MAP constant
4. Refactor existing `setStatus()` call-sites to `LifecycleService::transition()`
5. Add `#[ORM\Version] private int $lockVersion = 0;` to entity + migration (`isTransactional()=false`)
6. Smoke tests in `tests/Lifecycle/`

**Anti-pattern — never do this:**
```php
$entity->setStatus('approved'); // direct setter bypasses audit-log, RBAC, tenant-guard
$em->flush();
```

**Correct pattern:**
```php
$lifecycleService->transition($entity, 'foo_lifecycle', 'approve', $user, 'reason'); // facade
```

**Spec:** `docs/superpowers/specs/2026-05-17-lifecycle-foundation-pilot-design.md`
**ADR:** `docs/decisions/2026-05-17-lifecycle-state-machine.md`
**User-guide:** `docs/user-guide/STATUS_LIFECYCLE.md`

---

## Workflow System (Event-Driven Approvals)

> **Y.4 (2026-06):** YAML is the canonical source of truth for all regulatory
> workflow definitions. `App\Entity\Workflow` and `App\Entity\WorkflowStep` are
> `@deprecated`. The PHPStan rule `tools/phpstan/Rule/NoNewWorkflowOrWorkflowStep.php`
> prevents new instantiations in `src/` outside Repository/Command namespaces.
> ADR: `docs/decisions/2026-05-17-workflow-yaml-unification.md`

**Source of Truth:** `config/workflows/regulatory/*.yaml` (15 files)

**Overview:**
Event-driven workflow system where workflows automatically progress based on user actions.
Auto-progression is handled by `FieldCompletionAutoTransition` (Doctrine `postUpdate` listener)
— no explicit service calls needed in entity services or controllers.

**Available Regulatory Workflows** (YAML definitions in `config/workflows/regulatory/`):
- **GDPR Data Breach** — `gdpr_data_breach.yaml` — Art. 33/34, 6 steps, 72h SLA
- **Incident Response High/Critical** — `incident_high_severity.yaml` — ISO 27001, 6 steps
- **Incident Response Low/Medium** — `incident_low_severity.yaml` — ISO 27001, 4 steps
- **Risk Treatment** — `risk_treatment.yaml` — ISO 27001 Cl. 6.1.3, 6 steps
- **DPIA** — `dpia.yaml` — GDPR Art. 35/36, 6 steps
- Plus 10 further workflows: DSR, CAPA, Change Request, Management Review, Control Verification, Supplier Assessment, Training Verification, BC Plan Activation, Document Review, Incident Post-Mortem

**Auto-Progression (canonical pattern since Y.1):**
Auto-progression fires automatically via Doctrine event listener when entity fields are saved.
Define conditions in the YAML step metadata — no code changes needed:

```yaml
# config/workflows/regulatory/my_workflow.yaml
metadata:
    regulatory_metadata:
        steps:
            - name: "Initial Assessment"
              approver_role: ROLE_DPO
              auto_progress_conditions:
                  type: field_completion
                  entity: DataBreach
                  fields: [severity, affectedDataSubjectsCount, notificationRequired]
```

**Verification CLI:**
```bash
# Verify all 15 DB workflow rows have YAML equivalents (report-only, safe)
php bin/console app:migrate-legacy-workflows

# Archive obsolete DB rows with no YAML counterpart (opt-in)
php bin/console app:migrate-legacy-workflows --archive
```

**Advanced Features** (✅ Implemented):
- ✅ **AND/OR Logic**: Complex conditions like `(severity >= high AND count > 100) OR required = true`
- ✅ **Time-Based**: Auto-progress after time delay via `app:process-timed-workflows` cron
- ✅ **Entity Coverage**: DataBreach, Incident, Risk, Asset, Control, DPIA, ProcessingActivity
- ✅ **15 canonical workflows**: All defined in `config/workflows/regulatory/*.yaml`

**Do NOT:**
- Create `new Workflow()` or `new WorkflowStep()` in `src/` outside Repository/Command (PHPStan blocks this)
- Inject `WorkflowAutoProgressionService` in new code (deprecated — use listener pattern)
- Edit DB `workflow_steps` rows directly (read-only since Y.4)

**Documentation:**
- `docs/WORKFLOW_AUTO_PROGRESSION.md` - Complete auto-progression guide (updated for Y.4)
- `docs/decisions/2026-05-17-workflow-yaml-unification.md` - ADR with deprecation deadlines
- `config/workflows/regulatory/` - Canonical YAML definitions

## Module-Awareness

**Every feature that relates to an optional compliance framework MUST be module-gated.**
Do not add DORA fields to forms without `nis2_dora` gate, do not add GDPR fields without
`privacy` gate, etc.

Full reference: [`docs/MODULE_GATING_GUIDE.md`](docs/MODULE_GATING_GUIDE.md)

**Key artifacts:**
- `config/modules.yaml` — 21 module keys + metadata
- `config/active_modules.yaml` — per-tenant activation overrides
- `src/Form/Trait/ModuleAwareFormTrait.php` — FormType helper (`isModuleActive()`)
- `src/Controller/Trait/ModuleGatedControllerTrait.php` — Controller helper (`checkModuleActive()`)
- `is_module_active('key')` — Twig global function

**Quick pattern (FormType):**
```php
use App\Form\Trait\ModuleAwareFormTrait;

class MyType extends AbstractType {
    use ModuleAwareFormTrait;
    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        // Always-visible fields...
        if ($this->isModuleActive('privacy')) {
            $builder->add('gdprField', ...); // GDPR Art. X — add norm ref here
        }
    }
}
```

**Quick pattern (Controller):**
```php
use App\Controller\Trait\ModuleGatedControllerTrait;
// ...
if ($redirect = $this->checkModuleActive('privacy')) return $redirect;
```

**Quick pattern (Twig):**
```twig
{% if is_module_active('nis2_dora') %} ... {% endif %}
```

User-facing guide: [`docs/user-guide/MODULE_AKTIVIERUNG.md`](docs/user-guide/MODULE_AKTIVIERUNG.md)  
Compliance audit trail: [`docs/FORM_AUDIT_2026-05.md`](docs/FORM_AUDIT_2026-05.md)

---

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
| **Stable** | release-please-PR wird **Mo 09:00 UTC automatisch gemerged** (`release-please-auto-merge.yml`) — Skip via Label `release-blocked`/`do-not-merge`, Force via workflow_dispatch | `:vX.Y.Z`, `:X.Y`, `:latest` | ja, automatisch via release-please |
| **Dev** | GitHub Actions → *"Dev Release (manual)"* → bump-Input (patch/minor/major) | `:vX.Y.Z-dev.N`, `:dev` (rolling) | nein |
| **RC** | manuell `git tag vX.Y.Z-rc.1 && git push --tags` | `:vX.Y.Z-rc.N`, `:rc` | nein |

Conventional-Commits auf `main` sammeln; release-please haelt PR
automatisch aktuell. `fix:` → patch, `feat:` → minor, `feat!:` → major.
`chore/ci/test/style/docs` sind hidden im Changelog. CHANGELOG.md NICHT
manuell editieren (ausser fuer Backfill-Edge-Cases) — release-please
verwaltet `[Unreleased]` + Sections.

Cadence-Disziplin: Stable-Releases werden automatisch wochentlich gebuendelt
(Mo). Kein "Maschinengewehr-Tagging" mehr moeglich. Hot-Fix-Bedarf vor Mo:
workflow_dispatch auf "Release Please Auto-Merge". User-Disziplin-Erinnerung:
PR-Label `release-blocked` setzen wenn Mo-Release uebersprungen werden soll
(z.B. wenn ungeloester Bug noch reinmuss).

Config-Files: `release-please-config.json`, `.release-please-manifest.json`,
`.github/workflows/release-please.yml`, `.github/workflows/release-please-auto-merge.yml`,
`.github/workflows/dev-release.yml`, Tag-Regeln in
`.github/workflows/ci.yml` (`steps.meta.tags` Block). Vollstaendige Doku in
`CONTRIBUTING.md` § *Release Cadence* + § *Dev / Pre-Release Builds*.

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
- [ ] Bulk operations use `AuditLogger::logBulk()` (1 batch-entry +
      N per-entity-entries, returns UUIDv4 batch_id) — never bypass
      Doctrine lifecycle via raw `executeStatement()` without explicit
      audit-call. Required by ISO 27001 Clause 7.5.3.

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
   - **DDL migrations need `isTransactional()=false`**: MySQL `ALTER TABLE` /
     `CREATE TABLE` commit implicitly, which invalidates Doctrine's per-migration
     SAVEPOINT — running >1 DDL migration in a single `migrate` call fails with
     `SAVEPOINT DOCTRINE_X does not exist` (or `There is no active transaction`
     when MANY ALTERs in ONE migration each implicitly commit). Override:
     ```php
     public function isTransactional(): bool { return false; }
     ```
     Required for every migration that contains ALTER TABLE or CREATE TABLE.
     Data-only migrations (INSERT/UPDATE) can keep the default (true).
     **`doctrine:migrations:diff` does NOT add this override automatically** —
     after every diff-generated migration, manually inject the method before
     committing. Otherwise the next migration run on a non-trivial DB fails.
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
9. *(number reserved — see item 10 below)*
10. **Twig macro-import in `{% embed %}` needs local re-import** — Twig
    `{% embed %}` creates a new template scope. File-scope or parent-block
    imports are NOT visible inside the embed-block. Symptom:
    `Variable "_fa_X" does not exist`. **Static check:**
    `python3 scripts/quality/check_twig_macro_scope.py` (CI-gated since
    v3.5) detects file-scope-import + embed-block-use mismatches. Fix:
    add `{% import '_components/_fa_X.html.twig' as _fa_X %}` as first
    line inside the embed-block (or nested-embed-block). Regular
    `{% block %}` under `{% extends %}` inherits file-scope imports — only
    `{% embed %}` is the trap.
10b. **`trans_default_domain` has the same scope-isolation in `{% embed %}`** —
    A file-level `{% trans_default_domain 'X' %}` directive is NOT inherited
    inside `{% embed %}` blocks. Symptom: translation keys resolve against the
    `messages` fallback domain instead of the intended domain, causing silent
    wrong-language or missing-key lookups (hit in `_operational_baselines.html.twig`
    crypto_col keys). Fix: either repeat `{% trans_default_domain 'X' %}` as the
    first line inside every embed-block, or use the explicit domain parameter on
    every `|trans` call inside the embed (e.g. `|trans({}, 'crypto')`). Regular
    `{% block %}` under `{% extends %}` inherits the file-scope default domain —
    only `{% embed %}` is the trap.
11. **Do NOT use Bootstrap `bg-*` / `text-white` on `.card` or `.card-header`** —
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
| `fa-stepper` | Multi-step wizard chrome (Preset → Discovery → Test for SSO; Upload → Map → Preview → Commit for Bulk-Import) | `_fa_stepper.html.twig` |
| `fa-diff-row` | Old → New value diff visualization for Bulk-Import Delta-Mode + OSCAL-Conflict-Cards | `_fa_diff_row.html.twig` |
| `fa-condition-builder` | Visual rule-builder (chip-row WHEN/CONDITIONS) for Notification-Rules — replaces raw JSON editing | `_fa_condition_builder.html.twig` |
| `fa-table` | Aurora-styled data table (replaces raw `<table class="table">`) — 80+ adopted in v3.5 | `_fa_table.html.twig` |
| `fa-progress` | Aurora progress-bar (replaces hand-rolled `.progress > .progress-bar`) — 54 adopted in v3.5 | `_fa_progress.html.twig` |
| `fa-action-bar` | Page-level action bar (sticky bottom, top of detail-views) | `_fa_action_bar.html.twig` |
| `fa-bulk-bar` (BEM, canonical) | Canonical Aurora v4 floating bulk-action bar. Use `{% include '_components/_bulk_action_bar.html.twig' with { actions: ['export', 'delete', 'tag', 'assign', 'approve', 'status_change'], variant: 'neutral' } %}`. Props: `actions` array + `variant: 'neutral'\|'brand'` (default `'neutral'`). Use `variant: 'brand'` for hero lists only (risk/document policy workflow). `approve` → quick-approve `.fa-bulk-btn--success` button; `status_change` → dropdown (draft→in_review→approved→published→archived). BEM CSS: `.fa-bulk-bar`, `.fa-bulk-bar--brand`, `.fa-bulk-bar__count`, `.fa-bulk-bar__count-num`, `.fa-bulk-bar__count-label`, `.fa-bulk-bar__divider`, `.fa-bulk-bar__actions`, `.fa-bulk-bar__close`, `.fa-bulk-btn`, `.fa-bulk-btn--success`, `.fa-bulk-btn--danger`, `.fa-bulk-btn.is-loading`. BC-bridge macro `_fa_bulk_action_bar.html.twig` kept for legacy callers — emits new BEM markup. Old `.bulk-action-bar*` class set is a deprecated CSS shim. | `_components/_bulk_action_bar.html.twig` (include, not macro) |
| `fa-toast` | Aurora toast/flash-message stack (replaces Bootstrap toast-container) — wired in `base.html.twig` | `_fa_toast.html.twig` |
| `fa-audit-row` | ISMS-Audit-Trail row pattern (compact + full views) | `_fa_audit_row.html.twig` |
| `fa-cyber-field` | Hand-rolled Aurora-Frame inputs (text/textarea/select) | `_fa_cyber_field.html.twig` |
| `fa-drawer` | Slide-in side-sheet (right default, `--left`, `--sm`, `--lg`). Use `.fa-drawer-backdrop` + `.is-open`. CSS only — no macro yet. | CSS: `fairy-aurora-components.css` |
| `fa-menu` | Dropdown / overflow-action menu (`fa-menu-anchor` + `.fa-menu.is-open`). Supports groups, icons, shortcuts, danger items, dividers. CSS only. | CSS: `fairy-aurora-components.css` |
| `fa-pager` | Aurora pagination pill shell with glow + gradient active page. `fa-pager-bar` wraps pager + info + per-page select. CSS only. | CSS: `fairy-aurora-components.css` |
| `fa-kbd` / `fa-cheatsheet` | Keyboard key `<kbd class="fa-kbd">` + shortcut cheatsheet overlay content. CSS only. | CSS: `fairy-aurora-components.css` |
| `isms-comment-thread` | Comment-thread for Show-Pages (10 entities whitelisted by CommentController) | `_isms_comment_thread.html.twig` |
| `isms-approval-stages` | Multi-stage approval visualization (workflow instances) | `_isms_approval_stages.html.twig` |
| `.fa-aurora-surface` | Opt-in page-level Aurora atmosphere (CSS utility, not macro) | — |

Import pattern for macros: `{% import '_components/_fa_feature_card.html.twig' as _fa_feature_card %}`.
For the bulk-action bar use `{% include '_components/_bulk_action_bar.html.twig' with { actions: [...], variant: 'neutral' } %}` (it is a plain include, not a macro). `variant: 'brand'` for hero lists (risk, document). The canonical CSS class set is `.fa-bulk-bar*` (BEM); the old `.bulk-action-bar*` is a deprecated shim.
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
