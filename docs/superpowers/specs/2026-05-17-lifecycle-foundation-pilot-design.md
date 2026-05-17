# Lifecycle Foundation Pilot — Symfony Workflow Adoption

**Date:** 2026-05-17
**Scope:** Sprint X.0 — Foundation only. Post-pilot sprints (X.1–X.5) get their own specs.
**Pilot entity:** `Document`
**Estimated effort:** 5 dev-days

## Executive Summary

The codebase has three partly-overlapping status systems: an unused `App\Lifecycle\LifecycleService`, a production `WorkflowService` for multi-step approval chains, and 109 ad-hoc `setStatus()` call-sites across controllers and services. The UI bulk-action-bar exposes `status_change` actions whose endpoints duplicate the transition matrix inline. This spec defines the foundation pilot that unifies all status transitions through a single gateway, using the Symfony Workflow component as the underlying state-machine engine.

**Decision priority lens:** Symfony built-in > existing tool > new code. Symfony Workflow component fits 100% of the use-case; remaining infrastructure (5 listeners, 2 guards, generic controller) is the minimum new code required to integrate with audit-log, multi-tenancy, and module-gating.

**Pilot strategy:** End-to-end lifecycle on `Document` only. Subsequent sprints copy the pattern to other entities. The pilot proves the architecture without mass-migration risk.

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│ UI — existing _bulk_action_bar.html.twig (status_change action) │
└────────────────────┬────────────────────────────────────────────┘
                     │ POST /lifecycle/document/{id}/transition
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│ LifecycleController (NEW, generic)                              │
│  CSRF, version-check → 409, reason-validation → 422             │
└────────────────────┬────────────────────────────────────────────┘
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│ LifecycleService (REFACTOR — facade)                            │
│  ::transition($entity, $target, $user, $reason)                 │
│  internally → Symfony Workflow Registry::get()->apply()         │
└────────────────────┬────────────────────────────────────────────┘
                     ▼
┌─────────────────────────────────────────────────────────────────┐
│ Symfony Workflow (NEW: symfony/workflow)                        │
│ config/workflows/document.yaml (state_machine)                  │
│ marking_store: method (uses Document::getStatus/setStatus)      │
└──┬──────────────┬──────────────┬────────────────────────────────┘
   │ GuardEvent   │ TransitionEv │ CompletedEvent
   ▼              ▼              ▼
[TenantGuard]   [LifecycleVoter] [AuditLogListener]
[ModuleGate-      │              [AlvaHintInvalidator]
   Guard]         │              [ReasonValidator]
[ReasonValid.]    │              (+ deferred listeners X.4)
   │              │
   └──────┬───────┘
          ▼
┌─────────────────────────────────────────────────────────────────┐
│ LifecycleConfigResolver (NEW)                                   │
│  Merges YAML metadata + DB-overlay per-tenant                   │
│  Single source for Voter / Guards / Listener config reads       │
└─────────────────────┬───────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────┐
│ lifecycle_config table (NEW)                                    │
│  tenant_id + workflow_name + transition_name + key → value      │
│  Empty by default — YAML is the baseline; rows override         │
│  Admin-UI for editing rows: deferred to X.3 / X.0b              │
└─────────────────────────────────────────────────────────────────┘
```

## Decisions

1. **State engine:** Symfony Workflow component (`type: state_machine`). Battle-tested, native Guard/Transition/Completed events, `workflow:dump` visualization, Twig helpers, Profiler panel. No custom state-machine code.
2. **Config source:** YAML-split — one file per entity under `config/workflows/<entity>.yaml`. Symfony 7.4 supports config-import natively. Pilot ships `document.yaml` only.
3. **Voter pattern:** Single `LifecycleVoter` that reads role-matrix from workflow-config `metadata` blocks. Existing per-entity voters (`DocumentVoter`, `RiskVoter`, `IncidentVoter`) remain for non-lifecycle permissions.
4. **WorkflowInstance lifecycle:** Itself becomes a state-machine in subsequent sprints. Foundation pilot does not touch WorkflowInstance.
5. **Status-history storage:** Reuse existing `audit_log` table. New listener writes `action='status_change'` with `old_values={status}` + `new_values={status, reason, transition_name}`. AUD-02 integrity-signature, tenant_id, ISO 27001 Cl. 7.5.3 already covered.
6. **Optimistic locking:** Doctrine `#[ORM\Version]` on each lifecycle-managed entity. Foundation adds it to `Document` only; full rollout in X.1.
7. **Admin-konfigurierbar (two-layer config):** Static YAML is canonical baseline. New `lifecycle_config` DB-table holds tenant-scoped overrides for metadata fields (`roles`, `reason_required`, `four_eyes`, `module`). New `LifecycleConfigResolver` service merges YAML + DB-overlay; Voter / Guards / Listeners read via Resolver, never YAML directly. **Places and transitions structure stay in YAML only** — admin cannot add new statuses or change from/to wiring at runtime (would require entity-code changes). Admin-UI deferred to its own spec; foundation ships mechanism + table only.
8. **Scope:** Pilot on `Document` end-to-end. Other 19 entities deferred to X.1–X.2.

