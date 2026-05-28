# 02 — Architecture Tour

This document walks through the six layers of the application from the inside
out. Read it top-to-bottom once; use it as a reference afterward.

---

## ASCII Diagram

```
 ┌─────────────────────────────────────────────────────────────────┐
 │  Layer 6 — CI / Quality Gates (48+ automated checks)           │
 ├─────────────────────────────────────────────────────────────────┤
 │  Layer 5 — Frontend (Aurora v4 · Stimulus · Turbo · Bootstrap) │
 ├─────────────────────────────────────────────────────────────────┤
 │  Layer 4 — Security (AuditLogger · Voters · 4-Eyes · HMAC)     │
 ├─────────────────────────────────────────────────────────────────┤
 │  Layer 3 — Workflows (LifecycleService · 15 Regulatory YAMLs)  │
 ├─────────────────────────────────────────────────────────────────┤
 │  Layer 2 — Boundaries (Multi-Tenant · Module Gating · RBAC)    │
 ├─────────────────────────────────────────────────────────────────┤
 │  Layer 1 — Domain (114 Entities · Doctrine ORM · tenant_id)    │
 └─────────────────────────────────────────────────────────────────┘
```

---

## Layer 1 — Domain

### Entities (114 files in `src/Entity/`)

Every entity carries a `tenant_id` column. This is the primary isolation
mechanism for multi-tenancy. There is no global query scope applied
automatically — controllers and repositories are responsible for filtering
by the active tenant via `TenantContext`.

**Core entity clusters:**

| Cluster | Key Entities |
|---|---|
| Asset Management | `Asset`, `AssetDependency`, `AssetSubType` |
| Risk Management | `Risk`, `RiskTreatment`, `RiskAppetite` |
| Controls / SoA | `Control`, `ComplianceFramework`, `ComplianceRequirement`, `ComplianceMapping` |
| Incident | `Incident`, `DataBreach`, `DPIA` |
| Audit | `AuditFinding`, `AuditLog`, `AuditFreeze`, `AuditChecklist` |
| BCM | `BusinessContinuityPlan`, `BusinessProcess`, `BCExercise`, `CrisisTeam` |
| Documents | `Document`, `DocumentVersion`, `DocumentSection` |
| Workflow | `WorkflowInstance`, `WorkflowStep` (read-only since Y.4; YAML is canonical) |
| People | `User`, `Tenant`, `Role`, `Permission`, `Department` |

### Lifecycle facade

Every entity with a `status` field transitions through `LifecycleService`, not
via direct `setStatus()`. The service records the transition in the audit log,
enforces RBAC guards, and fires Symfony Workflow events.

```php
// Correct:
$lifecycleService->transition($entity, 'document_lifecycle', 'approve', $user, 'reason');

// Wrong — bypasses audit log and RBAC:
$entity->setStatus('approved');
$em->flush();
```

Workflow definitions live in `config/workflows/<entity>.yaml` and are imported
in `config/packages/workflow.yaml`.

---

## Layer 2 — Boundaries

### Multi-Tenancy

`TenantContext` (injected as a service) holds the active `tenant_id` for the
current request. All repository queries that return user-visible data must
include a `WHERE tenant_id = :tenantId` clause. Missing this filter is a
security defect, not just a bug.

The `AuditTenantVoter` and entity-specific voters (`AssetVoter`,
`ControlVoter`, etc.) enforce cross-tenant access prevention at the HTTP layer.

### Module Gating

Optional compliance frameworks are controlled by 40 module keys defined in
`config/modules.yaml`. Six modules are always on (`core`, `authentication`,
`documents`, `audit_logging`, `workflows`, `objectives`); the rest are
per-tenant opt-in.

Check module state in:

- **PHP controllers:** `$this->checkModuleActive('privacy')` (trait)
- **PHP FormTypes:** `$this->isModuleActive('nis2_dora')` (trait)
- **Twig templates:** `{% if is_module_active('bcm') %}`

Never add GDPR or DORA fields to a form without the corresponding module gate.

### RBAC

The permission hierarchy from lowest to highest privilege:

```
USER < AUDITOR < MANAGER < ADMIN < SUPER_ADMIN
```

Holding-level roles: `ROLE_GROUP_CISO`, `ROLE_KONZERN_AUDITOR`

Persona-roles (each gates its own dashboard):
`ROLE_CISO`, `ROLE_RISK_MANAGER`, `ROLE_DPO`, `ROLE_COMPLIANCE_MANAGER`

There are 50+ fine-grained permissions. Authorization is enforced via Symfony
Security Voters in `src/Security/Voter/`. Controllers use the `#[IsGranted]`
attribute — do not use `denyAccessUnlessGranted()` (deprecated pattern).

---

## Layer 3 — Workflows

### Two workflow systems (do not confuse them)

| System | Location | Purpose |
|---|---|---|
| **Symfony Workflow** (Lifecycle) | `config/workflows/<entity>.yaml` | Entity status state machine (draft → approved etc.) |
| **Regulatory Workflows** | `config/workflows/regulatory/*.yaml` | Event-driven approval chains (GDPR breach, risk treatment, etc.) |

### Symfony Workflow / LifecycleService

Used for entity lifecycle management. Five live workflow definitions:

- `document_lifecycle` — 5 stages: draft → in_review → approved → published → archived
- `processing_activity_lifecycle` — GDPR-gated, 5 stages
- `isms_objective_lifecycle` — 5 stages with custom names
- `policy_template_lifecycle` — isActive synced on publish/archive
- `asset_lifecycle` — 7 stages with 4-eyes on disposal

Tenant overrides of role/reason/four-eyes metadata live in the
`lifecycle_config` DB table, managed via `/admin/lifecycle-overrides`.

