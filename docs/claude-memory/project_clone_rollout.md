---
name: project-clone-rollout
description: "C4-C1 Klon-Funktionen — Pattern für Entity-Cloner. 8 Entities cloneable, alle ROLE_USER-zugänglich, alle in Show-Templates verdrahtet."
metadata: 
  node_type: memory
  type: project
  originSessionId: f94a1c13-01b9-4ace-8321-f7d76cfa1b21
---

**Pattern (etabliert 2026-05-27, Commit `77ce12982`):**

- Service unter `src/Service/Clone/<Entity>Cloner.php` implementiert `EntityClonerInterface`
- Pro-Entity-Cloner: kopiert Template-Felder + Master-Data-Refs, resettet Lifecycle-Status auf initial, omittet Cascade-Children (Execution-History, Versionen, Audit-Log-Einträge)
- Caller (Controller) ist verantwortlich für `flush()` + AuditLogger::logCreate
- Show-Template POST-Button mit per-Entity CSRF-Token (`clone_<entity>_<id>`)
- Alle Clone-Endpoints `IsGranted('ROLE_USER')` (broadened InternalAudit von ROLE_MANAGER)

**8 cloneable Entities:**

| Entity | Cloner | Reset-Felder |
|---|---|---|
| Risk | `RiskCloner` | status=Identified, residual=1/1, reviewDate, acceptance* |
| Asset | `AssetCloner` | status='active', returnDate, currentValue=acquisition |
| Document | `DocumentCloner` | status='draft', isImmutable=false, version='1.0', file binary cleared |
| Training | `TrainingCloner` | status='planned', scheduledDate, completionDate, attendeeCount, feedback |
| Supplier | `SupplierCloner` | status='evaluation', securityScore, contract*, dpaSignedDate |
| BusinessContinuityPlan | `BCPlanCloner` | status='draft', version='1.0', lastTested, nextTestDate, lastReviewDate |
| BCExercise | `BCExerciseCloner` | status='planned', exerciseDate, results, successRating, reportCompleted |
| AuditFinding | `AuditFindingCloner` | status='open', findingNumber, evidence, dueDate, nc-verification fields |

**Vorbestehende Cloner (nicht neu, aber gleiche Pattern-Familie):**
- InternalAudit → `InternalAuditCloner`
- DPIA → `DataProtectionImpactAssessmentService::clone()`
- ProcessingActivity → `ProcessingActivityService::clone()`
- CustomReport → `CustomReport::cloneAsNew()` (Entity-Methode)

**Trans-Keys (im messages.de/en):**
- `common.clone.label` ("Duplizieren" / "Duplicate")
- `common.clone.help` (Button-Tooltip)
- Pro-Domain `<domain>.clone.success` Flash-Message

**Tests:** `tests/Service/Clone/EntityClonersTest.php` — 10/10 grün, 79 Assertions.

**Was NICHT cloneable (by design):**
- Incident, DataBreach (singuläre Events), AuditFreeze (immutable Snapshot), AuditLog, Comment, DataSubjectRequest (per-Requester), ComplianceMapping, User/Tenant/Permission/Role

**Why:** User-Aufforderung 2026-05-27 "Klonfunktion find ich für diverse Punkte super wichtig" + "schau auch dass die Funktion für alle erreichbar ist". Tier 1+2+3 (8 Entities) komplett ausgerollt.

**How to apply:**
- Neue Entity-Klone: kopiere RiskCloner als Vorlage, ergänze `supportsEntity()`, im Controller ?-injecten + Route `/<entity>/{id}/clone` POST + Show-Template-Button
- Lifecycle-State immer auf initial-marking resetten (nicht setStatus mit Workflow umgehen — Cloner ist pre-persist, daher OK)
- M2M-Refs zur Master-Daten (Controls, Requirements, Persons) BEHALTEN — Template-Wert. M2M zu Execution-Records (Findings, Participations, Versionen) NICHT kopieren — verfälscht Audit-Trail.
