I now have enough understanding to design the migration plan. Note that I cannot write files (read-only mode), so I will provide the plan directly in my response. The user requested saving to `docs/superpowers/plans/2026-05-17-workflow-system-unification.md`, but per the system prompt I cannot create files. I'll deliver the plan content here.

## Critical Note on Constraints

The user requested saving the plan to `docs/superpowers/plans/2026-05-17-workflow-system-unification.md`. However, my system prompt strictly prohibits creating any files in READ-ONLY MODE. I'll deliver the complete plan content in this response so the user (or another agent) can save it.

---

# Workflow System Unification — Migration Plan

**Target path:** `docs/superpowers/plans/2026-05-17-workflow-system-unification.md`
**Date:** 2026-05-17
**Status:** Design / pre-implementation
**Effort estimate:** ~15 dev-days (Sprints Y.0–Y.4)
**Depends on:** Lifecycle Foundation Pilot (Sprint X.0) shipped; planned for X.1–X.5

## Executive Summary

The platform currently runs THREE partly-overlapping status systems: (1) the legacy `WorkflowService` driving DB-backed `Workflow / WorkflowInstance / WorkflowStep` approval chains, (2) the `WorkflowAutoProgressionService` that scans entity fields and force-progresses chain-steps, and (3) the new Symfony Workflow `LifecycleService` shipped in Sprint X.0 that controls `entity.status` state-machines (pilot on `Document`). The Workflow-Builder admin UI edits the legacy DB tables; the 5 regulatory workflows (`GenerateRegulatoryWorkflowsCommand`) and 10 extended ones (DSR, CAPA, Change-Request, Management-Review, etc.) all live as imperative PHP in that command.

The migration unifies all three under a single mental model: **two state-machines per entity** — an *entity-stage* SM (`entity.status`) plus an *approval-chain* SM on `WorkflowInstance.status` — both driven by Symfony Workflow YAMLs and merged through a new `WorkflowAutoProgressionBridge` listener (already stubbed in `config/packages/lifecycle.yaml` under `lifecycle.approval_bridges`). The `WorkflowService` public API remains stable so 14 caller-files (controllers + 6 dedicated workflow-services) are untouched; internals delegate to Symfony Workflow transitions. The `WorkflowAutoProgressionService` deprecates into the existing `FieldCompletionAutoTransition` listener (extended with AND/OR + risk-appetite conditions). The Workflow-Builder admin UI re-purposes to edit YAML configs via a DB-mirror (sandbox table). The 15 regulatory workflow definitions (5 core + 10 extended) ship as YAML files under `config/workflows/regulatory/*.yaml`.

## Architecture Diagram

### BEFORE (today)

```
┌──────────────────────────────────────────────────────────────────┐
│  Entity (Risk/Incident/DataBreach/...)                           │
│    .status  ←────── 109 ad-hoc setStatus() call-sites            │
│                ├──── DocumentController.bulkStatusChange         │
│                ├──── LifecycleService (Symfony WF) — Document only│
│                └──── inline setStatus($newStatus) elsewhere       │
└─────────┬────────────────────────────────────────────────────────┘
          │ Doctrine postUpdate
          ▼
┌──────────────────────────────────────────────────────────────────┐
│  WorkflowAutoProgressionService                                  │
│    .checkAndProgressWorkflow($entity, $user)  ← 14 call-sites    │
│    Reads WorkflowStep.metadata['autoProgressConditions']         │
│    Evaluates: field_completion | auto | risk_appetite            │
│      + AND/OR expression language                                │
└─────────┬────────────────────────────────────────────────────────┘
          │ Auto-approve
          ▼
┌──────────────────────────────────────────────────────────────────┐
│  WorkflowService (legacy chain orchestrator)                     │
│    .startWorkflow / approveStep / rejectStep / cancelWorkflow    │
│    .moveToNextStep  — manual index-based step traversal          │
│    Persists: WorkflowInstance.status ∈ {pending,in_progress,     │
│             approved, rejected, cancelled}  ← string field       │
└─────────┬────────────────────────────────────────────────────────┘
          │ ORM
          ▼
┌──────────────────────────────────────────────────────────────────┐
│  DB tables                                                       │
│    workflows           ← WorkflowBuilder UI writes here          │
│    workflow_steps      ← stepOrder + JSON metadata.autoProgress  │
│    workflow_instances  ← status + completedSteps[] + history JSON│
└──────────────────────────────────────────────────────────────────┘
          ▲
          │
┌──────────────────────────────────────────────────────────────────┐
│ Workflow-Builder UI (templates/workflow/builder.html.twig)       │
│  WorkflowController + Api\WorkflowStepApiController              │
│  Drag-drop steps, edit metadata JSON inline                      │
└──────────────────────────────────────────────────────────────────┘
          ▲
          │
┌──────────────────────────────────────────────────────────────────┐
│  GenerateRegulatoryWorkflowsCommand                              │
│  15 hard-coded PHP arrays creating Workflow + WorkflowStep rows  │
└──────────────────────────────────────────────────────────────────┘
```

### AFTER (target)

```
┌──────────────────────────────────────────────────────────────────┐
│  Entity .status  ←─────── LifecycleService.transition()          │
│                              (Symfony Workflow state_machine)    │
│                              defined in config/workflows/*.yaml  │
└─────────┬────────────────────────────────────────────────────────┘
          │ Doctrine postUpdate
          ▼
┌──────────────────────────────────────────────────────────────────┐
│  FieldCompletionAutoTransition (extended)                        │
│   - field_completion rules            (existing)                 │
│   - auto + risk_appetite rules        (folded in from WAPS)      │
│   - AND/OR condition DSL              (folded in from WAPS)      │
│   - Reads lifecycle.auto_transition_rules + lifecycle.approval_  │
│     auto_rules from config/packages/lifecycle.yaml               │
└─────────┬─────────────────────────────────┬──────────────────────┘
          │ Fires entity-stage transition   │ Fires approval-chain  
          ▼                                 ▼
┌──────────────────────┐         ┌──────────────────────────────────┐
│ Entity-stage SM       │  bridge │ WorkflowInstance-stage SM        │
│ (e.g. data_breach_    │ ◄─────► │ (workflow_instance_lifecycle)    │
│  lifecycle)           │ Approval│ places: pending, in_progress,    │
│ places: draft → … →   │ Chain   │   approved, rejected, cancelled  │
│  closed               │ Bridge  │ + current_step_index (state-data)│
└──────────────────────┘         └─────────┬────────────────────────┘
                                            │
                                            ▼
┌──────────────────────────────────────────────────────────────────┐
│  WorkflowService (FACADE — same public API, new internals)       │
│    startWorkflow()  → LifecycleService.transition(WI, 'start')   │
│    approveStep()    → LifecycleService.transition(WI, 'approve_  │
│                          step_N') OR step-internal place machine │
│    rejectStep()     → LifecycleService.transition(WI, 'reject')  │
│    cancelWorkflow() → LifecycleService.transition(WI, 'cancel')  │
│    moveToNextStep() → internal helper that bumps step-index      │
└─────────┬────────────────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────────────────────────┐
│  YAML truth                                                      │
│  config/workflows/workflow_instance.yaml  (approval-chain SM)    │
│  config/workflows/regulatory/                                    │
│     gdpr_data_breach.yaml      ← steps + roles + sla + auto-rules│
│     incident_high_severity.yaml                                  │
│     incident_low_severity.yaml                                   │
│     risk_treatment.yaml                                          │
│     dpia.yaml                                                    │
│     (+10 extended workflows)                                     │
└──────────────────────────────────────────────────────────────────┘
          ▲
          │ DB-mirror sync (cache:clear on write)
          │
┌──────────────────────────────────────────────────────────────────┐
│  Workflow-Builder UI (re-purposed)                               │
│  Symfony admin route /admin/workflows/builder/{name}             │
│  - Reads YAML via Workflow\Registry                              │
│  - Writes per-tenant overrides into lifecycle_config table       │
│  - Read-only for places/transitions structure                    │
│  - Editable: roles, sla_days, auto_progress_rules, reason_req'd, │
│              4_eyes, module                                      │
└──────────────────────────────────────────────────────────────────┘
```

