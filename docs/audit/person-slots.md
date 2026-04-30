# Person-Slots Audit (Stand 2026-04-30)

Map aller `?User $field`-Mappings in `src/Entity/*.php`. Klassifikation steuert
Plan B + C: nur `owner-or-responsible` bekommt das Tri-State + Deputy-Pattern.

## Naming Convention

Für jeden `owner-or-responsible`-Slot gelten folgende Regeln:

1. **Primary Person-Slot** (`*Person`): Das `User`-Suffix des Feldnamens wird
   durch `Person` ersetzt. Hat das Original kein `User`-Suffix (z. B. `riskOwner`,
   `assignedTo`), wird `Person` angehängt bzw. der Name leserlich angepasst:
   - `processOwnerUser` → `processOwnerPerson`
   - `riskOwner` → `riskOwnerPerson`
   - `assignedTo` → `assignedPerson`
   - `responsiblePersonUser` (Control) → `responsiblePerson` (primary, kürzer) — da
     `responsiblePersonPerson` unlesbar wäre; das bestehende Feld heißt `responsiblePersonUser`,
     neues Person-Feld heißt `responsiblePerson`.
   - Felder, die bereits `Person` im Namen tragen (`responsiblePerson`, `contactPerson`),
     bleiben unverändert — das `?User`-Feld wird zu `*User` umbenannt (Suffix ergänzen)
     und das neue `?Person`-Feld bekommt den ursprünglichen Namen.

2. **Deputy-Slot** (`*DeputyPersons`): Aus dem bereinigten Primär-Feldnamen (ohne
   `User`, ohne `Person`-Suffix) + `DeputyPersons`.
   - `processOwnerUser` → deputies `processOwnerDeputyPersons`
   - `riskOwner` → deputies `riskOwnerDeputyPersons`
   - `assignedTo` → deputies `assignedDeputyPersons`
   - `responsiblePersonUser` (Control) → deputies `responsibleDeputyPersons`

3. **Plan-Spalte:**
   - **A** = Referenzimplementierung (BusinessProcess, bereits als Sample genutzt)
   - **B** = Hoher Traffic / Prüfer-Sichtbarkeit — nächste Rollout-Welle
   - **C** = Restliche Owner-Slots — spätere Welle

---

## owner-or-responsible (Plan B + C scope)