## Workflow YAML Convention

`config/workflows/document.yaml`:

```yaml
framework:
  workflows:
    document_lifecycle:
      type: state_machine
      marking_store:
        type: method
        property: status
      supports:
        - App\Entity\Document
      initial_marking: draft
      places:
        - draft
        - in_review
        - approved
        - published
        - archived
      transitions:
        submit_for_review:
          from: draft
          to: in_review
          metadata:
            roles: [ROLE_USER, ROLE_MANAGER]
            tone_target: info
        approve:
          from: in_review
          to: approved
          metadata:
            roles: [ROLE_MANAGER]
            reason_required: false
        request_changes:
          from: in_review
          to: draft
          metadata:
            roles: [ROLE_MANAGER]
            reason_required: true
        publish:
          from: approved
          to: published
          metadata:
            roles: [ROLE_MANAGER]
            four_eyes: true
            module: documents
        archive:
          from: published
          to: archived
          metadata:
            roles: [ROLE_MANAGER]
            reason_required: true
        restore:
          from: archived
          to: published
          metadata:
            roles: [ROLE_MANAGER]
            reason_required: true
```

**Metadata schema (foundation-defined, used by future sprints unchanged):**

| Key | Type | Admin-overrideable | Purpose |
|---|---|---|---|
| `roles` | `string[]` | yes | `LifecycleVoter` allows transition if user holds any listed role |
| `reason_required` | `bool` | yes | `ReasonValidator` listener rejects with 422 if reason empty |
| `four_eyes` | `bool` | yes | `FourEyesGuard` requires existing approved `FourEyesApprovalRequest` (guard wired in X.4, metadata-key reserved now) |
| `module` | `string` | yes | `ModuleGateGuard` rejects if module-key disabled for tenant |
| `tone_target` | `string` | no | Aurora pill variant rendered after transition; consumed by UI (X.3) |

**Admin-overrideable** fields can be overridden per-tenant via `lifecycle_config` table (see `LifecycleConfigResolver` below). Voter/Guards/Listener never read YAML directly — always via Resolver.

## HTTP Contract

Routes (NOT locale-prefixed):

```
POST /lifecycle/{entityType}/{id}/transition
POST /lifecycle/{entityType}/bulk-transition
GET  /lifecycle/{entityType}/{id}/allowed-transitions
```

**Single-transition request:**
```json
POST /lifecycle/document/42/transition
{
  "transition": "approve",
  "reason": "QMS review passed",
  "lock_version": 7
}
```

**Success 200:**
```json
{
  "status": "approved",
  "lock_version": 8,
  "allowed_next": ["publish"],
  "audit_log_id": 12345
}
```

