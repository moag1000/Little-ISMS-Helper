# ADR — Workflow YAML Unification (Sprint Y.4)

**Date:** 2026-05-17
**Status:** Accepted
**Sprint:** Y.4 (Final) — implemented in PR feat/lifecycle-y4-workflow-deprecation

---

## Context

The platform accumulated three overlapping workflow systems over multiple development cycles:

1. **Legacy DB-backed `Workflow` / `WorkflowStep` entities** — Approval-chain definitions stored in the `workflows` and `workflow_steps` tables, created via the Workflow-Builder admin UI (`/workflow/definition/new`) or the `GenerateRegulatoryWorkflowsCommand`. Editable at runtime by ROLE_MANAGER users.

2. **`WorkflowAutoProgressionService`** — A field-completion auto-progression service that scanned entity fields and auto-approved workflow steps. Contained an AND/OR DSL, risk-appetite evaluator, and SLA-spawn logic. Called from 14+ controller and service files via explicit `checkAndProgressWorkflow()` calls.

3. **Regulatory YAML workflows** — Introduced in Sprint Y.2: 15 canonical regulatory workflows live as YAML files under `config/workflows/regulatory/*.yaml`. Each YAML contains a `metadata.steps` block (beside `metadata.regulatory_metadata`; see the S17 addendum) as the single source of truth for step definitions, roles, SLAs, and auto-progression conditions.

**Driving problems with the old DB-backed approach:**

- Regulatory workflow definitions (GDPR Art. 33/34, ISO 27001 Cl. 6.1.3, etc.) are normative — they must not be editable by tenant admins without a change-management gate. Storing them in a DB table with a ROLE_MANAGER-editable UI is a compliance gap.
- DB-stored definitions are invisible to version control, code review, and `lint:workflow` CI gates.
- `GenerateRegulatoryWorkflowsCommand` had to run on every fresh environment to seed the DB — fragile, environment-dependent.
- `WorkflowAutoProgressionService` was a single 700+ line class with no clear boundary, making it impossible to independently test the AND/OR evaluator, the risk-appetite logic, and the SLA spawner.
- Each new approval-chain call-site had to explicitly inject `WorkflowAutoProgressionService` and call `checkAndProgressWorkflow()` — developers routinely forgot, leading to silent no-progression bugs.

---

## Decision

**YAML is the canonical source of truth for all regulatory workflow definitions.**

`config/workflows/regulatory/*.yaml` (15 files) are the normative definitions. No DB-editable approval-chain structure is allowed. Per-tenant operational overrides (approver roles, SLA days) are written to the existing `lifecycle_config` table via the `WorkflowOverlayController` admin UI, NOT by editing the YAML directly.

**The `Workflow` and `WorkflowStep` DB entities are deprecated as of 2026-06.**

Existing DB rows are preserved read-only indefinitely for forensic display of historical `WorkflowInstance` records. The ORM schema (`workflows` + `workflow_steps` tables) is NOT removed — dropping it would break `WorkflowInstance.workflow_id` FK references.

**The `newDefinition` and `editDefinition` routes are removed from `WorkflowController`.**

As of Sprint Y.4 there is no UI path to create or edit DB-backed workflow definitions. `deleteDefinition` (ROLE_ADMIN) and `toggleDefinition` (per-tenant on/off) are kept for cleanup of obsolete rows.

**`WorkflowAutoProgressionService` is deprecated** (annotated in Sprint Y.1). Logic is merged into `FieldCompletionAutoTransition` listener.

---

## Consequences

### Positive

- **Regulatory definitions are version-controlled.** Changes go through git, code review, and CI — satisfying ISO 27001 Cl. 7.5.3 (documented information control) for the *definitions* themselves, not only their instances.
- **`lint:workflow` CI gate** validates all 15 YAML files on every push. Typos in step roles or entity class names are caught before deployment.
- **No seeding required.** Fresh environments load workflow definitions from YAML at boot via `RegulatoryWorkflowLoader`; the database row a running approval needs is written from the YAML on the first start (S17 addendum).
- **Unified auto-progression.** `FieldCompletionAutoTransition` listener handles all rule types (field_completion, auto, risk_appetite, AND/OR DSL) in one testable class.
- **PHPStan guard.** `tools/phpstan/Rule/NoNewWorkflowOrWorkflowStep.php` prevents future code from accidentally instantiating the deprecated entities in production code paths (exemptions: Repository, Command namespaces).
- **Data preserved.** Zero rows deleted. Historical WorkflowInstance display at `/workflow/instance/{id}` continues to work via preserved DB rows.

### Negative / Trade-offs