Resolution order: YAML baseline + DB override → `LifecycleConfigResolver::resolve()`.

### Regulatory Workflows (15 YAML definitions)

All in `config/workflows/regulatory/`. Key workflows:

| File | Standard | SLA |
|---|---|---|
| `gdpr_data_breach.yaml` | GDPR Art. 33/34 | 72 hours |
| `incident_high_severity.yaml` | ISO 27001 | — |
| `incident_low_severity.yaml` | ISO 27001 | — |
| `risk_treatment.yaml` | ISO 27001 Cl. 6.1.3 | — |
| `dpia.yaml` | GDPR Art. 35/36 | — |
| `dsr.yaml` | GDPR Art. 15-22 | — |
| `capa.yaml` | ISO 9001/27001 | — |
| `management_review.yaml` | ISO 27001 Cl. 9.3 | — |
| (7 more) | various | — |

Auto-progression fires via `FieldCompletionAutoTransition` (Doctrine
`postUpdate` listener). Define conditions in the YAML step metadata — no
code changes required for simple field-completion triggers.

**DEPRECATION:** `App\Entity\Workflow` and `App\Entity\WorkflowStep` are
read-only since Y.4. Do not instantiate them. PHPStan blocks `new Workflow()`
and `new WorkflowStep()` outside Repository/Command namespaces.

---

## Layer 4 — Security

### AuditLogger

Every CRUD operation that modifies security-relevant data must be logged via
`AuditLogger`. It creates an HMAC-chained audit trail that satisfies
ISO 27001 Clause 7.5.3.

```php
$this->auditLogger->log($entity, 'update', $user, $changedFields);
// For bulk ops:
$batchId = $this->auditLogger->logBulk($entities, 'bulk_delete', $user);
```

Never bypass Doctrine lifecycle with raw `executeStatement()` without an
explicit `auditLogger` call.

### 4-Eyes Principle

Sensitive transitions (e.g. asset disposal) can require a second approver. The
`four_eyes` metadata key in lifecycle YAML enables this. Pending approvals are
tracked in `FourEyesRequest` entities and surfaced in the `/four-eyes` dashboard.

### CSRF Protection

All state-changing forms use `#[IsCsrfTokenValid('token_id')]` attribute
(Symfony 7.1+). Do not use the legacy `$this->isCsrfTokenValid()` call.

### Input Validation

Sanitize user input via `InputValidationService`. File uploads go through
`FileUploadSecurityService`. Never trust raw `$request->get()` for data that
will be persisted.

---

## Layer 5 — Frontend

### Stack

- **Bootstrap 5.3** — utility classes, grid, modals (loaded as ES module)
- **FairyAurora v4** — custom design system layered on Bootstrap; 67 Twig macros
- **Stimulus 3.2** — lightweight JS controllers in `assets/controllers/`
- **Turbo 8** — SPA-like navigation without writing SPA code

### Aurora Macros

Import pattern:
```twig
{% import '_components/_fa_feature_card.html.twig' as _fa_feature_card %}
```

67 macros cover: page headers, tables, modals, filters, bulk action bars,
progress bars, KPI tiles, entity cards, wizard steppers, and more. See
`docs/onboarding/04-hot-files.md` for the full table and the live preview at
`/dev/design-system` (dev mode only).

### Critical Twig scoping rules

1. `{% embed %}` creates a new template scope. File-scope macro imports are
   **not** visible inside embed blocks. Re-import explicitly at the top of
   each embed block.
2. `{% trans_default_domain 'X' %}` is also NOT inherited in embed blocks.
   Either repeat the directive or use the explicit domain parameter on every
   `|trans` call.
3. `{% block %}` under `{% extends %}` inherits both. Only `{% embed %}` is
   the trap.

### Turbo Navigation

- Use `turbo:load` (not `DOMContentLoaded`) for page-specific JS init.
- Add `<meta name="turbo-cache-control" content="no-cache">` for dynamic pages
  that must not be served from Turbo's preview cache.

### Stimulus Controllers

Located in `assets/controllers/`. Controllers are auto-registered via the
`importmap`. Key controllers: `async_job`, `density-toggle`, `glossary-tooltip`,
`fa-modal`, `fa-confirm`, `reuse-roi-counter`.

---

## Layer 6 — CI / Quality Gates

48+ automated checks run on every push. They fall into several categories:

- **PHP static analysis** — PHPStan + custom rules (`tools/phpstan/`)
- **Twig validation** — `lint:twig` + scope checks (`check_twig_macro_scope.py`)
- **Translation completeness** — 90 domains × 2 languages
- **Architecture rules** — god-class size, entity reserved words, EM writes in controllers
- **Migration safety** — PREPARE/EXECUTE detection, DDL transaction override check
- **Security** — audit-log coverage, tenant isolation, module gating
- **Competitor name ban** — `check_no_competitor_names.sh`

Each gate has a baseline file in `scripts/quality/baselines/`. A gate fails
if the current value **exceeds** (for size gates) or **differs** (for allow-lists)
from the baseline. This is the "baseline ratchet" pattern.

See [docs/onboarding/06-quality-gates.md](06-quality-gates.md) for the full
gate inventory and how to add a new gate.

---

## Where to Start Reading

For a new contributor, the recommended reading order is:

1. `src/Entity/Risk.php` — a canonical entity with lifecycle, tenant_id, and
   module-gated fields
2. `src/Service/RiskService.php` — how the service layer wires to the entity
3. `src/Controller/RiskController.php` — how a controller composes service,
   voter, form, and template
4. `config/workflows/regulatory/risk_treatment.yaml` — a regulatory workflow definition
5. `templates/risk/index.html.twig` — Aurora macros in a real template
6. `tests/Service/RiskServiceTest.php` — the test pattern expected for all services

This path covers all six layers in a single coherent feature slice.
