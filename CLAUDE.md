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

**Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM, Twig, Stimulus/Turbo, Bootstrap 5.3

**Multi-tenancy:** All entities use `tenant_id` field. `TenantContext` service manages context.

**RBAC:** USER → AUDITOR → MANAGER → ADMIN → SUPER_ADMIN with 50+ permissions.

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

# Quality
php -l src/Service/MyService.php  # syntax check single file
php bin/console cache:clear
php bin/console lint:container    # validate service wiring
php bin/console lint:twig templates/  # validate all templates
```

## Architecture Quick Guide

**Directory Layout:**
- `src/Entity/` - Doctrine entities (43 total, all with `tenant_id`)
- `src/Service/` - Business logic (47 services)
- `src/Controller/` - HTTP handlers (55 controllers)
- `src/Command/` - Console commands (31 total)
- `src/Security/Voter/` - Authorization voters
- `templates/` - Twig templates
- `assets/controllers/` - Stimulus JS controllers
- `translations/` - Translation files (97 YAML files organized by domain)

**Key Services:**
- `RiskService`, `AssetService`, `ControlService` - Core CRUD
- `AuditLogger` - Compliance audit trail
- `BackupService`, `RestoreService` - Data backup/restore
- `TenantContext` - Multi-tenant scoping

**Core Entities:** Asset, Risk, Control (93 ISO 27001 controls), Incident, Document, ComplianceFramework, User, Tenant, Role, Permission

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
- `translations/` contains 97 YAML files (49 domains × 2 languages)
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

Available domains (49 total):
- **Navigation:** nav, dashboard, ui
- **Access Control:** mfa, role_management, session, users
- **ISMS Core:** assets, risks, controls, incidents, audits, soa, context
- **BCM:** bcm, bc_plans, bc_exercises, crisis_team
- **Compliance:** compliance, privacy, interested_parties, objectives
- **Management:** tenant, admin, management_review, analytics, reports
- **Operations:** documents, workflows, training, change_requests, patches
- **Resources:** suppliers, locations, people, physical_access
- **Technical:** monitoring, crypto, vulnerabilities, threat, bulk_delete, field
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

## Coding Standards

- PSR-12, Symfony conventions
- 4-space indent, max 120 char lines
- Type hints and return types required
- Commit format: `feat(scope): message` / `fix(scope): message`

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