- **Tenant admins can no longer create entirely new workflow types via UI.** Custom workflows must be defined in YAML and deployed. This is intentional — custom regulatory-grade workflows require a deployment gate.
- **`app:generate-regulatory-workflows`** is since S17 a thin wrapper around `RegulatoryWorkflowMirror::sync()` (no own copy of the steps); it runs nightly and as the Quick-Fix action.
- **WorkflowService remains as a permanent facade.** Its 14 caller-files continue to work unchanged. The service delegates to `LifecycleService.transition()` internally (Y.0) but is not removed.

---

## Deprecation Deadlines

| Milestone | Date | Action |
|---|---|---|
| **D-1: Entities annotated** | 2026-06 (this sprint) | `@deprecated` PHPDoc on `Workflow` + `WorkflowStep`; PHPStan rule active |
| **D-2: New/Edit UI removed** | 2026-06 (this sprint) | `newDefinition` + `editDefinition` routes removed from `WorkflowController` |
| **D-2b: Step builder API retired** | 2026-10 | `WorkflowStepApiController` is read-only (list); its write routes answer 410 Gone with a pointer to `/admin/workflows`. Orphan builder templates, `workflow_builder_controller.js` and `WorkflowStepType` removed; the PHPStan rule no longer exempts `App\Controller\Api\` |
| **D-3: Command deprecation** | 2026-09 | Remove `SeedIncidentWorkflowsCommand`, `SeedPolicyApprovalWorkflowCommand` from CI bootstrap; add deprecation warning to `GenerateRegulatoryWorkflowsCommand` |
| **D-4: Schema removal** | 2027-06 (earliest) | Drop `workflows` + `workflow_steps` tables ONLY after verifying zero active FK references from `workflow_instances`. Requires explicit release decision and migration. |

---

## Rollback Path

If the YAML-first approach introduces critical issues:

1. **Re-enable `newDefinition` / `editDefinition` routes** by reverting the WorkflowController changes (two actions, no DB schema change needed).
2. **Re-seed DB rows** via Quick-Fix → "Workflow-Definitionen abgleichen" (or `php bin/console app:generate-regulatory-workflows`).
3. **The `Workflow` + `WorkflowStep` entities are untouched** — their ORM mappings and DB tables are preserved. No data migration needed to roll back.
4. **Remove the PHPStan rule** from `phpstan.dist.neon` if needed during rollback.

Rollback window: indefinite (data preservation principle means DB tables are never dropped without an explicit release decision).

---

## Related Documents

- `docs/decisions/2026-05-17-lifecycle-state-machine.md` — ADR for the Symfony Workflow adoption (Sprint X.0)
- `config/workflows/regulatory/` — 15 canonical YAML files
- `src/Command/MigrateLegacyWorkflowsCommand.php` — verification CLI tool
- `tools/phpstan/Rule/NoNewWorkflowOrWorkflowStep.php` — PHPStan guard rule
- `docs/WORKFLOW_AUTO_PROGRESSION.md` — updated auto-progression guide

---

## Addendum 2026-11 (S17): one layout, one writer of the database rows

The sixth norm assessment found that all 15 YAMLs carry their steps at `metadata.steps`, while
`RegulatoryWorkflowLoader` and Gate 82 read `metadata.regulatory_metadata.steps`: the loader returned null
for every chain, the gate checked no step, and approvals ran on database rows written by a PHP copy of the
steps inside `GenerateRegulatoryWorkflowsCommand` (which never got the `owner` approvers of risk treatment,
DPIA and control verification). Tests used a stub in the loader's layout.

Decision:

- **Layout:** steps at `metadata.steps`; `regulatory_metadata` carries `standard`, SLA, `title` and
  `entity_type` (the identity of the database row). `RegulatoryWorkflowLoader::stepsOf()` is the one reader
  (still accepts the nested layout); Gate 82 rejects the nested layout and a regulatory YAML without steps,
  title or entity type; the overlay editor reads through the loader.
- **Database rows:** `App\Workflow\Mirror\RegulatoryWorkflowMirror` is the one writer of `Workflow` /
  `WorkflowStep` rows of the regulatory chains: in place (step ids stay for running approvals), a dropped step
  only once no approval stands on it. Triggers: Quick-Fix action "Workflow-Definitionen abgleichen", nightly
  `app:generate-regulatory-workflows` (04:20), and `WorkflowService::startWorkflow()` for a missing row.
- **Durable test:** `tests/Workflow/RegulatoryWorkflowRegistryTest` (real kernel registry: every chain has
  steps, every approver role exists, the database equals the YAML after alignment, drift is reported and
  aligned in place); loader and end-to-end tests read the real YAML.