## Inventory — what gets touched

### Existing files / classes

| File | Role | Migration target |
|---|---|---|
| `src/Service/WorkflowService.php` | Multi-step orchestrator | KEEP — refactored to delegate to Symfony WF (Sprint Y.0); public API unchanged |
| `src/Service/WorkflowAutoProgressionService.php` | Field-completion auto-progress | DEPRECATE (Sprint Y.1) — logic merged into `FieldCompletionAutoTransition` |
| `src/Service/WorkflowAutoTriggerService.php` | Triggers workflows on entity create/update | KEEP — its calls now go through facade unchanged |
| `src/Service/IncidentEscalationWorkflowService.php` | Spawns incident workflow via WorkflowService | UNCHANGED — uses facade |
| `src/Service/RiskAcceptanceWorkflowService.php` | Spawns risk workflow via WorkflowService | UNCHANGED — uses facade |
| `src/Service/RiskTreatmentPlanApprovalService.php` | Risk treatment plan approval | UNCHANGED — uses facade |
| `src/Service/DocumentApprovalService.php` | Document approval via WorkflowService | UNCHANGED — uses facade |
| `src/Service/IncidentRiskFeedbackService.php` | Incident→Risk feedback loop | UNCHANGED — uses facade |
| `src/Service/DataBreachService.php` | Calls `checkAndProgressWorkflow` | UNCHANGED — no-op once postUpdate listener takes over |
| `src/Service/ProcessingActivityService.php` | Calls `checkAndProgressWorkflow` | UNCHANGED — no-op once postUpdate listener takes over |
| `src/Service/DataProtectionImpactAssessmentService.php` | Calls `checkAndProgressWorkflow` | UNCHANGED |
| `src/Entity/Workflow.php` | DB-backed workflow definition | DEPRECATE entity (Sprint Y.4), tag `@deprecated`, keep rows for backwards-compat read-only |
| `src/Entity/WorkflowStep.php` | DB-backed step row | KEEP — used as approval-chain "step bookmark" attached to WorkflowInstance, but read-only |
| `src/Entity/WorkflowInstance.php` | Per-entity approval-chain | KEEP — gains `lockVersion`, `currentStepIndex`, new initial_marking |
| `src/Repository/WorkflowRepository.php` | Lookup workflows | KEEP read-only, may swap to YAML lookup in Y.3 |
| `src/Repository/WorkflowInstanceRepository.php` | Active/overdue/statistics | UNCHANGED |
| `src/Controller/WorkflowController.php` | Inbox + builder routes | KEEP inbox routes; builder routes re-pointed in Y.3 |
| `src/Controller/Api/WorkflowStepApiController.php` | Drag-drop CRUD for steps | DEPRECATE (Y.3) — replaced by YAML-write API |
| `src/Form/WorkflowType.php`, `WorkflowStepType.php`, `WorkflowInstanceType.php` | Builder forms | KEEP `WorkflowInstanceType` (inbox use); `WorkflowType/WorkflowStepType` become read-only views in Y.3 |
| `src/Command/GenerateRegulatoryWorkflowsCommand.php` | 15 hard-coded workflows | DEPRECATE (Sprint Y.2); add `--from-yaml` mode that just confirms YAMLs are registered |
| `src/Command/SeedIncidentWorkflowsCommand.php` | Seeds incident workflows | DEPRECATE (Y.2) — YAML replaces |
| `src/Command/SeedPolicyApprovalWorkflowCommand.php` | Seeds policy approval | DEPRECATE (Y.2) — YAML replaces |
| `src/Command/ProcessTimedWorkflowsCommand.php` | Cron: time-based auto-progress + SLA tick | UPDATE (Y.1) — uses new listener instead of WAPS |
| `src/Lifecycle/EventListener/FieldCompletionAutoTransition.php` | New unified listener | EXTEND in Y.1 with AND/OR DSL, risk_appetite type, approval-chain mode |
| `config/packages/lifecycle.yaml` | Auto-rules + approval bridges | EXTEND in Y.1: add `lifecycle.approval_auto_rules`, populate `approval_bridges` |
| `config/workflows/*.yaml` | Entity-stage workflows | Y.2 adds `config/workflows/regulatory/*.yaml` plus `workflow_instance.yaml` |
| `templates/workflow/builder.html.twig`, `_steps_builder.html.twig`, `definitions.html.twig`, `definition_*.html.twig` | Admin UI | Y.3 — re-target to YAML-overlay editor |

### Routes

| Route | Current | After |
|---|---|---|
| `GET /workflow/` | Inbox + statistics | KEEP unchanged |
| `GET /workflow/pending` | User's pending approvals | KEEP — query unchanged |
| `GET /workflow/active`, `/overdue` | Active/overdue lists | KEEP |
| `GET /workflow/instance/{id}` | Instance show + approve buttons | KEEP — buttons call same routes, controller delegates to facade |
| `POST /workflow/instance/{id}/approve\|reject\|clarify\|cancel` | Approval actions | KEEP — controller unchanged; service internals call `LifecycleService` |
| `POST /workflow/start/{entityType}/{entityId}` | Manual workflow start | KEEP |
| `GET /workflow/definitions` | List workflow defs | RE-PURPOSE Y.3 — lists YAML-registered workflows + tenant override-count |
| `GET /workflow/definition/{id}` | Show DB-stored workflow | RE-PURPOSE Y.3 — show YAML+overrides view |
| `GET /workflow/definition/{id}/builder` | Drag-drop builder | DEPRECATE Y.3 — replace with `admin_workflow_overlay_edit` |
| `POST /workflow/definition/new` | Create DB workflow | REMOVE Y.3 (admin must edit YAML files; no DB-create) |
| `POST /workflow/definition/{id}/edit\|delete\|toggle` | Edit/delete/toggle | DOWNGRADE Y.3 — toggle still works (per-tenant disable); edit/delete removed |
| `GET /api/workflow/{id}/steps` and CRUD endpoints | Step API | DEPRECATE Y.3 |

### DB schema