**Bulk-transition request:**
```json
POST /lifecycle/document/bulk-transition
{
  "transition": "approve",
  "ids": [1, 2, 3],
  "reason": "Batch approval — quarterly review cycle"
}
```

**Bulk response 200:**
```json
{
  "succeeded": [1, 3],
  "failed": { "2": "version_conflict" },
  "audit_log_batch_id": "uuid-v4"
}
```

**Allowed-transitions GET response 200:**
```json
{
  "current_status": "in_review",
  "lock_version": 7,
  "allowed_transitions": [
    {"name": "approve", "to": "approved", "reason_required": false},
    {"name": "request_changes", "to": "draft", "reason_required": true}
  ]
}
```

CSRF token via header `X-CSRF-Token` or form `_token`. Symfony default.

## Error Handling

| Failure | Source | HTTP | Behavior |
|---|---|---|---|
| Entity not found | Param resolver | 404 | Generic message |
| Unknown entityType | Controller lookup | 404 | "Lifecycle für Typ X nicht konfiguriert" |
| CSRF invalid | Symfony built-in | 403 | "CSRF-Token ungültig" |
| Voter denied (role) | `LifecycleVoter` | 403 | "Berechtigung fehlt für Transition X" |
| Tenant cross-leak | `TenantGuard` | 403 (not 404) | "Berechtigung fehlt" — never reveal entity existence |
| Module disabled | `ModuleGateGuard` | 422 | "Modul '{key}' nicht aktiviert" |
| Reason missing | `ReasonValidator` listener | 422 | "Begründung erforderlich für Transition X" |
| Version mismatch | Doctrine `OptimisticLockException` | 409 | "Entity wurde geändert — neu laden" |
| Transition not applicable | Symfony `NotEnabledTransitionException` | 422 | "Transition X nicht möglich aus Status Y" |
| Audit-log write fails | `AuditLogListener` | (silent skip) | Status-change succeeds; audit-row missed |
| EM closed mid-flush | LifecycleService catch | 500 + ManagerRegistry reset | Operator sees retry-suggestion |

**Rules:**
- Voter denial → 403 always (never reveal entity existence)
- Bulk-transition: best-effort, failed list returned with reasons
- All errors return JSON `{ "error": "code", "message": "...", "details": {...} }`
- LifecycleService catches Symfony's transition-exceptions, translates to domain-exceptions (existing `InvalidTransitionException`)
- AuditLogListener wraps persist in try/catch (matches existing `AuditLogger::persistAndFlush` defensive pattern)

## Components

### New Files

| File | Purpose |
|---|---|
| `composer.json` (require) | `symfony/workflow` |
| `config/workflows/document.yaml` | State-machine config (schema above) |
| `src/Controller/LifecycleController.php` | 3 routes (single, bulk, allowed-transitions) |
| `src/Security/Voter/LifecycleVoter.php` | Reads roles via `LifecycleConfigResolver` |
| `src/Lifecycle/Config/LifecycleConfigResolver.php` | Merges YAML metadata + DB-overlay per-tenant; single source for Voter/Guards/Listener config reads |
| `src/Entity/LifecycleConfig.php` | Doctrine entity for `lifecycle_config` table (tenant-scoped overrides) |
| `src/Repository/LifecycleConfigRepository.php` | Tenant-scoped query for override-rows |
| `src/Lifecycle/Guard/TenantGuard.php` | Rejects cross-tenant transition |
| `src/Lifecycle/Guard/ModuleGateGuard.php` | Rejects transition if `module` (via Resolver) disabled for tenant |
| `src/Lifecycle/EventListener/AuditLogListener.php` | Subscribes `workflow.<name>.completed` → audit_log row |
| `src/Lifecycle/EventListener/AlvaHintInvalidator.php` | Clears stuck-in-status hints on transition |
| `src/Lifecycle/EventListener/ReasonValidator.php` | Pre-apply validation: `reason_required` via Resolver |
| `migrations/Version20260518XXXXXX_AddLockVersionToDocument.php` | `lock_version INT NOT NULL DEFAULT 0` on `documents` |
| `migrations/Version20260518XXXXXY_CreateLifecycleConfig.php` | `lifecycle_config` table: id, tenant_id, workflow_name, transition_name, key, value (JSON), updated_at, updated_by_user_id; UNIQUE (tenant_id, workflow_name, transition_name, key) |

