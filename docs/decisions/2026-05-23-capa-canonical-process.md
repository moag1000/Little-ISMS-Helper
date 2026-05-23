# ADR — CAPA-Canonical-Process Consolidation

**Date:** 2026-05-23
**Status:** Proposed
**Sprint:** S14 (Proposed — implementation deferred to a separate PR)
**Audit reference:** Junior-ISB-Audit 2026-05-22, finding M-07
**Norms:** ISO 27001:2022 Cl. 10.1 (Continual improvement / nonconformity & corrective action), Cl. 10.2 (Improvement), Cl. 7.5.3 (Control of documented information / audit trail)

---

## Context

The codebase has accumulated **three parallel models** for what ISO 27001 calls a "corrective and preventive action" (CAPA). Each was introduced for a single regulatory slice, but together they no longer present a coherent closure-loop. The Junior-ISB-Audit summarised the problem in one sentence:

> "Welche ist DER CAPA-Prozess?" — Junior-ISB-Audit 2026-05-22, finding M-07

### Current state — three CAPA surfaces

| Layer | Entity / Field | LOC | Origin | What it models |
|---|---|---:|---|---|
| 1 | `App\Entity\CorrectiveAction` (`corrective_actions` table) | 438 | H-01 ISO-27001 readiness sweep | A structured CAPA record bound to an `AuditFinding`. Has `actionType` (corrective/preventive/improvement), `status` (planned → in_progress → completed → verified_effective/ineffective), `rootCauseAnalysis`, planned/actual completion dates, responsible Person + deputies, effectiveness review fields. |
| 2 | `App\Entity\ChangeRequest` (`change_request` table) | 863 | ISMS Change-Management module | A formal change-control record (`changeType`, `priority`, `status`, `plannedImplementationDate`, approval workflow). Often **created as the operational response to a CAPA**, but with no formal link back to the originating `CorrectiveAction`. |
| 3 | `Incident.rootCause` + `Incident.correctiveActions` + `Incident.preventiveActions` (freetext TEXT columns) | — | Incident-Response module | Three free-form TEXT columns on `Incident` (`Incident.php:171`, `:175`, `:179`). Captures the analyst's narrative during incident closure. **No traceability** to structured CAPA records, no due-date, no responsible person, no effectiveness review. |

### Existing flow that already works

`AutoReactionCorrectiveActionListener` (in `src/EventListener/`) auto-creates a `CorrectiveAction` skeleton whenever an `AuditFinding` with severity `high`/`critical` or type `major_nc` is persisted/updated. This is the **canonical pattern** ISO 27001 Cl. 10.1 demands: the finding becomes a structured action with a due date and a responsible owner.

### What is broken

1. **No closure-loop traceability for Incident-derived CAPAs.** An auditor asking "show me the corrective actions for INC-042" sees a freetext blob in `Incident.correctiveActions` — no due date, no owner, no effectiveness review, no audit trail per Cl. 7.5.3.
2. **`ChangeRequest` is implicitly a CAPA-execution-vehicle.** ISB users routinely open a CR because of a finding/incident, but the CR has no FK pointing back to the originating `CorrectiveAction`. The compliance trail is broken at the moment the change is filed.
3. **Three places to look** for "what are we doing about this finding/incident?" An external auditor must triangulate `AuditFinding.correctiveActions[]`, `Incident.correctiveActions` freetext, and `ChangeRequest.changeNumber` references in commit messages.
4. **ISO 27001 Cl. 10.1 demands a unified closure-loop** with documented root-cause, action, effectiveness verification — a single entity per closure with a complete audit trail.

---

## Decision

**`App\Entity\CorrectiveAction` becomes the single canonical CAPA entity.** All structured CAPA tracking — regardless of whether the trigger is an audit finding, an incident, a management review, or a manual entry — flows through `CorrectiveAction`.

The two surrounding surfaces are realigned:

### 1. `ChangeRequest` becomes a related entity (sibling, not subtype)

A `ChangeRequest` gains an **optional** `relatedCorrectiveAction` FK (`change_request.related_corrective_action_id INT NULL`). Semantics:

- **CR linked to a CA** → the CR is the operational execution vehicle for the CA. The CA's "completed" transition can be gated on the CR reaching status `implemented`.
- **CR with no CA link** → the CR is a standalone operational change (e.g. routine config rollout, planned upgrade). This must remain valid; not every change is a corrective action.

`ChangeRequest` does **not** become a subtype of `CorrectiveAction`. The two entities have legitimately different attributes (CR has approval-board metadata; CA has effectiveness-review metadata) and a CR with no CA link is a real business case. Sibling-with-FK is the lowest-churn, highest-traceability option.

### 2. `Incident.correctiveActions` / `.preventiveActions` freetext → auto-created `CorrectiveAction` rows

Introduce `App\EventListener\AutoReactionCorrectiveActionListenerForIncident` — a Doctrine `postPersist` + `postUpdate` listener on `Incident` that mirrors the existing `AutoReactionCorrectiveActionListener` (for `AuditFinding`).

**Trigger condition:**
- `Incident.severity >= high` **AND**
- `Incident.rootCause` is non-empty (root-cause is the prerequisite for a structured corrective action — without it the analyst has only an immediate-action note, which stays in `Incident.immediateActions`)

**Behaviour:**
- Create a `CorrectiveAction` skeleton with `actionType = corrective`, `status = planned`, planned completion = +30 days (configurable via existing `auto_reactions.auto_ca_due_days` `SystemSettings` key — already in production for the AuditFinding flow).
- Pre-populate `CorrectiveAction.rootCauseAnalysis` from `Incident.rootCause`.
- Pre-populate `CorrectiveAction.description` from `Incident.correctiveActions` freetext.
- Set `CorrectiveAction.sourceType = incident` (new enum column — see schema additions below).
- Link via a new `CorrectiveAction.sourceIncident` FK (nullable, alongside the existing `finding` FK).

The freetext columns on `Incident` are **retained** as the UX entry point — the analyst keeps typing into the existing field, the listener materialises the structured record. This mirrors the AuditFinding flow that has been ergonomically validated in production since H-01.

### 3. `AuditFinding` → `CorrectiveAction` flow stays as-is

Already canonical (see `src/EventListener/AutoReactionCorrectiveActionListener.php`). No changes.

### Summary — the future CAPA surface

```
AuditFinding ──┐
               ├──► CorrectiveAction (canonical)  ◄── ChangeRequest (optional FK, sibling)
Incident   ────┘         │
(severity >= high        │
 + rootCause set)        ▼
                  Effectiveness review
                  (Cl. 10.1 closure-loop)
```

---

## Consequences

### Positive

- **Single source of truth for the ISO 27001 Cl. 10.1 closure-loop.** Every corrective action — whether triggered by audit finding, incident, or filed manually — lives in one queryable table with a complete audit trail (`tenant_id`, `lockVersion`, `status` lifecycle, responsible person + deputy chain).
- **External auditor experience improves dramatically.** Question "show me your open corrective actions" becomes a single repository query, not a triangulation across three tables and one freetext field.
- **Listener-pattern preserves UX.** Analysts keep typing into `Incident.rootCause` + `Incident.correctiveActions` — no UI churn, no training cost. The structured record is materialised on the side.
- **Effectiveness verification (Cl. 10.1 d/e) becomes universal.** Today only `AuditFinding`-derived CAs go through `verified_effective` / `verified_ineffective` review. Post-migration every Incident-derived CA does too.
- **ChangeRequest traceability.** A CR opened because of a CAPA carries a FK pointing back to its origin — the audit trail no longer breaks at "we filed CR-042 about this finding".
- **No schema collapse, no entity merge.** `CorrectiveAction` and `ChangeRequest` keep their domain-specific fields (effectiveness review vs. approval board). The relationship is the only structural change.

### Negative