| Table | Current | After |
|---|---|---|
| `workflow_instances` | id, workflow_id, entity_type, entity_id, status (str), current_step_id, completed_steps (JSON), approval_history (JSON), comments, started_at, completed_at, due_date, witness_user_id, witnessed_at, tenant_id | KEEP. Y.0 adds: `lock_version INT NOT NULL DEFAULT 0` (Doctrine `#[ORM\Version]`). `status` field now Symfony Workflow marking-store. Initial marking `pending`. |
| `workflow_steps` | id, workflow_id, name, description, step_order, step_type, approver_role, approver_users (JSON), is_required, days_to_complete, metadata (JSON), tenant_id | KEEP READ-ONLY. Y.4 ships PHPDoc `@deprecated` notice. Rows preserved for historical instance display. |
| `workflows` | id, name, description, entity_type, is_active, metadata (JSON), tenant_id | DEPRECATE Y.4. Read-only after Y.3. Schema-drop is OUT OF SCOPE — preserve rows for audit trail.  |
| `lifecycle_config` | tenant_id, workflow_name, transition_name, key, value (JSON) | EXTEND Y.3 to handle workflow-builder overlay (roles, sla_days, auto-rules); also gains workflow-instance-scoped rows for approval-step config |
| (NEW Y.3) `workflow_yaml_overlay` | OPTIONAL: tenant_id, workflow_name, step_index, key, value (JSON) | NEW IF DB-MIRROR chosen — see open Q #1 |

## Decisions

### D1. WorkflowInstance.status becomes a Symfony Workflow state_machine

New file `config/workflows/workflow_instance.yaml`:

```yaml
framework:
    workflows:
        workflow_instance_lifecycle:
            type: state_machine
            marking_store:
                type: method
                property: status
            supports:
                - App\Entity\WorkflowInstance
            initial_marking: pending
            places:
                - pending
                - in_progress
                - approved
                - rejected
                - cancelled
            transitions:
                start:
                    from: pending
                    to: in_progress
                    metadata:
                        roles: [ROLE_USER]
                approve_step:
                    from: in_progress
                    to: in_progress      # self-loop while steps remain
                    metadata:
                        roles: [ROLE_USER]   # actual role check is on step.approverRole
                complete:
                    from: in_progress
                    to: approved
                reject:
                    from: in_progress
                    to: rejected
                    metadata:
                        reason_required: true
                cancel:
                    from: [pending, in_progress]
                    to: cancelled
                    metadata:
                        roles: [ROLE_ADMIN]
                        reason_required: true
                reopen:
                    from: [approved, rejected, cancelled]
                    to: in_progress
                    metadata:
                        roles: [ROLE_ADMIN]
                        reason_required: true
```

The same `LifecycleService.transition()` + `LifecycleVoter` + `AuditLogListener` apparatus shipped in Sprint X.0 then automatically covers WorkflowInstance status changes — meaning the audit-log AUD-02 integrity-signature, ISO 27001 Cl. 7.5.3 documented-information, tenant isolation, and 4-eyes guards already work on approval chains too without writing new infrastructure.

### D2. WorkflowService.moveToNextStep refactor — facade pattern

Public signature preserved. The new internals:

- `startWorkflow($entityType, $entityId, ?$workflowName)` — finds workflow (YAML lookup), creates `WorkflowInstance` row with `status = 'pending'`, persists, then calls `$this->lifecycleService->transition($instance, 'workflow_instance_lifecycle', 'start')`.
- `approveStep(WI, $user, ?$comments)` — checks user-can-approve (unchanged), records history entry, marks step complete, calls helper `advanceToNextStep()`. The helper either calls `transition('approve_step')` (more steps remain) or `transition('complete')` (final step) on the WI's state machine. Symfony WF events drive audit-log; ReasonValidator handles required-reason on `reject`/`cancel`/`reopen`.
- `moveToNextStep(WI)` — kept as helper. Reads workflow.steps array, finds current index, returns next or null. No longer mutates `status` directly; that's the transition's job.
- `rejectStep` / `cancelWorkflow` — call `transition('reject' | 'cancel')`.

**Why this matters:** zero caller-changes. All 14 caller files (controllers + 6 dedicated services + `ProcessTimedWorkflowsCommand`) keep working unchanged. We get Symfony WF events, audit log, optimistic locking (via new `WorkflowInstance.lockVersion`), Guard support, and dump/profiler for free.

### D3. WorkflowAutoProgressionService deprecation

The existing `FieldCompletionAutoTransition` listener handles `field_completion` rules for entity-stage SMs. We extend it to also handle:

1. **AND/OR condition DSL** — port `evaluateComplexCondition()` from WAPS.
2. **`risk_appetite` type** — port `checkRiskAppetite()` to a new `RiskAppetiteAutoTransitionRule` strategy class (avoid bloating the listener).
3. **Approval-chain mode** — new rule structure `lifecycle.approval_auto_rules` in `config/packages/lifecycle.yaml`:

```yaml
parameters:
    lifecycle.approval_auto_rules:
        App\Entity\DataBreach:
            assess_step:
                workflow: workflow_instance_lifecycle    # the WI state-machine
                step_metadata_key: autoProgressConditions  # read from active step's YAML metadata
                # When all required_fields complete AND condition true,
                # fire 'approve_step' transition on the WorkflowInstance.
```

In practice we don't need a separate config for this: `FieldCompletionAutoTransition` reads the *current step* of the active `WorkflowInstance` for the entity, looks at the step's metadata in the YAML (loaded via `Workflow\Registry`-attached helper), evaluates conditions, and on match calls `WorkflowService::approveStep($wi, $systemUser)`. This converges all auto-progression behavior into ONE listener.

**SLA spawning** (`maybeSpawnSlaMonitor`) moves to a separate `WorkflowApprovalStepEntered` listener on the `workflow.workflow_instance_lifecycle.entered.in_progress` event, dispatched once per `approve_step` transition. Keeps SLA logic decoupled.

### D4. Workflow-Builder UI re-purpose — overlay editor

Recommendation: **YAML is canonical truth; admin-UI edits per-tenant overlays via the existing `lifecycle_config` table only**. No file-system writes (avoids race conditions, requires container rebuild on cloud deployments, breaks audit trail). The builder UI becomes a *visual overlay editor* for the 4 admin-overrideable metadata keys (`roles`, `reason_required`, `four_eyes`, `module`) plus 3 approval-chain-specific keys (`approverRole`, `approverUsers`, `daysToComplete`, `autoProgressConditions`). The drag-drop step-reorder feature is REMOVED — step order is structural and must live in YAML.

This matches the architecture already shipped in `LifecycleOverridesController` (Sprint X.3 spec). The Workflow-Builder UI essentially becomes a *more user-friendly view* over the same overlay mechanism, with grouping by workflow name and an approval-chain visualization.

Alternative considered (filesystem-write): too invasive for cloud deployments, needs explicit `kernel.cache:clear` after every save, audit trail harder. Rejected.

### D5. 15 regulatory workflows migrate to YAML

