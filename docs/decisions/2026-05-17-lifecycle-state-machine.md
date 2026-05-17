# ADR — Lifecycle State Machine via Symfony Workflow

**Date:** 2026-05-17
**Status:** Accepted
**Sprint:** X.0 (Foundation Pilot) — implemented in PR #398

---

## Context

The codebase arrived at Sprint X.0 with three partly-overlapping status systems that had accumulated over multiple development cycles.

**Layer 1 — Legacy `WorkflowService` / `WorkflowInstance`:** A production multi-step approval-chain system used for regulatory workflows (GDPR Data Breach Art. 33/34, DPIA Art. 35/36, Risk Treatment ISO 27001 Cl. 6.1.3, Incident Response). It manages `WorkflowInstance` + `WorkflowStep` entities, event-driven auto-progression via `WorkflowAutoProgressionService`, and cron-based timed steps. This system is intentionally kept for multi-step approval chains and is *not* replaced by this ADR.

**Layer 2 — `App\Lifecycle\LifecycleService` skeleton:** A thin facade introduced during audit-s3 P-4 planning that held a transition matrix inline. It had no underlying state-machine engine — transitions were executed via direct `$entity->setStatus($target)` calls validated against a hand-coded `$allowedTransitions` map. The matrix was already showing signs of drift (Document had a different matrix than Risk, which differed from Incident).

**Layer 3 — 109 ad-hoc `setStatus()` call-sites:** Controllers and services directly called `$entity->setStatus(...)` without going through any gateway. This bypassed RBAC checks, audit-logging, tenant isolation, and module-gating. The UI bulk-action-bar exposed `status_change` actions whose endpoints duplicated the transition matrix inline, creating a fourth parallel implementation.

**Driving requirements:**
- ISO 27001 Cl. 7.5.3 mandates an audit trail for every status change on documented information.
- GDPR Article 5(2) accountability principle requires that status changes on ProcessingActivities be traceable.
- Multi-tenancy requires that a tenant's admin can override which roles may trigger which transitions, without touching code.
- The growing entity count (20+ entities with a `status` field) makes per-entity custom matrices unsustainable.

---

## Decision

Adopt the **Symfony Workflow component** (`symfony/workflow`, `type: state_machine`) as the canonical state-machine engine for all entity status transitions.

`LifecycleService` becomes a facade that delegates to `Workflow\Registry::get()->apply()`. Its public signature (`transition($entity, $workflowName, $transitionName, $user, $reason)`) is unchanged — call-sites that already go through the facade need no modification.

`WorkflowService` and `WorkflowInstance` remain untouched. They continue to serve multi-step approval chains. The two systems are complementary: Symfony Workflow handles "what status is this entity in", WorkflowService handles "what approval steps does a regulatory process require".

**Two-layer configuration** governs every workflow:

1. **Static YAML baseline** — one file per entity under `config/workflows/<entity>.yaml`. Places, transitions, and from/to wiring live here permanently. No runtime agent can add new statuses or rewire transitions (doing so would require entity-code changes anyway).

2. **Tenant DB-overlay** — the `lifecycle_config` table holds per-tenant, per-transition overrides for metadata fields (`roles`, `reason_required`, `four_eyes`, `module`). A new `LifecycleConfigResolver` service merges YAML metadata with DB rows at resolution time. All Voters, Guards, and Listeners read exclusively via the Resolver — never from YAML directly.

The pilot entity is `Document`. All other entities are migrated in subsequent sprints (X.1: 4 standard-5-stage entities; X.2: 10 custom-stage entities).

---

## Consequences

### Positive

- **Single canonical engine.** Symfony Workflow is battle-tested, ships with Guard events, Transition events, Completed events, a Profiler panel, `workflow:dump` visualization, and Twig helper functions. No custom state-machine logic to maintain.
- **Native event bus.** `AuditLogListener`, `AlvaHintInvalidator`, `ReasonValidator`, and future listeners (`FourEyesGuard`, `NotificationFanOut`, `WorkflowAutoProgressionBridge`) all subscribe to standard Symfony events — loosely coupled, independently testable.
- **ISO 27001 Cl. 7.5.3 evidence.** `AuditLogListener` writes an `audit_log` row with `action='status_change'`, `transition_name`, `from`, `to`, `reason`, `user_id`, and AUD-02 integrity-signature for every completed transition. No transition can be completed without the listener firing.
- **Tenant-isolated overrides.** Admins configure per-transition metadata via `/admin/lifecycle-overrides` without touching YAML or deploying code. Overrides are tenant-scoped and never bleed across tenants.
- **Optimistic locking.** Doctrine `#[ORM\Version]` on lifecycle-managed entities prevents silent concurrent overwrites. Version mismatch → HTTP 409 with a retry-suggestion.
- **Progressive migration path.** The facade pattern means existing ad-hoc `setStatus()` call-sites can be migrated entity-by-entity without a big-bang refactor. PHPStan enforcement is deferred to X.5 to allow the sweep to complete.