- **Data migration required for existing tenants.** Existing `Incident.correctiveActions` freetext rows must be audited per tenant and the non-empty ones materialised as `CorrectiveAction` rows. This is **per-tenant, dry-run-first**, see migration plan below.
- **One new listener + two FK columns.** `AutoReactionCorrectiveActionListenerForIncident` (~150 LOC, mirrors the existing finding-listener), plus `ChangeRequest.related_corrective_action_id` and `CorrectiveAction.source_type` + `CorrectiveAction.source_incident_id` schema additions. All additive — no breaking schema changes.
- **`CorrectiveAction.finding` becomes nullable.** Currently `nullable: false` (`CorrectiveAction.php:48`). It must become nullable to support `sourceType = incident` / `sourceType = manual` / `sourceType = change_request` rows. Migration with `isTransactional()=false` (per CLAUDE.md pitfall #6).
- **Incident-form UX hint required.** A small Alva-Hint on the Incident show-page after the listener fires ("A structured corrective action has been created — view it under /admin/corrective-actions/X") so users know the freetext was elevated. Pattern already in use (`project_alva_hint_foundation` memory entry).
- **Reporting queries must be updated.** Any report or dashboard that today reads `Incident.correctiveActions` freetext should be migrated to read the structured `CorrectiveAction` rows linked by `sourceIncident`. The freetext column is retained as UX-entry-point, but **canonical reporting is via `CorrectiveAction`**.

---

## Alternatives Considered

| Option | Pros | Cons | Verdict |
|---|---|---|---|
| **Keep all three** as-is | Zero migration cost | ISO 10.1 closure-loop unverifiable; external auditor frustration; three places to look for "open CAPAs"; no traceability between CR and originating CA | **REJECTED** |
| **Merge `CorrectiveAction` into `Incident`** | Fewer tables | CRs and audit-findings would have no home; Cl. 10.1 applies far beyond incident-management; conceptual collapse | **REJECTED** |
| **Single new entity `CapaItem`** | Clean slate, no legacy | Massive migration churn (all `AuditFinding.correctiveActions[]` relations rewired); existing CA effectiveness-review UI/UX/reports/exports rewired; throws away 438 LOC of working code; existing AuditFinding-listener rewrite | **REJECTED** |
| **`ChangeRequest extends CorrectiveAction`** (table-per-class-hierarchy) | Conceptually clean ("a CR is a kind of CA-execution") | Many CRs are NOT corrective actions (routine config changes, planned upgrades) — STI/CTI would force a fake-parent-CA row for every standalone CR; Doctrine inheritance has well-known performance/complexity pitfalls | **REJECTED** |
| **`CorrectiveAction` canonical, `ChangeRequest` sibling with optional FK, Incident-listener** | Lowest-churn; preserves UX; preserves both entities' domain-specific fields; single canonical reporting surface; listener-pattern already proven for AuditFinding | One data-migration per tenant required; one new listener + 3 schema columns | **ADOPTED** |

---

## Migration Plan (high-level — not in this PR)

This ADR establishes the **decision and direction only**. Implementation is a separate, plannable unit of work — estimated **5 days of senior developer + 1 day senior consultant review = 1 sprint (S14)**.

### Phase 1 — Schema additions (additive, non-breaking)

Two migrations (separate files, each with `isTransactional() = false`):

1. `change_request.related_corrective_action_id INT NULL` + FK + index
2. `corrective_actions.source_type VARCHAR(30) NOT NULL DEFAULT 'audit_finding'` (enum: `audit_finding` | `incident` | `manual` | `change_request`)
3. `corrective_actions.source_incident_id INT NULL` + FK + index
4. `corrective_actions.finding_id` → `nullable: true` (was `nullable: false`)

Per CLAUDE.md pitfall #6 — `isTransactional() = false` manually injected after `doctrine:migrations:diff`.

### Phase 2 — Data backfill (per-tenant, dry-run-first)

Console command `app:migrate-capa --tenant=<tenant-slug> [--dry-run]`:

- Iterate `Incident` rows in the tenant where `severity ∈ {high, critical}` AND `rootCause IS NOT NULL AND rootCause != ''`
- For each, check: does a `CorrectiveAction` with `sourceIncident = this.id` already exist? (idempotent re-run safety)
- If no → create `CorrectiveAction` skeleton (`sourceType = incident`, `sourceIncident = this`, `rootCauseAnalysis = Incident.rootCause`, `description = Incident.correctiveActions ?? ''`, `actionType = corrective`, `status = planned`, planned completion = `incident.detectedAt + 30 days` capped at `now + 30 days`)
- Print per-tenant statistics: scanned, would-create, already-migrated, skipped (no rootCause)
- `--dry-run` flag → prints stats only, no writes
- Default = dry-run; only `--commit` actually writes

The stub command in this PR (`MigrateCapaToCanonicalCommand.php`) prints stats only — full command in S14.

### Phase 3 — Listener activation

Wire `AutoReactionCorrectiveActionListenerForIncident` as `postPersist` + `postUpdate` Doctrine entity listener on `Incident`. Add a feature-flag in `AutoReactionService` (`KEY_CA_ON_INCIDENT`, default off until S14 validation complete).

### Phase 4 — UX additions

- Incident show-page: Alva-Hint after listener fires ("Strukturierte Corrective-Action erstellt — siehe …")
- CorrectiveAction show-page: badge for `sourceType` (audit_finding / incident / manual / change_request)
- ChangeRequest form: optional select for `relatedCorrectiveAction` with autocomplete

### Phase 5 — Reporting & exports

- Update Cl. 10.1 management-review report to count CAs by `sourceType`
- Update ISO 27001 evidence-bundle exports to include `CorrectiveAction` linked via `sourceIncident` (currently includes only finding-linked)

### Out-of-scope for S14

- Migrating the `Incident.correctiveActions` freetext column to a deprecated state. The column is **retained as a UX entry point**. A future ADR may revisit if the listener-pattern proves the freetext field is redundant; not before 2 quarters of production data.

---

## Open Questions for Reviewer

1. **Should `ChangeRequest` be made a Doctrine subtype of `CorrectiveAction` via single-table-inheritance instead of a sibling-with-FK?** — Argued against above (many CRs are standalone), but a Senior-Architect may want to revisit if the CR/CA conceptual overlap proves to dominate the standalone-CR case in production data. Recommendation: stick with sibling-FK until 2 quarters of usage data prove otherwise.

2. **Should `Incident.correctiveActions` and `.preventiveActions` freetext fields be marked `@deprecated` immediately**, or retained indefinitely as UX-entry-points? — Recommendation: retain as UX-entry-points; the listener materialises the structured record. Revisit after 2 quarters of S14 production data.

3. **Effectiveness-review thresholds for Incident-derived CAs.** — AuditFinding-derived CAs require effectiveness review per Cl. 10.1 d/e. Should Incident-derived CAs follow the same rules, or only when `Incident.severity = critical`? Recommendation: same rules (Cl. 10.1 does not distinguish trigger-type), but make threshold configurable per tenant via `lifecycle_config`.

4. **Should standalone `ChangeRequest` rows (no CA link) appear in CAPA-reporting?** — Recommendation: no. CAPA reports are scoped to `CorrectiveAction`. CRs are visible in change-management reports. The FK exists for traceability one-way only.

---

## References

- ISO 27001:2022 Cl. 10.1 — Continual improvement, nonconformity and corrective action
- ISO 27001:2022 Cl. 10.2 — Improvement
- ISO 27001:2022 Cl. 7.5.3 — Control of documented information
- Junior-ISB-Audit 2026-05-22 — finding M-07 in `var/junior-isb-audit/TODO_2026-05-22.md`
- `src/EventListener/AutoReactionCorrectiveActionListener.php` — reference pattern (AuditFinding path, already in production)
- `src/Entity/CorrectiveAction.php` — canonical CAPA entity (438 LOC)
- `src/Entity/ChangeRequest.php` — sibling entity (863 LOC)
- `src/Entity/Incident.php` — freetext fields at lines 171, 175, 179
- `docs/decisions/2026-05-17-lifecycle-state-machine.md` — lifecycle facade pattern this ADR builds on
- `docs/decisions/2026-05-17-workflow-yaml-unification.md` — workflow YAML pattern (note: `capa` is already a canonical workflow slug)

---

## Stubs delivered in this PR

This PR contains the ADR **only**. As placeholders for the S14 implementation, two stub files are also included:

- `src/Command/MigrateCapaToCanonicalCommand.php` — dry-run-only stub that prints scope statistics without writing. Visible reminder for the next sprint.
- `src/Listener/AutoReactionCorrectiveActionListenerForIncident.php` — empty class with TODO doc-block.

Both stubs are intentionally inert. They exist so that S14 implementation can be search-discovered and tracked, and so that a `php bin/console list` lookup surfaces the planned command before it is implemented.
