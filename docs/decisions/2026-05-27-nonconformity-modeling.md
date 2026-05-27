# Nonconformity Modeling: AuditFinding + Type Enum (Accepted)

- **Date:** 2026-05-27
- **Status:** Accepted (no refactor)
- **Scope:** Audit findings, nonconformities, CAPA lifecycle

## Context

ISO 19011 (audit guidelines), ISO 17021 (certification body requirements), ISO 9001
and ISO 27001 distinguish four discovery types during an audit:

- **Observation** — minor remark, no compliance gap
- **OFI (Opportunity for Improvement)** — non-blocking suggestion
- **Minor Nonconformity** — isolated control gap, doesn't undermine the system
- **Major Nonconformity** — systemic gap, blocks certification

Plus a CAPA (Corrective Action / Preventive Action) lifecycle per ISO 27001
Cl. 10.1–10.2: root-cause analysis → containment → corrective action →
effectiveness verification.

The architectural question: should we have a separate `Nonconformity` entity
distinct from `AuditFinding`, or is the current "type-enum on AuditFinding
plus separate CorrectiveAction entity" model sufficient?

## Current Model

`AuditFinding` carries a `type` enum (`TYPE_OBSERVATION`, `TYPE_OPPORTUNITY`,
`TYPE_MINOR_NC`, `TYPE_MAJOR_NC`) plus a 5-state lifecycle
(`open → in_progress → resolved → verified → closed`).

CAPA-specific fields (added 2026-06 via `Version20260614100000`) live on the
same entity but render only when `isNonconformity()` is true:

- `nonconformityDetails` (JSON; `rootCauseAnalysisMethod`,
  `correctiveActions[]`, `verificationMethod` schema)
- `ncRootCauseSummary` (TEXT) — ISO 27001 Cl. 10.2 b
- `ncCorrectionDueDate` (DATE) — ISO 27001 Cl. 10.2 c
- `ncVerifiedAt`, `ncVerifiedBy` — ISO 27001 Cl. 10.1 d effectiveness review

`CorrectiveAction` is a **separate entity** with its own 7-state lifecycle:
`planned → in_progress → completed → verified → verified_effective /
verified_ineffective` (plus `cancelled`). Carries `rootCauseAnalysis`,
`effectivenessReviewDate`, `effectivenessNotes`, `effectivenessEvidence`,
`verifiedBy`, `verifiedAt`, and a `previousCapa` self-FK that traces
ineffective-action chains per ISO 27001 Cl. 10.1 b.

`CorrectiveAction.finding_id` is nullable as of the 2026-06 CAPA-Canonical
refactor — CAPAs can also originate from incidents, change requests, or
manual creation (`sourceType` enum + `sourceIncident` / `sourceChangeRequest`
FKs).

## Decision

**Keep the current hybrid model.** No separate `Nonconformity` entity.

The combination of `AuditFinding.type` + nc-specific fields + the standalone
`CorrectiveAction` entity satisfies the audit-defensibility requirements:

| ISO Clause | Requirement | Current Coverage |
|---|---|---|
| 27001 Cl. 10.1 d | Effectiveness review of correction | `ncVerifiedAt` + `ncVerifiedBy` + `CorrectiveAction.previousCapa` chain |
| 27001 Cl. 10.2 b | Determine root cause | `ncRootCauseSummary` + `nonconformityDetails.rootCauseAnalysisMethod` |
| 27001 Cl. 10.2 c | Implement action with deadline | `ncCorrectionDueDate` + `CorrectiveAction.dueDate` |
| 27001 Cl. 10.2 d | Review effectiveness | `effectivenessReviewDate` + `effectivenessNotes` + `effectivenessEvidence` |
| 19011 Cl. 6.4.8 | Classification of findings | `type` enum with 4 distinct values |
| 17021 Cl. 9.4.8 | Independent verifier | `verifiedBy` (User-FK) + role-gated transitions (`ROLE_AUDITOR`) |

Type-enum gating ensures observations and OFIs never expose nc-only fields,
while major/minor NCs force CAPA entry via form validation.

## What a separate Nonconformity entity would add

- **Strict schema separation** — AuditFinding holds only discovery facts;
  Nonconformity owns CAPA + verification. Currently one entity carries both.
- **N:1 cardinality flexibility** — multiple nonconformities per finding
  (rarely needed in audit practice; the typical pattern is 1 finding = 1 NC).
- **Post-closure immutability** — Nonconformity rows could become read-only
  after closure while AuditFinding history remains mutable for metadata.
- **Simpler report queries** — standalone `nonconformity` table eliminates
  the `WHERE type IN ('major_nc','minor_nc')` filter in audit-report SQL.

## Why we don't refactor now

1. **No auditor feedback demands it.** ISO 27001 audits accept the
   type-enum-plus-CAPA-entity pattern; it's used by most ISMS tools.
2. **CorrectiveAction already provides the heavy CAPA lifecycle** — the
   "split" is effectively already done; the missing piece (separate NC table)
   would only carry verifier metadata and an immutability flag.
3. **Migration cost** is ~3–5 FTE-days plus a breaking change on
   `CorrectiveAction.finding_id` (repoint to `nonconformity_id`), affecting
   any downstream reporting query.
4. **Current tests pass** — `AuditFindingTest` validates the full nc-field
   round-trip and the `isNonconformity()` helper.

## When to revisit

Refactor to a separate `Nonconformity` entity only if any of these triggers:

1. An accredited auditor explicitly flags the hybrid model in a certification
   audit (ISO 27001 / ISO 9001) as insufficient.
2. Post-closure immutability becomes a hard requirement (e.g., regulatory
   reporting demands an immutable nonconformity log).
3. Cross-organization reporting (multi-tenant Holding setup) requires strict
   segregation of audit-discovery facts from remediation history.

Until one of those triggers fires, the current model is the right level of
complexity.

## References

- `src/Entity/AuditFinding.php` — entity with `type` enum + nc-fields
- `src/Entity/CorrectiveAction.php` — separate CAPA entity (7-state lifecycle)
- `src/Enum/AuditFindingStatus.php` — finding-lifecycle enum
- `migrations/Version20260614100000.php` — adds nc-specific columns
- `docs/decisions/2026-05-23-capa-canonical-process.md` — earlier CAPA-Canonical refactor
- ISO 27001:2022 Cl. 10.1–10.2 (nonconformity and corrective action)
- ISO 19011:2018 Cl. 6.4.8 (audit findings classification)
- ISO 17021-1:2015 Cl. 9.4.8 (certification body audit reporting)
- B4 backlog entry in `var/junior-isb-audit/BACKLOG_2026-05-25.md` (resolved)