`config/workflows/regulatory/`:
- `gdpr_data_breach.yaml` — 6-step approval-chain with 72h SLA
- `incident_high_severity.yaml` — 6-step CISO→Crisis→Tech→Legal→Recovery→Mgmt
- `incident_low_severity.yaml` — 4-step Triage→Investigate→Remediate→Review
- `risk_treatment.yaml` — 6-step conditional approval (severity-gated)
- `dpia.yaml` — 6-step DPIA with GDPR Art. 36 conditional consultation
- `dsr.yaml` — 5-step Data Subject Request
- `capa.yaml` — 5-step Corrective Action with loop-back
- `change_request.yaml` — 4-step CAB
- `management_review.yaml` — 4-step ISO 9.3
- `control_verification.yaml` — 3-step with loop-back
- `supplier_assessment.yaml` — 5-step
- `training_verification.yaml` — 2-step
- `bc_plan_activation.yaml` — 5-step ISO 22301
- `document_review.yaml` — 3-step periodic review
- `incident_post_mortem.yaml` — 3-step post-incident

Schema extension: each workflow YAML adds top-level `regulatory_metadata` block:

```yaml
framework:
    workflows:
        gdpr_data_breach:
            type: state_machine
            marking_store: { type: method, property: status }
            supports: [App\Entity\WorkflowInstance]   # approval-chain SM
            initial_marking: pending
            places: [pending, in_progress, approved, rejected, cancelled]
            transitions:
                # same 6 places as workflow_instance_lifecycle.yaml — inheritance via YAML import
                ...
            metadata:
                regulatory_metadata:
                    standard: "GDPR Art. 33/34"
                    sla_hours: 72
                    escalation_threshold_hours: 60
                    escalation_role: ROLE_ADMIN
                steps:
                    - name: "Initial Assessment (DPO)"
                      order: 1
                      approver_role: ROLE_DPO
                      days_to_complete: 1
                      auto_progress_conditions:
                          type: field_completion
                          entity: DataBreach
                          fields: [severity, affectedDataSubjectsCount, dataCategories, notificationRequired]
                    - name: "Technical Assessment (CISO)"
                      ...
```

**Reason for embedded steps block:** approval-chain step-data is heavy (role, SLA, auto-progress, loop-back). The workflow SM itself only has 5 transitions. The 6 *approval steps* are state-data inside the SM's `in_progress` place, navigated via `WorkflowInstance.currentStepIndex` (new int column, see Y.0 migration). A new `RegulatoryWorkflowLoader` service reads the `metadata.steps` block at boot and registers it with `WorkflowService`.

### D6. Bridging events — entity-stage ↔ approval-chain

The bridge listener already stubbed (`lifecycle.approval_bridges` in `config/packages/lifecycle.yaml`) needs the *reverse* direction too:

- **Forward (existing):** `WorkflowInstance.complete` → fire `transition(entity, 'approve')` on entity-stage SM.
- **Reverse (new, Sprint Y.1):** When entity-stage SM transitions to a "needs-approval" place, spawn a `WorkflowInstance` for the configured approval chain. New `EntityStageApprovalSpawner` listener on `workflow.{entity_lifecycle}.entered.{place}` events.

This lets, e.g., `Risk.status` transitioning to `assessed` spawn the risk-treatment approval chain automatically; once chain completes, entity-stage moves to `treatment_approved`.

## Migration Sprints

### Sprint Y.0 — WorkflowInstance becomes state-machine [BLOCKING]

**Files to touch:**
- CREATE `config/workflows/workflow_instance.yaml` (D1 above)
- MODIFY `config/packages/workflow.yaml` — add import for new YAML
- MODIFY `src/Entity/WorkflowInstance.php` — add `#[ORM\Version] private int $lockVersion = 0;`
- CREATE `migrations/Version20260520XXXXXX_AddLockVersionAndStepIndexToWorkflowInstance.php` — adds `lock_version INT NOT NULL DEFAULT 0` and `current_step_index INT NOT NULL DEFAULT 0` columns; `isTransactional() = false`
- MODIFY `src/Service/WorkflowService.php` — inject `LifecycleService`, replace direct `setStatus()` calls with `$this->lifecycleService->transition($wi, 'workflow_instance_lifecycle', $transitionName, $user, $reason)`. Add private helper `advanceToNextStep(WI)` that returns next `WorkflowStep` or null and updates `current_step_index`.
- CREATE `src/Lifecycle/EventListener/WorkflowApprovalStepEntered.php` — listens on `workflow.workflow_instance_lifecycle.completed.approve_step` to spawn SLA monitor (logic ported from `WorkflowAutoProgressionService::maybeSpawnSlaMonitor`)
- CREATE `tests/Service/WorkflowServiceSymfonyWorkflowDelegationTest.php` — verifies all 6 public methods delegate to LifecycleService correctly
- CREATE `tests/Entity/WorkflowInstanceLockVersionTest.php` — concurrent edit yields `OptimisticLockException` → caller surface 409
- MODIFY `tests/Service/WorkflowServiceTest.php` (existing) — keep green

**Acceptance criteria:**
- `bin/console workflow:dump workflow_instance_lifecycle` produces graph
- All 14 caller files run unchanged; existing tests green
- `workflow_instance` table has `lock_version` column; concurrent `approveStep` from two browsers yields 409
- Audit log gains `action='status_change'` row per WI transition with `workflow_name = workflow_instance_lifecycle`
- `WorkflowService.moveToNextStep` no longer directly sets `status`; instead increments `current_step_index` and lets SM transition do status change
- `lint:workflow` CI gate green for new YAML

**Effort:** ~3 dev-days

---

### Sprint Y.1 — Merge WorkflowAutoProgressionService into FieldCompletionAutoTransition

**Files to touch:**
- MODIFY `src/Lifecycle/EventListener/FieldCompletionAutoTransition.php` — add 3 strategies:
  - Branch A: existing entity-stage rule (current behavior)
  - Branch B (NEW): approval-chain rule. When entity has active WI, lookup current step's metadata, evaluate field_completion / auto / risk_appetite conditions (port logic), if match call `WorkflowService::approveStep($wi, $systemUser)` with `$autoProgression = true` flag.
  - Branch C: AND/OR DSL handler in shared `ConditionEvaluator` service (NEW: `src/Lifecycle/Config/ConditionEvaluator.php`) — port `evaluateComplexCondition()` + `evaluateCondition()` + `compareValues()` from WAPS verbatim
- CREATE `src/Lifecycle/Config/RiskAppetiteRule.php` — strategy class wrapping `RiskAppetiteRepository`, evaluates `risk_appetite` type. Injected via DI tag.
- MODIFY `config/packages/lifecycle.yaml` — add `lifecycle.approval_auto_rules` param. Reuses `WorkflowService` lookup so no per-entity config needed; rules live in the YAML workflow step metadata.
- MODIFY `src/Service/WorkflowService.php` — add `$autoProgression = false` boolean flag to `approveStep` signature (default false preserves behavior), used in history-entry `action` field.
- MODIFY `src/Command/ProcessTimedWorkflowsCommand.php` — replace `WorkflowAutoProgressionService` injection with `WorkflowInstanceRepository` + `WorkflowService`; iterate active instances, evaluate `time_based` condition (NEW: 4th condition type to add — currently inferred from doc comments, not in WAPS code), advance via `approveStep`.
- DEPRECATE `src/Service/WorkflowAutoProgressionService.php` — keep class as @deprecated facade that forwards to new listener for one release; add deprecation notice in class header.
- MODIFY all 14 caller-files: `Controller/AssetController.php`, `Controller/StatementOfApplicabilityController.php`, `Controller/IncidentController.php`, `Controller/RiskController.php` (3 calls), `Service/ProcessingActivityService.php`, `Service/DataBreachService.php`, `Service/DataProtectionImpactAssessmentService.php` — REMOVE `checkAndProgressWorkflow` calls (now handled automatically by Doctrine postUpdate listener). One follow-up commit clears DI injections.
- CREATE `tests/Lifecycle/EventListener/FieldCompletionAutoTransitionApprovalChainTest.php` — 6 scenarios: field_completion match, AND-condition, OR-condition, risk_appetite, no-match no-op, missing-WI no-op.