### Refactored Files

| File | Change |
|---|---|
| `src/Lifecycle/LifecycleService.php` | Inject `Workflow\Registry`; delegate `transition()` internals to `$workflow->apply()`. Public signature unchanged. |
| `src/Lifecycle/LifecycleRegistry.php` | Strip transition matrix; keep only `tone`/`label` metadata for UI helpers |
| `src/Entity/Document.php` | Add `#[ORM\Version] private int $lockVersion = 0;` + getter |
| `src/Controller/DocumentController.php` | `bulkStatusChange` calls `LifecycleService::transition()` instead of inline matrix |

### Untouched (deferred to later sprints)

- Other 13 ad-hoc setStatus call-sites
- Other 19 entities (Risk, Incident, DataBreach, etc.)
- `templates/_components/_bulk_action_bar.html.twig` (stays compatible with refactored DocumentController route)
- Status-history UI tab (X.3)
- 4-Eyes / Field-completion / Notification listeners (X.4)
- `WorkflowAutoProgressionService` (X.4 bridge work)

## Testing Strategy

### Unit Tests
- `LifecycleServiceTest` (existing 4 files extended): facade delegates to `Workflow\Registry` mock; public API unchanged
- `LifecycleConfigResolverTest`: YAML-only returns YAML value; DB-overlay overrides per tenant; missing key returns default
- `LifecycleVoterTest`: voter reads roles via Resolver, votes correctly for ROLE_USER/MANAGER/AUDITOR × each transition, respects tenant-override
- `TenantGuardTest`: rejects transition when `entity.tenant != current-user.tenant`
- `ModuleGateGuardTest`: rejects transition when `module` key disabled in tenant; honors Resolver override
- `AuditLogListenerTest`: subscribes to `workflow.document_lifecycle.completed`, writes audit_log row with `action='status_change'`
- `ReasonValidatorTest`: rejects transition with `reason_required=true` (YAML or DB-override) when reason empty
- `AlvaHintInvalidatorTest`: invalidates stuck-in-status hints on transition

### Integration Tests (WebTestCase)
- `LifecycleControllerTest::testSingleTransitionSuccess`: POST endpoint returns 200, status persists, audit_log written
- `LifecycleControllerTest::testVersionConflictReturns409`: stale `lock_version` → 409
- `LifecycleControllerTest::testInvalidTransitionReturns422`: `archive` from `draft` → 422
- `LifecycleControllerTest::testVoterDeniedReturns403`: non-MANAGER attempts `publish` → 403
- `LifecycleControllerTest::testBulkBestEffort`: 3 IDs, 1 stale → succeeded=[2], failed=[1 with reason]

### System Test
- `WorkflowDumpTest`: `bin/console workflow:dump document_lifecycle` produces valid graphviz output → CI-asserted

### CI Gates (new)
- `lint:workflow`: every workflow config loads, every `supports:` entity-class exists, every `marking_store.property` resolves on entity
- PHPStan rule "no-direct-setStatus" — **deferred** to Sprint X.5 (not enforced in foundation)

### Migration Test
- `Version20260518XXXXXX_AddLockVersionToDocumentTest`: up() adds column, down() removes, idempotent
- `Version20260518XXXXXY_CreateLifecycleConfigTest`: up() creates table with unique-key constraint, down() drops idempotently

**Total:** 13 new tests + 4 existing kept green = 17.

## Acceptance Criteria

