# ADR — Workflow YAML Unification (Sprint Y.4)

**Date:** 2026-05-17
**Status:** Accepted
**Sprint:** Y.4 (Final) — implemented in PR feat/lifecycle-y4-workflow-deprecation

---

## Context

The platform accumulated three overlapping workflow systems over multiple development cycles:

1. **Legacy DB-backed `Workflow` / `WorkflowStep` entities** — Approval-chain definitions stored in the `workflows` and `workflow_steps` tables, created via the Workflow-Builder admin UI (`/workflow/definition/new`) or the `GenerateRegulatoryWorkflowsCommand`. Editable at runtime by ROLE_MANAGER users.

2. **`WorkflowAutoProgressionService`** — A field-completion auto-progression service that scanned entity fields and auto-approved workflow steps. Contained an AND/OR DSL, risk-appetite evaluator, and SLA-spawn logic. Called from 14+ controller and service files via explicit `checkAndProgressWorkflow()` calls.

3. **Regulatory YAML workflows** — Introduced in Sprint Y.2: 15 canonical regulatory workflows live as YAML files under `config/workflows/regulatory/*.yaml`. Each YAML contains `metadata.regulatory_metadata.steps` blocks as the single source of truth for step definitions, roles, SLAs, and auto-progression conditions.

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
- **No seeding required.** Fresh environments load workflow definitions from YAML at boot via `RegulatoryWorkflowLoader` — no manual `app:generate-regulatory-workflows` needed.
- **Unified auto-progression.** `FieldCompletionAutoTransition` listener handles all rule types (field_completion, auto, risk_appetite, AND/OR DSL) in one testable class.
- **PHPStan guard.** `tools/phpstan/Rule/NoNewWorkflowOrWorkflowStep.php` prevents future code from accidentally instantiating the deprecated entities in production code paths (exemptions: Repository, Command namespaces).
- **Data preserved.** Zero rows deleted. Historical WorkflowInstance display at `/workflow/instance/{id}` continues to work via preserved DB rows.

### Negative / Trade-offs

- **Tenant admins can no longer create entirely new workflow types via UI.** Custom workflows must be defined in YAML and deployed. This is intentional — custom regulatory-grade workflows require a deployment gate.
- **`app:generate-regulatory-workflows` still works** but emits a deprecation notice. It remains for tenants that need a DB-mirror of YAML data for legacy display. Long-term it should be removed once all callers are updated.
- **WorkflowService remains as a permanent facade.** Its 14 caller-files continue to work unchanged. The service delegates to `LifecycleService.transition()` internally (Y.0) but is not removed.

---

## Deprecation Deadlines

| Milestone | Date | Action |
|---|---|---|
| **D-1: Entities annotated** | 2026-06 (this sprint) | `@deprecated` PHPDoc on `Workflow` + `WorkflowStep`; PHPStan rule active |
| **D-2: New/Edit UI removed** | 2026-06 (this sprint) | `newDefinition` + `editDefinition` routes removed from `WorkflowController` |
| **D-3: Command deprecation** | 2026-09 | Remove `SeedIncidentWorkflowsCommand`, `SeedPolicyApprovalWorkflowCommand` from CI bootstrap; add deprecation warning to `GenerateRegulatoryWorkflowsCommand` |
| **D-4: Schema removal** | 2027-06 (earliest) | Drop `workflows` + `workflow_steps` tables ONLY after verifying zero active FK references from `workflow_instances`. Requires explicit release decision and migration. |

---

## Rollback Path

If the YAML-first approach introduces critical issues:

1. **Re-enable `newDefinition` / `editDefinition` routes** by reverting the WorkflowController changes (two actions, no DB schema change needed).
2. **Re-seed DB rows** via `php bin/console app:generate-regulatory-workflows --overwrite`.
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