**Acceptance criteria:**
- Saving a `DataBreach` with severity/count/categories/notificationRequired filled auto-approves the current step (existing behavior preserved)
- `php bin/console doctrine:cache:clear-result` doesn't change WI state (no caching bleed)
- Test suite green; 14 callers no longer mention `WorkflowAutoProgressionService`
- Deprecation notices fire in `var/log/dev.log` for any remaining caller

**Effort:** ~2 dev-days

---

### Sprint Y.2 — Regulatory workflows migrate to YAML

**Files to touch:**
- CREATE 15 YAMLs under `config/workflows/regulatory/`:
  - `gdpr_data_breach.yaml`
  - `incident_high_severity.yaml`
  - `incident_low_severity.yaml`
  - `risk_treatment.yaml`
  - `dpia.yaml`
  - `dsr.yaml`
  - `capa.yaml`
  - `change_request.yaml`
  - `management_review.yaml`
  - `control_verification.yaml`
  - `supplier_assessment.yaml`
  - `training_verification.yaml`
  - `bc_plan_activation.yaml`
  - `document_review.yaml`
  - `incident_post_mortem.yaml`
- MODIFY `config/packages/workflow.yaml` — add `imports:` for `regulatory/*.yaml` (use glob if Symfony supports; otherwise enumerate)
- CREATE `src/Workflow/Loader/RegulatoryWorkflowLoader.php` — at boot, reads `metadata.steps` block from each registered workflow YAML and provides `getStepsForWorkflow($name): array` API. Injected into `WorkflowService` to replace `WorkflowRepository::findOneBy()`-based step lookup. Falls back to DB lookup if YAML not registered (backwards-compat).
- MODIFY `src/Service/WorkflowService.php` — `startWorkflow()` now tries YAML loader first, falls back to DB.
- MODIFY `src/Command/GenerateRegulatoryWorkflowsCommand.php` — keep as backwards-compat seeder (re-creates DB rows from YAMLs for tenants that need DB-mirror), add deprecation warning.
- DEPRECATE `src/Command/SeedIncidentWorkflowsCommand.php`, `src/Command/SeedPolicyApprovalWorkflowCommand.php` — mark with @deprecated; remove from CI bootstrap.
- CREATE `tests/Workflow/Loader/RegulatoryWorkflowLoaderTest.php` — verifies all 15 YAMLs load, return 2-6 steps each, role/SLA/auto-progress conditions parse correctly.
- CREATE `tests/Integration/RegulatoryWorkflowEndToEndTest.php` — for each of the 5 critical workflows (data-breach, incident-high, risk-treatment, dpia, dsr): start workflow, auto-progress through all steps, verify final approved state.

**Acceptance criteria:**
- `bin/console workflow:dump gdpr_data_breach` produces a valid graphviz dump
- 15 YAMLs registered in `Workflow\Registry`
- `lint:workflow` CI gate enforces: every regulatory workflow YAML supports `WorkflowInstance` class, has required `metadata.regulatory_metadata` block, every step has `name`/`approver_role`/`days_to_complete`
- End-to-end test: create DataBreach, fill 4 fields, observe auto-progress through 6 approval steps, observe entity-stage SM transitioning to `closed`
- `php bin/console app:generate-regulatory-workflows` emits deprecation notice but still creates DB rows for legacy display

**Effort:** ~2 dev-days

---

### Sprint Y.2b — Entity show-page lifecycle UI sweep [BLOCKING for Y.3]