### Negative

- **Migration of 100+ `setStatus()` call-sites** is required for full coverage. X.2 progress is ongoing; X.5 cleanup completes the sweep. Until then, a call-site that bypasses the facade will not produce an audit-log entry for that transition.
- **`@Version` columns** must be rolled across 14+ entities. Each requires a migration with `isTransactional()=false` (per CLAUDE.md pitfall #6). Foundation adds it to `Document` only; subsequent sprints add to each entity at migration time.
- **PHP enum compatibility risk.** `IncidentStatus` / `RiskStatus` use `BackedEnum`. Symfony Workflow marking-store `method` supports `BackedEnum` since Symfony 6.0, but practical verification is required when X.2 migrates those entities (Document uses VARCHAR, so the risk is deferred).

---

## Alternatives Considered

| Option | Pros | Cons | Verdict |
|---|---|---|---|
| Keep custom `LifecycleService` (hand-coded matrix) | Already built, no new dependency | Reinventing a state-machine wheel; no Guard events; no profiler; transition matrix drift across entities | REJECTED |
| Symfony Workflow component | Battle-tested, native events, profiler, Twig helpers, `workflow:dump`, BackedEnum support | Requires YAML config layer, new DB-overlay table | ADOPTED |
| Third-party FSM library (e.g. `winzou/state-machine`) | Mature, well-documented | Additional Composer dependency, less Symfony-native, no Profiler integration | REJECTED |
| Per-entity custom Voters (no central engine) | Fine-grained control | Multiplies code by entity count (20+), no event bus, audit-log wiring per-entity | REJECTED |

---

## Implementation Roadmap

| Sprint | Scope | PR | Status |
|---|---|---|---|
| X.0 | Foundation: Symfony Workflow + Document pilot, `LifecycleService` facade refactor, `lifecycle_config` table, `LifecycleConfigResolver`, `AuditLogListener`, guards | #398 | ✅ Done |
| X.1 | 4 standard-5-stage entities: PolicyTemplate, Asset, ProcessingActivity, ISMSObjective | #402 | ✅ Done |
| X.3 | UI layer: show-page transition dropdown, status-pill, history-tab, bulk-bar generalization, `LifecycleChoiceType` | #405 | ✅ Done |
| Admin-UI overrides | `/admin/lifecycle-overrides` page for tenant config | #403 | ✅ Done |
| Asset blocker | Custom 7-stage physical-lifecycle state-machine | #409 | ✅ Done |
| X.2 | 10 custom-stage entities: DataBreach, Incident, Risk, DPIA, CorrectiveAction, AuditFinding, InternalAudit, Vulnerability, DataSubjectRequest, Consent | — | 🚧 In flight |
| X.4 | Automation: field-completion listeners, cron, cascade, AlvaHint rules, REST API, 4-Eyes, Notification, Acknowledgement, Webhook, WorkflowAutoProgressionBridge | — | 🚧 In flight |
| X.5 | Cleanup: backfill audit entries, PHPStan rule (no-direct-setStatus), test migration, this ADR, user-guide | — | 📋 This PR (docs portion only) |

Foundation + X.1–X.5 total estimated effort: ~33 dev-days.

---

## References

- Spec: `docs/superpowers/specs/2026-05-17-lifecycle-foundation-pilot-design.md`
- Plan: `docs/superpowers/plans/2026-05-17-lifecycle-foundation-pilot.md`
- User-guide: `docs/user-guide/STATUS_LIFECYCLE.md`
- CLAUDE.md: "Lifecycle (Symfony Workflow Foundation P-4b)" section
- Symfony Workflow docs: https://symfony.com/doc/current/workflow.html