| Entity | Field | New `*Person` slot | New `*DeputyPersons` slot | Plan |
|---|---|---|---|---|
| BusinessProcess | processOwnerUser | processOwnerPerson | processOwnerDeputyPersons | A (sample) |
| Asset | ownerUser | ownerPerson **(DONE 4fed1f86)** | ownerDeputyPersons | B |
| Risk | riskOwner | riskOwnerPerson | riskOwnerDeputyPersons | B |
| Control | responsiblePersonUser | responsiblePerson | responsibleDeputyPersons | B |
| Incident | reportedByUser | reportedByPerson | reportedByDeputyPersons | B |
| BusinessContinuityPlan | planOwnerUser | planOwnerPerson | planOwnerDeputyPersons | B |
| DataBreach | dataProtectionOfficer | dataProtectionOfficerPerson | dataProtectionOfficerDeputyPersons | B |
| DataBreach | assessor | assessorPerson | assessorDeputyPersons | B |
| DataProtectionImpactAssessment | dataProtectionOfficer | dataProtectionOfficerPerson | dataProtectionOfficerDeputyPersons | B |
| DataProtectionImpactAssessment | conductor | conductorPerson | conductorDeputyPersons | B |
| DataProtectionImpactAssessment | approver | approverPerson | approverDeputyPersons | B |
| ProcessingActivity | contactPerson | contactPerson *(rename `?User` → `contactPersonUser`)* | contactDeputyPersons | B |
| ProcessingActivity | dataProtectionOfficer | dataProtectionOfficerPerson | dataProtectionOfficerDeputyPersons | B |
| AuditFinding | assignedTo | assignedPerson | assignedDeputyPersons | C |
| AuditFinding | reportedBy | reportedByPerson | reportedByDeputyPersons | C |
| ComplianceRequirementFulfillment | responsiblePerson | responsiblePerson *(rename `?User` → `responsiblePersonUser`)* | responsibleDeputyPersons | C |
| CorrectiveAction | responsiblePerson | responsiblePerson *(rename `?User` → `responsiblePersonUser`)* | responsibleDeputyPersons | C |
| CrisisTeam | teamLeader | teamLeaderPerson | teamLeaderDeputyPersons | C |
| CrisisTeam | deputyLeader | deputyLeaderPerson | deputyLeaderDeputyPersons | C |
| CustomReport | owner | ownerPerson | ownerDeputyPersons | C |
| DataSubjectRequest | assignedTo | assignedPerson | assignedDeputyPersons | C |
| FourEyesApprovalRequest | requestedApprover | requestedApproverPerson | requestedApproverDeputyPersons | C |
| ManagementReview | reviewedBy | reviewedByPerson | reviewedByDeputyPersons | C |
| PrototypeProtectionAssessment | assessor | assessorPerson | assessorDeputyPersons | C |
| RiskTreatmentPlan | responsiblePerson | responsiblePerson *(rename `?User` → `responsiblePersonUser`)* | responsibleDeputyPersons | C |
| ThreatIntelligence | assignedTo | assignedPerson | assignedDeputyPersons | C |
| Training | trainerUser | trainerPerson | trainerDeputyPersons | C |

**Total owner-or-responsible: 27 slots across 18 entities** (Asset DONE; 26 slots remaining)

---

## audit-trail (NOT in scope — User-only)

System-only who-did-what-when fields. External Person-slots make no sense here because
these record which internal *system actor* performed an automated or system-level action.

| Entity | Field | Reason |
|---|---|---|
| AppliedBaseline | appliedBy | System action — baseline was applied by a user session, not an external contact |
| ComplianceRequirementFulfillment | lastUpdatedBy | Tracks last edit author — system/audit trail |
| Consent | documentedBy | Records who entered the consent — system data-entry audit trail |
| Consent | revocationDocumentedBy | Records who logged the revocation — system data-entry audit trail |
| Consent | verifiedBy | Records who verified consent — system verification action |
| CorporateGovernance | createdBy | Standard audit trail field |
| DataBreach | createdBy | Standard audit trail field |
| DataBreach | updatedBy | Standard audit trail field |
| DataProtectionImpactAssessment | createdBy | Standard audit trail field |
| DataProtectionImpactAssessment | updatedBy | Standard audit trail field |
| EntityTag | taggedBy | Records who applied the tag — system action |
| FourEyesApprovalRequest | approvedBy | Who actually approved — system 4-eyes audit trace |
| FourEyesApprovalRequest | requestedBy | Who initiated the 4-eyes request — initiator audit trail |
| FulfillmentInheritanceLog | fourEyesApprovedBy | 4-eyes audit event log |
| FulfillmentInheritanceLog | overriddenBy | Audit trail for override action |
| FulfillmentInheritanceLog | reviewedBy | Audit event — who reviewed the inheritance |
| ImportSession | uploadedBy | Who uploaded the import file — system action |
| ImportSession | fourEyesApprover | 4-eyes approver during import — system audit |
| IncidentSlaConfig | updatedBy | Config audit trail |
| ProcessingActivity | createdBy | Standard audit trail field |
| ProcessingActivity | updatedBy | Standard audit trail field |
| Risk | acceptanceApprovedByUser | 4-eyes / formal approval of risk acceptance — audit-trace |
| RiskAppetite | approvedBy | Formal approval of risk appetite document — audit trace |
| RiskApprovalConfig | updatedBy | Config audit trail |
| SampleDataImport | importedBy | System import action |
| ScheduledReport | createdBy | Standard audit trail field |
| SsoUserApproval | reviewedBy | SSO onboarding review — system IAM audit |
| WorkflowInstance | initiatedBy | Who triggered the workflow — system workflow audit trail |