**Why:** X.3 (PR #405) integrated lifecycle UI macros only on Document / ProcessingActivity / ISMSObjective show-pages. Sprint X.1 added PolicyTemplate + Asset, X.2 added 10 more entities — total 12 new workflows ship without their corresponding entity-show-page UI. Without this sprint, end-users only see the new lifecycle on 3 of 15 entities, while admins switch them via overlay-config — confusing.

This sprint is the **prerequisite for Y.3**: the legacy Workflow-Builder UI can only be deprecated AFTER every entity's show-page exposes the new lifecycle. Otherwise users lose visibility into their approval-chain progress.

**Scope:** For each of the 12 X.1+X.2 entities, edit the show-page template to:
1. Replace existing status badge with `_fa_status_pill.pill(entity)` macro
2. Add `_lifecycle_actions.dropdown(entity, '<slug>', '<workflow>')` macro near header actions
3. Add Status-History tab using `_lifecycle_history_tab.history(entity_class, entity_id)` macro
4. Verify lifecycle-route attributes are gated to the entity's allowed transitions via the new LifecycleVoter

**Per-entity work:**
- `templates/data_breach/show.html.twig` (DataBreach — privacy)
- `templates/incident/show.html.twig` (Incident — CSIRT chain)
- `templates/risk/show.html.twig` (Risk — accepted/treated)
- `templates/dpia/show.html.twig` (DataProtectionImpactAssessment)
- `templates/corrective_action/show.html.twig` (CAPA)
- `templates/audit_finding/show.html.twig` (AuditFinding)
- `templates/internal_audit/show.html.twig` (InternalAudit)
- `templates/vulnerability/show.html.twig` (Vulnerability)
- `templates/data_subject_request/show.html.twig` (DSR)
- `templates/consent/show.html.twig` (Consent)
- `templates/policy_template/show.html.twig` (PolicyTemplate)
- `templates/asset/show.html.twig` (Asset)

If a show-page does not exist for a given entity (some admin-only entities have only list-views): skip with a comment in the plan.

**Bulk-action-bar update:** For each entity-list-view that uses `_bulk_action_bar.html.twig`, verify the `status_change` action option now reads from the new lifecycle config (already generalized in X.3). Mostly a re-test, not new code.

**Persona-dashboard tiles:** ROLE_CISO / ROLE_DPO / ROLE_RISK_MANAGER dashboards should surface lifecycle-stuck-entity counts (already covered by `LifecycleStuckInStatusRule` AlvaHint from X.4). No new code, just verification.

**Acceptance:**
- All 12 show-pages render lifecycle-pill + transition dropdown + history tab
- E2E quality audit confirms each entity-type shows correct workflow info under `/de/<entity>/{id}`
- No raw `XX.status.foo` translation placeholders visible
- Bulk-action-bar status-change works for at least 1 entity per type (smoke)
- Quality-gate Twig macro-scope check stays green

**Files to touch:** 12 templates × small additions; 0-3 lines per entity in service-layer to expose `getLifecycleWorkflow()` if a custom slug-resolver is needed.

**Test coverage:** Smoke tests for each entity show-page already exist as part of existing controller-test suite. No new unit tests needed; X.3 macros already covered.

**Effort:** ~3 dev-days (mechanical sweep, low risk).

**Blocking for:** Y.3 — the legacy Workflow-Builder UI can only be removed after Y.2b ships and every entity uses the new lifecycle UI surface.

---

### Sprint Y.3 — Workflow-Builder UI re-purpose

**Files to touch:**
- CREATE `src/Controller/Admin/WorkflowOverlayController.php` — routes:
  - `GET /admin/workflows` — list of YAML-registered workflows with override-count
  - `GET /admin/workflows/{name}` — show structure (read-only) + tenant overrides
  - `GET|POST /admin/workflows/{name}/{stepIndex}/edit` — edit overridable keys for that step
  - `POST /admin/workflows/{name}/{stepIndex}/reset` — drop tenant overrides
- CREATE `src/Form/Admin/WorkflowStepOverlayType.php` — form for `approverRole`, `approverUsers`, `daysToComplete`, `autoProgressConditions` (JSON), `reasonRequired`, `fourEyes`, `module`
- MODIFY `src/Lifecycle/Config/LifecycleConfigResolver.php` — extend to support workflow-step-scoped overrides (new key shape: `workflow:gdpr_data_breach:step:2:approverRole`)
- MODIFY `src/Repository/LifecycleConfigRepository.php` — add `findByWorkflowAndStep()` method
- CREATE templates:
  - `templates/admin/workflows/index.html.twig`
  - `templates/admin/workflows/show.html.twig`
  - `templates/admin/workflows/edit_step.html.twig`
- MODIFY `src/Service/Admin/AdminHubCatalog.php` — add `admin_workflow_overlay_index` to `audit_compliance` group (next to `admin_lifecycle_overrides_index`)
- DEPRECATE `src/Controller/Api/WorkflowStepApiController.php` — annotate @deprecated; return 410 Gone on POST/PATCH/DELETE; keep GET for backwards-compat for one release
- DEPRECATE routes in `src/Controller/WorkflowController.php`:
  - `app_workflow_definition_builder` — redirect to `admin_workflow_overlay_show`
  - `app_workflow_definition_new` — return 410 with deprecation notice
  - `app_workflow_definition_edit` — same
  - `app_workflow_definition_delete` — keep for ROLE_ADMIN until Y.4
- REPURPOSE templates `templates/workflow/builder.html.twig`, `_steps_builder.html.twig`, `definition_form.html.twig` — replace drag-drop UI with read-only structural view + "Edit overrides" links to new admin URL
- ADD translation keys to `translations/admin.de.yaml`, `admin.en.yaml`, `workflows.de.yaml`, `workflows.en.yaml`
- CREATE `tests/Controller/Admin/WorkflowOverlayControllerTest.php` — 8 scenarios

**Acceptance criteria:**
- ROLE_ADMIN can edit `approverRole` for "Initial Assessment (DPO)" in GDPR Data Breach workflow → saved to `lifecycle_config`
- After save, new `WorkflowInstance` for `DataBreach` uses overridden role
- Other tenants unaffected (multi-tenant isolation)
- Old drag-drop UI returns 410 Gone with link to new editor
- AdminHub displays new entry; cyber-aurora tile rendering correct
- ISB/CISO personas can NOT delete YAML-defined workflows; toggleDefinition (per-tenant deactivate) still works

**Effort:** ~5 dev-days

---

### Sprint Y.4 — Workflow/WorkflowStep deprecation + data preservation

**Files to touch:**
- ANNOTATE `src/Entity/Workflow.php` with class-level `@deprecated since 2026-06 — use config/workflows/regulatory/*.yaml instead`
- ANNOTATE `src/Entity/WorkflowStep.php` with same notice (rows preserved for historical display only)
- MODIFY `src/Controller/WorkflowController.php`:
  - REMOVE: `newDefinition`, `editDefinition` actions
  - KEEP: `deleteDefinition` (ROLE_ADMIN only, used to clean up obsolete DB rows after migration)
  - KEEP: `toggleDefinition` (per-tenant on/off)
  - KEEP: `showDefinition`, `definitions` — now show YAML data + DB historical data side-by-side
- CREATE `src/Command/MigrateLegacyWorkflowsCommand.php` — one-shot CLI tool to verify all 15 DB-stored regulatory workflows have YAML equivalents; report mismatches; optionally archive obsolete DB rows.
- CREATE PHPStan rule `tools/phpstan/Rule/NoNewWorkflowOrWorkflowStep.php` — prevents `new App\Entity\Workflow()` or `new App\Entity\WorkflowStep()` in src/ outside the Repository/Command namespace.
- MODIFY `CLAUDE.md` (root) — add section "Workflow System" pointing to YAML configs as source of truth, link to ADR.
- CREATE `docs/architecture/adr/2026-05-17-workflow-yaml-unification.md` — ADR documenting the unification, listing 4 deprecation deadlines and rollback path.
- MODIFY `docs/WORKFLOW_AUTO_PROGRESSION.md` — update references to point at `FieldCompletionAutoTransition`.
- DELETE: nothing (data preservation principle — keep all DB rows for forensic audit-trail readability).
- ARCHIVE: `src/Service/WorkflowAutoProgressionService.php`, `src/Command/SeedIncidentWorkflowsCommand.php`, `src/Command/SeedPolicyApprovalWorkflowCommand.php` — move to `src/Deprecated/` directory? Open question (see #2).

**Acceptance criteria:**
- New PHPStan rule passes on entire codebase (proves no remaining production usage of `new Workflow()`)
- ADR merged describing the migration outcome and deprecation timeline
- CLAUDE.md updated; junior-implementer persona can find correct guidance in <30 seconds
- All existing `WorkflowInstance` rows still display correctly in `/workflow/instance/{id}` (data preservation acceptance)
- Doctrine schema-validate clean
- 30-day post-deploy: zero references to `WorkflowAutoProgressionService` in production logs (manual check)

**Effort:** ~3 dev-days

---

**Total: ~15 dev-days** (Y.0 + Y.1 + Y.2 + Y.3 + Y.4 = 3 + 2 + 2 + 5 + 3)

## Coverage Matrix

| Workflow | Entities using it | Approval-chain | Auto-progression trigger | Migration sprint |
|---|---|---|---|---|
| GDPR Data Breach | `DataBreach` | DPO → CISO → Mgmt notif → Authority → Subjects → Final (6 steps) | severity + count + categories + notificationRequired completed | Y.2 |
| Incident Response (High/Critical) | `Incident` | CISO → Crisis → Tech → Legal → Recovery → Mgmt (6 steps) | severity in {high, critical} on create | Y.2 |
| Incident Response (Low/Medium) | `Incident` | Triage → Investigate → Remediate → Review (4 steps) | severity in {low, medium} on create | Y.2 |
| Risk Treatment Plan Approval | `Risk` / `RiskTreatmentPlan` | RiskOwner → CISO → DPO → CFO → CEO → Audit notif (6 steps, severity-gated) | residualRisk vs risk-appetite check on plan-save | Y.2 |
| DPIA | `DataProtectionImpactAssessment` | DataOwner → DPO → CISO → RiskMgr → Mgmt → DPO final (6 steps) | residualRiskLevel + supervisoryConsultationDate per GDPR Art. 36 logic | Y.2 |
| Data Subject Request | `DataSubjectRequest` | Identity → Processing → DPO Review → Delivery → Extension (5 steps) | identityVerified + responseDescription + completedAt fields | Y.2 |
| Corrective Action (CAPA) | `CorrectiveAction` | RCA → Plan → Implement → Verify → Close (5 steps + loop-back) | rootCauseAnalysis + actualCompletionDate fields | Y.2 |
| Change Request | `ChangeRequest` | RiskAssess → CAB → Implement → Verify (4 steps + loop-back) | riskAssessment + actualImplementationDate fields | Y.2 |
| Management Review | `ManagementReview` | Input → Execute → Action → Follow-up (4 steps) | auditResults + decisions + actionItems | Y.2 |
| Control Verification | `Control` | RiskOwner → CISO → Auditor (3 steps + loop-back) | manual approvals only | Y.2 |
| Supplier Assessment | `Supplier` | Initial → DPO → Contract → DORA → Mgmt (5 steps + loop-back) | criticality + gdprAvContractSigned + contractStartDate + ictCriticality | Y.2 |
| Training Verification | `Training` | Complete → Manager (2 steps) | completionDate field | Y.2 |
| BC Plan Activation | `BusinessContinuityPlan` | Declare → Notify → Execute → Deactivate → Review (5 steps) | status = active | Y.2 |
| Document Review | `Document` | Review → Revise → Approve (3 steps) | timer-based + entity-stage SM | Y.2 |
| Incident Post-Mortem | `Incident` | Schedule → Review → Actions (3 steps, opt-in) | rootCause + lessonsLearned + correctiveActions | Y.2 |

## Edge Cases & Mitigations

| Edge | Mitigation |
|---|---|
| In-flight `WorkflowInstance` rows during Y.0 deploy (status=in_progress) | Y.0 migration sets `lock_version=0` on all existing rows; Symfony WF marking-store reads existing `status` value as initial marking (the 5 places match exactly). No data backfill needed. |
| Existing approval-history JSON | Preserved as-is. The new audit-log entries via `AuditLogListener` ADD to the audit trail; old `approval_history` JSON remains on the entity for historical display. |
| `WorkflowDefinition` rows referenced by foreign-key from `WorkflowInstance` | DEPRECATE-only, never DELETE. ON-DELETE behavior on `workflow_id` FK stays RESTRICT to prevent orphans. |
| Custom step transitions defined per-tenant via DB Workflow row | Tenant overrides convert to `lifecycle_config` rows during Y.3. CLI tool `app:migrate-tenant-workflow-overrides` (new in Y.4 implementation) converts existing DB-overrides to YAML-overlay format. |
| WorkflowAutoProgression's AND/OR DSL edge cases (deeply nested parentheses) | Port verbatim with full test suite from `WorkflowAutoProgressionServiceTest`. Add 5 new edge-case tests for `(a AND (b OR c)) AND d` patterns. |
| Loop-back transitions (CAPA "reject → back to Implementation") | Modeled as `from: in_progress, to: in_progress` self-loop on `approve_step` PLUS `current_step_index` decrement in the helper. Step metadata `rejectAction: loop_back` and `rejectTargetStep: N` honored by `WorkflowService::rejectStep()` — instead of transitioning to `rejected`, it decrements index and calls `transition('approve_step')`. |
| Concurrent edits during Y.0 rollout (someone hits Approve while migration runs) | `Version20260520XXXXXX` migration runs in NON-transactional mode (`isTransactional()=false`, CLAUDE.md pitfall #6). Add `lock_version` with `DEFAULT 0`. First write after migration starts versioning. |
| AlvaHint "stuck-workflow" stale during migration | `AlvaHintInvalidator` listener (X.0) already covers WI status transitions once Y.0 ships. Verify `LifecycleStuckInStatusRule` doesn't double-trigger for instances mid-migration. |
| 4-eyes-principle on `approve_step` self-loop | Reuse `FourEyesGuard` from X.4. Per-step `four_eyes: true` metadata read via `LifecycleConfigResolver`. |
| Notification recipients differ per tenant (overlay needed) | `approverUsers` (JSON) and `approverRole` are admin-overrideable through the new overlay editor (Y.3). |
| YAML config typo bricks production | `lint:workflow` CI gate extension: for every `regulatory_metadata.steps` entry, verify `approver_role` is a known Symfony Security role, `entity` (in auto-progress conditions) is a known Doctrine entity class, `fields` resolve to actual entity getters. |
| Time-based progression (cron) — `time_based` type referenced in `ProcessTimedWorkflowsCommand` docs | Currently not implemented in WAPS (only documented). Y.1 adds a 4th rule type: `time_based` with `delay_hours` and optional `condition`. Listener fires from cron, not Doctrine postUpdate. |
| Tests using `new Workflow()` directly | Tests excluded from PHPStan rule via `tests/` path. New tests use YAML fixture loader. |

## Automation Catalogue (post-migration)

After Y.0–Y.4 ship, the following auto-fires (zero-touch from controllers):

1. **Entity create** → `WorkflowAutoTriggerService` (or one of 6 dedicated workflow-spawners) calls `WorkflowService::startWorkflow()` → `LifecycleService.transition(WI, 'start')` → audit-log row + email notification to step 1 approvers
2. **Entity field-save** (postUpdate) → `FieldCompletionAutoTransition` listener evaluates rules for both entity-stage AND approval-chain SMs → on match, fires `transition('approve_step')` → SLA monitor spawned → audit-log → next step notification
3. **Cron tick** (`ProcessTimedWorkflowsCommand`) → for active WIs, evaluates `time_based` rules → auto-advances → SLA watcher tick
4. **WI complete** → `WorkflowApprovalChainBridge` (existing in lifecycle.yaml) → fires `transition(entity, 'approve')` on entity-stage SM
5. **Entity-stage enters "needs-approval" place** → `EntityStageApprovalSpawner` (NEW, Y.1) → spawns appropriate WorkflowInstance
6. **4-eyes-required transition** → `FourEyesGuard` requires existing `FourEyesApprovalRequest` → blocks transition with 422 if missing
7. **AlvaHint** → `LifecycleStuckInStatusRule` invalidated on every transition (entity OR WI) via `AlvaHintInvalidator`
8. **Audit-log** → `AuditLogListener` writes `action='status_change'` row on every transition with AUD-02 integrity signature
9. **Notifications** → existing `EmailNotificationService::sendWorkflowAssignmentNotification` triggered on step-entered events
10. **REST API** (`/api/...`, X.4 deliverable) — surfaces both lifecycles uniformly

## Open Questions / Decisions Needed

1. **Workflow-Builder UI editing approach** — overlay-only (lifecycle_config, my recommendation) vs DB-mirror table vs filesystem-write to YAML? Decision affects Y.3 scope (5 days assumes overlay-only).

2. **Deprecated service location** — keep deprecated `WorkflowAutoProgressionService` in `src/Service/` with `@deprecated` annotation, or move to `src/Deprecated/`? PSR-4 implication: moving requires composer autoload-update. Recommendation: leave in place with annotation, schedule removal in next major release.

3. **Per-tenant override storage** — extend `lifecycle_config` (current resolver supports {tenant, workflow, transition, key}) or new dedicated `workflow_step_overlay` table? The step-scoped overrides need a 5th dimension (step_index). Recommendation: extend `lifecycle_config` to encode step as part of `workflow_name` field (e.g., `gdpr_data_breach.step.2`). Avoids new table.

4. **Migration deadline for removing legacy `WorkflowService`** — keep as facade indefinitely (zero migration debt for callers) or aggressive 2-release deprecation? Recommendation: facade indefinitely. 14 caller-files would break otherwise, churn-cost outweighs simplification.

5. **`WorkflowStep` entity reads after Y.2** — once YAML is canonical, do we sync step rows back to DB so legacy WI rows can still display step-name on /workflow/instance/{id}? Recommendation: YES — small `WorkflowYamlSyncCommand` (one-shot in Y.4) replicates YAML steps into DB on tenant-create + nightly cron. Read-only mirror.

6. **`time_based` auto-progression rule type** — currently only documented in `ProcessTimedWorkflowsCommand`, never implemented in WAPS. Y.1 adds it; should it match WAPS docs (e.g., "24 hours after step entry") or be replaced with a more capable cron-expression? Recommendation: match docs (delay_hours int) for now; cron-syntax is future-work.

## Risks

- **Production data integrity** — `workflow_instances` rows must survive Y.0 migration. Mitigation: `lock_version` added with `DEFAULT 0`; existing status values are already valid Symfony WF places (verified: pending/in_progress/approved/rejected/cancelled match exactly).
- **Workflow-Builder UI is high-touch admin feature** — ROLE_ADMIN users (compliance managers, ISBs) rely on it heavily. UX regression risk during Y.3. Mitigation: ship parallel routes for 1 release, run both old and new UI side-by-side, A/B feedback from ISB persona.
- **Event-listener ordering** — `FieldCompletionAutoTransition` (postUpdate) vs `WorkflowAutoProgressionBridge` (workflow.completed) vs `AuditLogListener` (workflow.entered) must not race. Mitigation: tagged Doctrine listener priorities + Symfony event-priority constants in service config.
- **AlvaHint stale "stuck workflow" hints during migration** — between Y.0 deploy and Y.1 deploy, AlvaHint may flag instances that just got their first WF event. Mitigation: ship Y.0 with `AlvaHintInvalidator` hooked to `workflow.workflow_instance_lifecycle.completed` so first transition clears cache.
- **5+10 regulatory workflows YAML errors** — each YAML has 4-6 steps × 4-7 metadata fields. Risk of typos. Mitigation: extend `lint:workflow` CI gate (Y.2) to validate every step's entity-class, role-string, field-names against actual Doctrine entities.
- **Cross-tenant config bleed** — `lifecycle_config` queries must be tenant-scoped. Mitigation: existing `LifecycleConfigRepository` already tenant-scoped (verified in Sprint X.0 spec). Y.3 controller uses `TenantContext::getCurrentTenant()`.
- **Loop-back transitions** in CAPA / Change-Request / Supplier-Assessment / Control-Verification (5 workflows) — modeling as self-loop with `current_step_index--` is non-standard. Risk of off-by-one bugs. Mitigation: dedicated `WorkflowLoopBackTest` with 4 scenarios per workflow.

## Out of Scope

- New regulatory workflows beyond the existing 15
- 4-eyes-principle behavior changes (already in lifecycle YAML metadata, X.4 wires the guard)
- Notification-rule changes (use existing `EmailNotificationService` infrastructure)
- REST API endpoint changes for workflow operations (covered by Sprint X.4 generic Lifecycle API)
- User-guide rewrite (Sprint X.5)
- PHPStan "no-direct-setStatus" rule rollout (Sprint X.5)
- Migration of the 19 other entities to Symfony Workflow (Sprints X.1–X.2)
- Removing `WorkflowController` legacy routes for the inbox (`/workflow/`, `/pending`, etc.) — they continue to work

### Critical Files for Implementation

- `/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/src/Service/WorkflowService.php` (facade refactor — Y.0)
- `/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/src/Service/WorkflowAutoProgressionService.php` (logic source for Y.1 listener extension)
- `/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/src/Lifecycle/EventListener/FieldCompletionAutoTransition.php` (target for Y.1 merge)
- `/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/src/Command/GenerateRegulatoryWorkflowsCommand.php` (source for 15 YAML conversions — Y.2)
- `/Users/michaelbanda/Nextcloud/www/Little-ISMS-Helper/config/packages/lifecycle.yaml` (rule-config extension point — Y.1)

---

## Report (under 350 words)

**File path requested:** `docs/superpowers/plans/2026-05-17-workflow-system-unification.md` — I am in read-only planning mode and cannot create the file. Plan content is delivered in this response for an editor-capable agent to save.

**Sprint outline:**

- **Sprint Y.0 (3d) — `WorkflowInstance` becomes Symfony Workflow state-machine.** Ships `config/workflows/workflow_instance.yaml`, adds `lock_version` + `current_step_index` columns, refactors `WorkflowService` internals to delegate to `LifecycleService.transition()`. Public API unchanged — all 14 caller files keep working. Audit-log + AlvaHint + tenant-guard coverage gained for free.

- **Sprint Y.1 (2d) — Fold `WorkflowAutoProgressionService` into `FieldCompletionAutoTransition`.** Ports AND/OR DSL, `risk_appetite` type, SLA-spawn into the existing X.0 listener. Adds approval-chain mode. Removes 14 explicit `checkAndProgressWorkflow` calls from controllers/services. Deprecates the old service.

- **Sprint Y.2 (2d) — 15 regulatory workflows migrate to YAML** under `config/workflows/regulatory/*.yaml` with a `metadata.regulatory_metadata.steps` block. `RegulatoryWorkflowLoader` reads steps at boot. `GenerateRegulatoryWorkflowsCommand` becomes a deprecated backwards-compat seeder.

- **Sprint Y.3 (5d) — Workflow-Builder UI re-purposed as YAML overlay editor.** New `WorkflowOverlayController` under `/admin/workflows`. Drag-drop UI removed (step structure is YAML-only). Overrides written to existing `lifecycle_config` table with extended key shape including step index.

- **Sprint Y.4 (3d) — `Workflow` + `WorkflowStep` deprecation + data preservation.** PHPDoc `@deprecated`, PHPStan rule blocking new instantiations, ADR document, CLAUDE.md update. Zero data deletion — all rows preserved for forensic display.

**Three most-important open questions for user-decision:**

1. **Workflow-Builder UI editing approach** (Q1) — overlay-only via `lifecycle_config` (recommended, 5-day Y.3 estimate) versus filesystem-write to YAML (forced cache-clear, audit-trail challenges, deployment friction).

2. **Per-tenant override storage** (Q3) — extend `lifecycle_config` with composite-keyed step-scoped rows (recommended, no new table), or add a dedicated `workflow_step_overlay` table.

3. **Legacy `WorkflowService` removal deadline** (Q4) — keep as permanent facade (recommended, zero churn for 14 caller-files), or aggressive 2-release deprecation.