1. `composer require symfony/workflow` completes; no version conflicts
2. `bin/console workflow:dump document_lifecycle | dot -Tsvg > /tmp/doc.svg` produces 5-place state-machine graph
3. `POST /lifecycle/document/{id}/transition` returns 200 for valid transition, 409/422/403 per error-table
4. `DocumentController::bulkStatusChange` UI unchanged for end-users (same bulk-bar); endpoint internally delegates to `LifecycleService`
5. `audit_log` rows with `action='status_change'` written per transition; contain `transition_name`, `from`, `to`, `reason`, `user_id`
6. Concurrent-edit test (409) passes
7. `php bin/console lint:container` green
8. 15 lifecycle-domain tests pass; existing test suite unaffected
9. `lint:workflow` CI gate added and passing

## Edge Cases (Foundation-relevant)

- **Status field doesn't exist on entity:** caught at startup by Symfony Workflow boot. `lint:workflow` CI gate fails the build.
- **Bulk-transition partial-failure:** best-effort per existing DocumentController pattern; failed list returned in response.
- **EM closed mid-flush:** LifecycleService catches, calls `ManagerRegistry::resetManager()` (existing pattern from `SchemaMaintenanceService` introduced in PR #390).
- **CSRF on JSON API:** Symfony default handles; tests confirm.
- **Tenant cross-leak through URL guessing:** `TenantGuard` rejects with 403, not 404, to prevent existence-disclosure.

## Risks

- **Symfony Workflow + PHP enums:** `IncidentStatus`/`RiskStatus` already use `BackedEnum`. Marking-store `method` supports `BackedEnum` since Symfony 6.0. Foundation pilot is on `Document` which uses VARCHAR, so this risk surfaces only in X.2 — verify-test required at that point.
- **DocumentController.bulkStatusChange refactor regression:** existing tests for that controller must remain green. Add `LifecycleControllerTest::testBulkBestEffort` and run pre-existing Document tests as smoke before merging.
- **Voter ordering:** `LifecycleVoter` must not conflict with existing `DocumentVoter`. Voter strategy `affirmative` (default) means any voter granting access wins. Verify in `LifecycleVoterTest`.
- **`@Version` on live `documents` table:** migration adds column with `DEFAULT 0` for existing rows. No data-migration needed. `isTransactional()=false` per CLAUDE.md pitfall #6.

## Out of Scope (this spec)

- **Admin-UI for editing `lifecycle_config` overrides** — mechanism + DB-table ship in foundation, but no admin form/list-page. Separate spec X.3 (alongside other lifecycle UI) or X.0b.
- Reason-required defaults per entity → X.1/X.2 specs
- 4-Eyes-Guard wiring → X.4 spec
- AlvaHint threshold-rules (StuckInStatus, MissingFields, GuardBlocked) → X.4 spec
- REST API endpoints (`/api/...`) → X.4 spec
- Notification fan-out, Acknowledgement spawning, Webhook fires → X.4 spec
- Status-history UI tab → X.3 spec
- WorkflowAutoProgressionService bridge → X.4 spec
- Migration of other 19 entities → X.1/X.2 specs
- PHPStan rule enforcement → X.5 spec
- User-guide documentation → X.5 spec

## Post-Pilot Roadmap (context only — not part of this spec)

| Sprint | Scope | Effort |
|---|---|---|
| X.1 | Std-5 entities: PolicyTemplate, Asset, ProcessingActivity, ISMSObjective | 5d |
| X.2 | Custom-stage entities: DataBreach, Incident, Risk, DPIA, CorrectiveAction, AuditFinding, InternalAudit, Vulnerability, DataSubjectRequest, Consent | 7d |
| X.3 | UI: show-page dropdown, status-pill, history-tab, bulk-bar generalization, LifecycleChoiceType | 6d |
| X.4 | Automation: field-completion, cron, cascade, AlvaHint, REST API, 4-Eyes, Notification, Acknowledgement, Webhook, WorkflowAutoProgressionBridge | 7d |
| X.5 | Cleanup: backfill, PHPStan rule, test migration, ADR, user-guide | 5d |

Foundation + X.1–X.5 total: ~33 dev-days.