**Total audit-trail: 28 slots across 22 entities**

---

## technical (semantic identity — NOT scope)

Fields where the User *is* the entity's identity — i.e. the record exists per-user
or the user *is* the subject, not a role-holder.

| Entity | Field | Reason |
|---|---|---|
| CryptographicOperation | user | Crypto op belongs to a User identity — the user IS the subject |
| DashboardLayout | user | Layout is per-user preference — User IS the subject |
| MfaToken | user | MFA credential belongs to a User identity |
| Person | linkedUser | Reverse link: Person → User account; Person already IS the abstraction |
| PhysicalAccessLog | user | Access log subject is the User identity |
| PushSubscription | user | Push subscription is per-User device — technical identity binding |
| UserSession | user | Session belongs to User identity |
| WizardSession | user | Wizard progress belongs to User identity |

**Total technical: 8 slots across 8 entities**

---

## ambiguous (resolve manually)

| Entity | Field | Note |
|---|---|---|
| Document | uploadedBy | Could be audit-trail (system upload action) **or** owner-or-responsible (document author/owner). Currently no `getEffective*`. Recommend: keep as audit-trail for now; if document ownership workflow is added, promote to owner-or-responsible with `documentOwner` slot separately. |

**Total ambiguous: 1**

---

## Summary

| Bucket | Count |
|---|---|
| owner-or-responsible (Plan B + C) | 27 |
| audit-trail (excluded) | 28 |
| technical (excluded) | 8 |
| ambiguous (manual decision) | 1 |
| **Total** | **64** |

### Entities with existing `getEffective*` accessors (Pattern-A dual-state already present)

These 14 entities already have `private ?User` for the primary slot AND a `private ?string`
legacy/plain-text fallback field, bridged via `getEffective*()`:

| Entity | getEffective* method | `?User` field | Status |
|---|---|---|---|
| Asset | getEffectiveOwner() | ownerUser | **DONE (4fed1f86)** — full Tri-State incl. Person-slot |
| BCExercise | getEffectivenessScore() | *(no ?User field — score accessor, not person)* | n/a |
| BusinessContinuityPlan | getEffectivePlanOwner() | planOwnerUser | Plan B |
| BusinessProcess | getEffectiveProcessOwner() | processOwnerUser | Plan A (sample) |
| Control | getEffectiveResponsiblePerson() | responsiblePersonUser | Plan B |
| DataBreach | getEffectiveDetectedAt() | *(date field, not person)* | n/a |
| DataSubjectRequest | getEffectiveDeadline() | *(date field, not person)* | n/a |
| FulfillmentInheritanceLog | getEffectivePercentage() | *(numeric, not person)* | n/a |
| Incident | getEffectiveReportedBy() | reportedByUser | Plan B |
| PhysicalAccessLog | getEffectivePersonName() | *(string name, no ?User — technical)* | n/a |
| PhysicalAccessLog | getEffectiveLocation() | *(location field, not person)* | n/a |
| Risk | getEffectiveAcceptanceApprovedBy() | acceptanceApprovedByUser | audit-trail (excluded) |
| Training | getEffectiveTrainer() | trainerUser | Plan C |

> Note: BCExercise, DataBreach, DataSubjectRequest, FulfillmentInheritanceLog, and
> PhysicalAccessLog have `getEffective*` methods but for non-person fields (dates, scores,
> locations). Risk.acceptanceApprovedByUser is in audit-trail bucket. The true
> Pattern-A (person dual-state) entities are: Asset (**DONE**), BusinessContinuityPlan,
> BusinessProcess, Control, Incident, Training — 6 entities total with person dual-state.
