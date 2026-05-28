# 03 — Where Things Live

A 30-namespace index to orient you in the codebase. Numbers in parentheses are
approximate file counts as of the 3.x release series.

---

## `src/` — PHP Application Root

### Entity (114 files)

All entities live in `src/Entity/`. Every entity:

- Has a `tenant_id` field (multi-tenancy isolation)
- Uses PHP 8 ORM attributes (`#[ORM\Entity]`), not annotations
- Uses typed properties and PHP generics in collection hints

Most-imported entities:

| Entity | Role |
|---|---|
| `Risk` | Central ISMS entity; wired to Asset, Control, WorkflowInstance |
| `Control` | ISO 27001 Annex A control; linked to ComplianceMapping, SoA |
| `Asset` | Information asset; has dependency graph via `AssetDependency` |
| `User` | Symfony Security user; carries tenant, roles, persona-roles |
| `Tenant` | Organisation unit; root of multi-tenant scoping |

Sub-directories: `src/Entity/Authority/` (authority-specific entities).

---

### Service (171 files)

Business logic lives in `src/Service/`. Grouped by namespace prefix:

| Prefix / Sub-directory | Domain |
|---|---|
| `AlvaHint/` | Alva hint rule engine (17 rules, tier system) |
| `Admin/` | Admin-only services (repair, schema maintenance) |
| `Audit/` | Audit checklist + findings |
| `Authority/` | Authority-template services |
| `Clone/` | Entity cloner implementations (8 entities, `EntityClonerInterface`) |
| `ComplianceWizard/` | Category providers for the compliance framework wizard |
| `DataIntegrity/` | Data repair, orphan detection, tenant-mismatch fixes |
| `Document/` | Document versioning, approval pipeline |
| `Evidence/` | Evidence collection for control assessments |
| `Export/` | CSV/Excel/PDF/XBRL/OSCAL exporters |
| `FollowUpTrigger/` | Automated follow-up creation after lifecycle events |
| `Fte/` | FTE tracking service |
| `Import/` | CSV/XML import pipeline services |
| `Job/` | Async job infrastructure (dispatcher, runner, status store) |
| `Library/` | Mapping library loaders |
| `Mail/` | Tenant-branded email services |
| `Nonconformity/` | Corrective-action and NC workflows |
| `Notification/` | In-app + push notification services |
| `PolicyWizard/` | Document generator, policy template engine |
| `PreFiller/` | Form pre-filling from existing entity data |
| `Risk/` | Risk scoring, probability adjustment, matrix |
| `Search/` | Full-text search across 33+ entity types (`SearchService`) |
| `Setup/` | Wizard + initial-admin provisioning |
| `Soa/` | SoA report builder |
| `Sso/` | SSO providers (OIDC, SAML, Azure OAuth) |
| Root-level files | `RiskService`, `AssetService`, `ControlService`, `TenantContext`, `AuditLogger`, `WorkflowService`, `ModuleConfigurationService` |

**Key services for new contributors:**

- `TenantContext` — always-available; provides active `tenant_id`
- `AuditLogger` — must be called on every data-mutating operation
- `WorkflowService` — stable public API facade for regulatory workflow instances
- `LifecycleService` — state machine facade for entity status transitions
- `ModuleConfigurationService` — check module activation before adding gated fields

---

### Controller (145 files)

HTTP handlers in `src/Controller/`. Grouped by routing prefix:

| Sub-directory / Prefix | Routes |
|---|---|
| `Admin/` | `/admin/*` — admin panel, data repair, licensing, queue status |
| `Analytics/` | `/analytics/*` — KPI charts, reports |
| `Api/` | `/api/*` — API Platform resources |
| `Audit/` | `/audit/*` |
| `Authority/` | `/authority/*` |
| Root-level | All feature controllers (`RiskController`, `AssetController`, `DocumentController`, etc.) |

Controllers must:
- Extend `AbstractController`
- Use `#[Route]` attributes (no YAML routes)
- Use `#[IsGranted('ROLE_X')]` (not `denyAccessUnlessGranted`)
- Use `#[IsCsrfTokenValid]` for state-changing actions
- Use constructor injection with `private readonly`

---

### Command (146 files — categorised)

Console commands in `src/Command/`:

| Category | Examples |
|---|---|
| **Setup / seed** | `LoadAnnexAControlsCommand`, `SeedIsoPolicyTemplatesCommand`, `CreateScreenshotUserCommand` |
| **Import** | `ImportBsiKompendiumXmlCommand`, `ImportGstoolXmlCommand`, `ImportDoraRegisterCommand` |
| **Verify / validate** | `AuditLogVerifyCommand`, `ValidateSoACommand`, `ValidateFrameworkModulesCommand` |
| **Process / schedule** | `ProcessTimedWorkflowsCommand`, `GenerateRegulatoryWorkflowsCommand`, `KpiSnapshotCommand` |
| **Export** | `Nis2MusExportCommand`, `SiemExportCommand` |
| **Maintenance** | `SchemaReconcileCommand`, `AuditLogCleanupCommand`, `CleanupExpiredFourEyesCommand` |

---

### Other `src/` Namespaces

| Namespace | Purpose |
|---|---|
| `AlvaHint/` | Alva hint rules, rule interface, service, form-rule variants |
| `ApiResource/` | API Platform resource definitions |
| `Doctrine/` | Custom DBAL types, Doctrine extensions |
| `Enum/` | PHP 8 backed enums (status, severity, type fields) |
| `Event/` | Custom Symfony events |
| `EventListener/` / `EventSubscriber/` | Doctrine + Symfony event hooks |
| `Exception/` | Domain-specific exceptions |
| `Form/` | FormTypes (one per entity); `Trait/` holds `ModuleAwareFormTrait` |
| `Job/` | Async job implementations (`AsyncJobInterface`) |
| `Lifecycle/` | `LifecycleService` facade, `EntityTypeRegistry`, guards, listeners |
| `Message/` | Symfony Messenger message envelopes |
| `MessageHandler/` | Messenger handlers |
| `Model/` | Value objects, DTOs |
| `Repository/` | `ServiceEntityRepository` subclasses; all extend Doctrine |
| `Risk/` | Risk-domain value objects |
| `Security/` | Authenticators (OIDC, SAML, Azure), voters |
| `Security/Voter/` | Per-entity access voters + `PermissionVoter` |
| `Serializer/` | Custom normalizers for API Platform |
| `Setup/` | Setup wizard logic |
| `State/` | API Platform state providers/processors |
| `Template/` | Twig extensions, global functions |
| `Twig/` | Twig extension classes |
| `Validator/` | Custom validation constraints |
| `Workflow/` | Regulatory workflow engine (YAML loader, instance manager) |

---

## `templates/` — Twig Templates

Feature directories mirror the controller structure:

```
templates/
  _components/         Aurora v4 macro library (_fa_*.html.twig, _bulk_action_bar.html.twig)
  _macros/             Shared utility macros
  _previews/           Design-system preview partials
  admin/               Admin panel pages
  asset/               Asset management views
  audit/               Audit views
  compliance/          Compliance framework views
  compliance_wizard/   Multi-step compliance wizard
  dashboard/           Persona dashboards
  document/            Document lifecycle views
  incident/            Incident management views
  risk/                Risk management views
  workflow/            Regulatory workflow views
  base.html.twig       Master layout (Aurora v4, Stimulus wiring, Turbo)
  base_auth.html.twig  Authentication pages layout
  (50+ more feature directories)
```

---

## `config/` — Configuration Files

| File / Directory | Purpose |
|---|---|
| `config/modules.yaml` | 40 module keys + metadata (required vs optional) |
| `config/active_modules.yaml` | Per-tenant module activation overrides |
| `config/services.yaml` | Service definitions, tagged services |
| `config/packages/security.yaml` | Firewalls, access control, password hasher |
| `config/packages/workflow.yaml` | Imports all lifecycle YAML definitions |
| `config/packages/messenger.yaml` | Async transport configuration |
| `config/workflows/` | Entity lifecycle YAML definitions |
| `config/workflows/regulatory/` | 15 regulatory workflow YAML definitions |

---

## `assets/` — Frontend Assets

```
assets/
  controllers/         Stimulus JS controllers (auto-registered via importmap)
  styles/              SCSS / CSS (Aurora v4 theme variables, overrides)
```

Key controllers: `async_job_controller.js`, `fa_modal_controller.js`,
`density_toggle_controller.js`, `glossary_tooltip_controller.js`.

---

## `fixtures/library/` — Reference Data

Pre-built library data loaded by seed commands:

```
fixtures/library/
  catalogues/          BSI, ISO, NIS2, DORA, TISAX requirement catalogues
  mappings/            Cross-framework control mappings (ISO↔BSI, ISO↔DORA, etc.)
  presets/             Industry preset bundles
  frameworks/          Framework definition JSONs
```

---

## `scripts/quality/` — CI Gate Scripts

48+ Python and shell scripts. Each has a corresponding baseline file in
`scripts/quality/baselines/`. See [06-quality-gates.md](06-quality-gates.md).

---

## `docs/` — Documentation

```
docs/
  decisions/           Architecture Decision Records (ADRs)
  deployment/          Hosting and Docker guides
  design_system/       FairyAurora v4 spec and assets
  onboarding/          This guide set
  user-guide/          End-user documentation (German)
  superpowers/specs/   Technical design specifications
  (40+ more directories)
```
