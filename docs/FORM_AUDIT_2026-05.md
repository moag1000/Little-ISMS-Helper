# Form Audit — Norm-Gating Rollout (May 2026)

> **Audience:** External auditors, compliance officers, CISO, DPO  
> **Period covered:** 2026-05-01 – 2026-05-08  
> **Scope:** T31 FormType Norm-Gating Rollout, Sprints 1–8  
> **Cross-references:** [MODULE_GATING_GUIDE.md](MODULE_GATING_GUIDE.md) | [CONTRIBUTING.md](../CONTRIBUTING.md)

---

## Executive Summary

Six specialist reviews were integrated into a single implementation sprint series (T31):

| Specialist | Focus area | Key findings |
|---|---|---|
| DPO Specialist | GDPR Art. 7, 12, 15-21, 30, 33/34, 35 | Consent withdrawal, DSR deadline tracking, DPIA completeness |
| BSI Specialist | BSI 200-3/4, IT-Grundschutz | Risk justification fields, BCM JSON structuring |
| BCM Specialist | ISO 22301 | BC Plan team members, BC Exercise RTO/RPO, Crisis Team contacts |
| Risk Management Specialist | ISO 27005, FAIR | Likelihood/impact justification, quantitative risk FAIR fields |
| Pentester Specialist | Security hardening | SSRF protection (4 URL fields), file-upload hardening, privilege escalation (RiskAcceptanceVoter) |
| ISMS Specialist | ISO 27001:2022 | ManagementReview §9.3 agenda, AuditFinding source, CorrectiveAction type, Training competence |

**Results:**

- 50+ norm-mandated fields added across 23 FormTypes
- 8 new module keys added to `config/modules.yaml` (consolidation from 17 raw specialist requests)
- 8 controllers gained whole-form module gating (privacy, BCM)
- 2 security fixes: SSRF (NoInternalIp constraint on 4 URL fields) + file-upload hardening (5 FileTypes)
- 1 new Voter: `RiskAcceptanceVoter` (role escalation check for high-value risk acceptance)
- 2 migration files (`Version20260507100000`, `Version20260507100001`) with `isTransactional(): false`

---

## Sprint Timeline

| Sprint | Tag | Commit SHA | Description | FTE-days |
|---|---|---|---|---|
| 1.1 | T31.1.1 | `34da7d1e` / `144de67f` | ModuleAwareFormTrait + 8 module keys added | 0.5 |
| 1.2 | T31.1.2 | `66ad361f` | Risk: likelihood/impact justification + GDPR subset gating + RiskAcceptanceVoter | 1.5 |
| 1.3 | T31.1.3 | `c582188d` | Consent: GDPR Art. 7(3) withdrawnAt + reason + channel | 0.5 |
| 1.4 | T31.1.4 | `26a21086` | DSR: GDPR Art. 12(3) responseAt + extendedDeadline + extensionReason + document + method + rejection | 1.0 |
| 1.5 | T31.1.5 | `8740412b` | Security: SSRF protection NoInternalIp constraint (4 URL fields) | 0.5 |
| 1.6 | T31.1.6 | `7709bba4` | Security: File-upload hardening MIME+Size+SecurityService (5 FileTypes) | 0.5 |
| 1.7 | T31.1.7 | `3af1d492` | Migration: Sprint-1 norm-fields | 0.5 |
| 2.1 | T31.2.1 | `e13edce2` | Whole-form gating 8 controllers (DataBreach, Consent, DSR, ProcessingActivity, DPIA, BCPlan, BCExercise, CrisisTeam) | 1.0 |
| 2.2 | T31.2.2 | `57b5ac3a` | Incident: ISO A.5.28 evidence + DORA Art. 17-19 ICT fields | 1.0 |
| 2.3 | T31.2.3 | `07c83580` | Asset: AI-Agent gating + EU AI Act Art. 5 prohibited validation | 1.0 |
| 2.4 | T31.2.4 | `8bd16777` | BCM: structured JSON fields (BC Plan, BC Exercise, Crisis Team) | 1.0 |
| 2.5 | T31.2.5 | `daccc7eb` | ManagementReview: ISO 27001 §9.3 agenda fields | 0.5 |
| 2.6 | T31.2.6 | `e327d02b` | Migration: Sprint-2 norm-fields | 0.5 |
| 3.1 | T31.3.1 | `4c3a994a` | Cross-cutting classifiers: AuditFinding.source enum, CorrectiveAction.actionType enum, ChangeRequest.clauseReference | 1.0 |
| 4.1 | T31.4.1 | `b4062ca3` | Support clauses: Training (ISO §7.2), User (§7.3 awareness), Document (§7.4 communication) | 1.0 |
| 5+6+7 | T31.5+ | `5b84a288` | ControlType (cloud fields §ISO 27017/18/27701) + RiskType DORA-ICT subset + Supplier MaRisk outsourcing | 2.0 |
| 8.1 | T31.8.1 | `4c8e1536` | ThreatIntel TLP/MITRE/IOC fields + Quantitative Risk FAIR methodology fields | 1.5 |

**Total: ~16 FTE-days across 8 sprint batches**

---

## Per-FormType Change Log

### RiskType (`src/Form/RiskType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `likelihoodJustification` | ISO 27005 §8.3 — documented assessment rationale | none (always) | 1.2 |
| `impactJustification` | ISO 27005 §8.3 — documented assessment rationale | none (always) | 1.2 |
| `decisionApprovedByUser` | ISO 27001 §6.1.3 — treatment plan approval | none (always) | 1.2 |
| `decisionApprovalDate` | ISO 27001 §6.1.3 | none (always) | 1.2 |
| `decisionRationale` | ISO 27001 §6.1.3 | none (always) | 1.2 |
| `involvesPersonalData` | GDPR Art. 35(1) — DPIA trigger check | `privacy` | 1.2 |
| `involvesSpecialCategoryData` | GDPR Art. 9 + Art. 35 | `privacy` | 1.2 |
| `ictRiskCategory` | DORA Art. 28(4) — ICT risk categorisation | `nis2_dora` | 5+ |
| `ictSystemCritical` | DORA Art. 28(2) | `nis2_dora` | 5+ |

**Voter added:** `RiskAcceptanceVoter` — requires MANAGER role minimum when risk score ≥ 15, ADMIN when ≥ 20. Prevents privilege escalation on risk acceptance sign-off.

### ConsentType (`src/Form/ConsentType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `withdrawnAt` | GDPR Art. 7(3) — right to withdraw consent | `privacy` | 1.3 |
| `withdrawalReason` | GDPR Art. 7(3) — documented reason | `privacy` | 1.3 |
| `withdrawalChannel` | GDPR Art. 7(3) — channel of withdrawal | `privacy` | 1.3 |

### DataSubjectRequestType (`src/Form/DataSubjectRequestType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `responseAt` | GDPR Art. 12(3) — 30-day response deadline tracking | `privacy` | 1.4 |
| `extendedDeadline` | GDPR Art. 12(3) — extension to 90 days | `privacy` | 1.4 |
| `extensionReason` | GDPR Art. 12(3) — documented extension reason | `privacy` | 1.4 |
| `responseDocument` | GDPR Art. 15-21 — evidence of response | `privacy` | 1.4 |
| `responseMethod` | GDPR Art. 12(1) — how delivered | `privacy` | 1.4 |
| `rejectionReason` | GDPR Art. 12(5) — rejection only under Art. 12(5)(b) | `privacy` | 1.4 |

### IdentityProviderType, PatchType, CrisisTeamType, TenantEmailBrandingType

| Field | Norm Source | Fix | Sprint |
|---|---|---|---|
| All URL fields | Security — SSRF prevention | `NoInternalIp` constraint added | 1.5 |

### IncidentType (`src/Form/IncidentType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `evidenceCollected` | ISO 27001 A.5.28 — evidence of incident handling | none (always) | 2.2 |
| `containmentActions` | ISO 27001 A.5.26/A.5.28 | none (always) | 2.2 |
| `ictIncidentClassification` | DORA Art. 17(1) — major ICT incident classification | `nis2_dora` | 2.2 |
| `dataLossOccurred` | DORA Art. 17(3)(d) | `nis2_dora` | 2.2 |
| `dataLeakageOccurred` | DORA Art. 17(3)(d) | `nis2_dora` | 2.2 |
| `economicImpact` | DORA Art. 17(3)(g) | `nis2_dora` | 2.2 |
| `reputationalImpact` | DORA Art. 17(3)(f) | `nis2_dora` | 2.2 |
| `clientsAffected` | DORA Art. 17(3)(b) | `nis2_dora` | 2.2 |
| `geographicalSpread` | DORA Art. 17(3)(c) | `nis2_dora` | 2.2 |
| `regulatoryNotificationRequired` | NIS2 Art. 23 / DORA Art. 19 | `nis2_dora` | 2.2 |
| `regulatoryNotificationDeadline` | NIS2 Art. 23(1) — 24/72h deadline | `nis2_dora` | 2.2 |
| `regulatoryNotificationSentAt` | NIS2 Art. 23 — evidence of notification | `nis2_dora` | 2.2 |

### AssetType (`src/Form/AssetType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `aiSystemPurpose` | EU AI Act Art. 6/9 — intended purpose | `ai_governance` | 2.3 |
| `aiSystemRiskLevel` | EU AI Act Art. 6 — risk classification | `ai_governance` | 2.3 |
| `isProhibitedAiUse` | EU AI Act Art. 5 — prohibited AI practices | `ai_governance` | 2.3 |
| `prohibitedAiJustification` | EU AI Act Art. 5 — must not be justified; validation prevents save | `ai_governance` | 2.3 |
| `aiActComplianceStatus` | EU AI Act Art. 9/17 — conformity | `ai_governance` | 2.3 |

**Validation added:** If `isProhibitedAiUse = true`, form validation blocks save with
error referencing EU AI Act Art. 5. (Stimulus front-end pre-validation + server-side constraint.)

### BusinessContinuityPlanType (`src/Form/BusinessContinuityPlanType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `responseTeamMembers` | ISO 22301 §8.4.4 — recovery team definition | `bcm` | 2.4 |
| `requiredResources` | ISO 22301 §8.4.2 — resource requirements | `bcm` | 2.4 |

### CrisisTeamType (`src/Form/CrisisTeamType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `members` | ISO 22301 §7.1 — crisis team composition | `bcm` | 2.4 |
| `emergencyContacts` | ISO 22301 §8.4.3 — communication plan | `bcm` | 2.4 |

### BCExerciseType (`src/Form/BCExerciseType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `successCriteria` | ISO 22301 §8.5 — exercise objectives | `bcm` | 2.4 |
| `actualRtoAchieved` | ISO 22301 §8.4.1 — recovery time objective measurement | `bcm` | 2.4 |
| `actualRpoAchieved` | ISO 22301 §8.4.1 — recovery point objective measurement | `bcm` | 2.4 |

### ManagementReviewType (`src/Form/ManagementReviewType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `topManagementAttended` | ISO 27001 §9.3.1 — top management participation required | none (always) | 2.5 |
| `nextReviewDate` | ISO 27001 §9.3.1 — scheduled next review | none (always) | 2.5 |
| `meetingMinutesDocument` | ISO 27001 §9.3.1 — documented outputs | none (always) | 2.5 |
| `frameworkComplianceStatus` | ISO 27001 §9.3.2(g) — compliance obligations | `compliance` | 2.5 |

### AuditFindingType, CorrectiveActionType, ChangeRequestType (`src/Form/`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `AuditFinding.source` (enum) | ISO 19011 §6.4.5 — finding source classification | none (always) | 3.1 |
| `CorrectiveAction.actionType` (enum) | ISO 27001 §10.1 — corrective vs. preventive | none (always) | 3.1 |
| `ChangeRequest.clauseReference` | ISO 27001 A.8.32 — change management control | none (always) | 3.1 |

### TrainingType, UserType, DocumentType

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `Training.competenceEvidence` | ISO 27001 §7.2 — demonstrated competence | `training` | 4.1 |
| `Training.awarenessTopics` | ISO 27001 §7.3 — awareness topics | `training` | 4.1 |
| `Document.communicationMethod` | ISO 27001 §7.4 — communication planning | none (always) | 4.1 |

### ControlType (`src/Form/ControlType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `effectiveness` | ISO 27001 §9.1 — performance evaluation | none (always) | 5+ |
| `controlType` | ISO 27001 A.5 — preventive/detective/corrective | none (always) | 5+ |
| `controlMaturity` | ISO 33000 / CMMI levels | none (always) | 5+ |
| `cloudControlReference` | ISO 27017 §6 — cloud-specific controls | `cloud_security` | 5+ |
| `cloudPrivacyReference` | ISO 27018 — cloud privacy | `cloud_security` | 5+ |
| `pimsReference` | ISO 27701 §6/7 — PIMS extension | `cloud_security` | 5+ |
| `customerOrProviderResponsibility` | ISO 27017 §6.3 — shared responsibility | `cloud_security` | 5+ |

### SupplierType (`src/Form/SupplierType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `outsourcingClassification` | MaRisk AT 9.2 — wesentlich vs. nicht-wesentlich | `marisk` | 5+ |
| `aufsichtlicheMeldepflicht` | MaRisk AT 9.2 Tz. 8 — regulatory notification duty | `marisk` | 5+ |
| `subcontractorChain` | MaRisk AT 9.2 — Weiterverlagerung documentation | `marisk` | 5+ |
| `exitStrategyDocumented` | MaRisk AT 9.2 — exit plan | `marisk` | 5+ |

### ThreatIntelligenceType (`src/Form/ThreatIntelligenceType.php`)

| Field | Norm Source | Module Gate | Sprint |
|---|---|---|---|
| `tlpClassification` | FIRST TLP 2.0 — Traffic Light Protocol | `vulnerability_intel` | 8.1 |
| `threatActorAttribution` | MITRE ATT&CK attribution | `vulnerability_intel` | 8.1 |
| `mitreAttackTechniques` | MITRE ATT&CK Technique IDs | `vulnerability_intel` | 8.1 |
| `iocList` | Indicators of Compromise | `vulnerability_intel` | 8.1 |
| `confidenceScore` | Intelligence confidence (0-100) | `vulnerability_intel` | 8.1 |
| `fairLossEventFrequency` | FAIR methodology — LEF | `quantitative_risk` | 8.1 |
| `fairLossMagnitude` | FAIR methodology — LM | `quantitative_risk` | 8.1 |
| `fairRiskScore` | FAIR — computed quantitative risk | `quantitative_risk` | 8.1 |

---

## Compliance Coverage Matrix

### GDPR — 5 FormTypes

| Requirement | Article | FormType | Field(s) | Status |
|---|---|---|---|---|
| Consent withdrawal documented | Art. 7(3) | ConsentType | withdrawnAt, withdrawalReason, withdrawalChannel | ✅ Covered |
| DSR response deadline 30/90 days | Art. 12(3) | DataSubjectRequestType | responseAt, extendedDeadline | ✅ Covered |
| DSR rejection documented | Art. 12(5) | DataSubjectRequestType | rejectionReason | ✅ Covered |
| Personal data risk trigger | Art. 35(1) | RiskType | involvesPersonalData, involvesSpecialCategoryData | ✅ Covered |
| Data breach notification 72h | Art. 33 | DataBreachController gated | whole-form | ✅ Module-gated |
| DPIA completeness | Art. 35 | DPIAController gated | whole-form | ✅ Module-gated |
| RoPA completeness | Art. 30 | ProcessingActivityController | whole-form | ✅ Module-gated |

### ISO 27001:2022

| Requirement | Clause/Annex | FormType | Field(s) | Status |
|---|---|---|---|---|
| Risk assessment rationale documented | §6.1.2 / ISO 27005 §8.3 | RiskType | likelihoodJustification, impactJustification | ✅ Covered |
| Risk treatment approval | §6.1.3 | RiskType | decisionApprovedByUser, decisionApprovalDate | ✅ Covered |
| Incident evidence collection | A.5.28 | IncidentType | evidenceCollected, containmentActions | ✅ Covered |
| Competence demonstrated | §7.2 | TrainingType | competenceEvidence | ✅ Covered |
| Awareness programme | §7.3 | TrainingType | awarenessTopics | ✅ Covered |
| Communication planned | §7.4 | DocumentType | communicationMethod | ✅ Covered |
| Management review attendance | §9.3.1 | ManagementReviewType | topManagementAttended | ✅ Covered |
| Management review outputs | §9.3.1 | ManagementReviewType | meetingMinutesDocument, nextReviewDate | ✅ Covered |
| Corrective action type | §10.1 | CorrectiveActionType | actionType | ✅ Covered |
| Audit finding source | ISO 19011 §6.4.5 | AuditFindingType | source | ✅ Covered |
| Change management reference | A.8.32 | ChangeRequestType | clauseReference | ✅ Covered |

### DORA (EU 2022/2554)

| Requirement | Article | FormType | Field(s) | Module Gate | Status |
|---|---|---|---|---|---|
| Major ICT incident classification | Art. 17(1) | IncidentType | ictIncidentClassification | `nis2_dora` | ✅ Gated |
| ICT incident impact criteria | Art. 17(3) | IncidentType | dataLoss/Leakage, economicImpact, clientsAffected | `nis2_dora` | ✅ Gated |
| Competent authority notification | Art. 19 | IncidentType | regulatoryNotificationRequired, SentAt, Deadline | `nis2_dora` | ✅ Gated |
| ICT risk categorisation | Art. 28(4) | RiskType | ictRiskCategory, ictSystemCritical | `nis2_dora` | ✅ Gated |

### NIS2 (EU 2022/2555 Art. 21)

| Requirement | Article | FormType | Field(s) | Module Gate | Status |
|---|---|---|---|---|---|
| Incident notification 24/72h | Art. 23(1) | IncidentType | regulatoryNotificationDeadline | `nis2_dora` | ✅ Gated |
| Significant incident reporting | Art. 23 | IncidentType | regulatoryNotificationSentAt | `nis2_dora` | ✅ Gated |

### BSI 200-3/4 (Risk + BCM)

| Requirement | Standard | FormType | Field(s) | Status |
|---|---|---|---|---|
| Documented risk assessment | BSI 200-3 §3 | RiskType | likelihoodJustification, impactJustification | ✅ Covered |
| BCM recovery team | BSI 200-4 §4.4 | BusinessContinuityPlanType | responseTeamMembers | ✅ Module-gated |
| BCM exercise measurement | BSI 200-4 §5.5 | BCExerciseType | actualRtoAchieved, actualRpoAchieved | ✅ Module-gated |

### EU AI Act (2024/1689)

| Requirement | Article | FormType | Field(s) | Module Gate | Status |
|---|---|---|---|---|---|
| AI system intended purpose | Art. 6/9 | AssetType | aiSystemPurpose, aiSystemRiskLevel | `ai_governance` | ✅ Gated |
| Prohibited AI practices blocked | Art. 5 | AssetType | isProhibitedAiUse + validation constraint | `ai_governance` | ✅ Gated |
| Conformity assessment | Art. 17 | AssetType | aiActComplianceStatus | `ai_governance` | ✅ Gated |

### ISO 22301 (BCM)

| Requirement | Clause | FormType | Field(s) | Module Gate | Status |
|---|---|---|---|---|---|
| Resource requirements | §8.4.2 | BusinessContinuityPlanType | requiredResources (JSON) | `bcm` | ✅ Gated |
| Recovery team definition | §8.4.4 | BusinessContinuityPlanType | responseTeamMembers (JSON) | `bcm` | ✅ Gated |
| Exercise objectives | §8.5 | BCExerciseType | successCriteria (JSON) | `bcm` | ✅ Gated |
| RTO/RPO measurement | §8.4.1 | BCExerciseType | actualRtoAchieved/Rpo | `bcm` | ✅ Gated |
| Crisis team contacts | §8.4.3 | CrisisTeamType | emergencyContacts (JSON) | `bcm` | ✅ Gated |

### ISO 27017 / 27018 / 27701 (Cloud + Privacy Controls)

| Requirement | Standard | FormType | Field(s) | Module Gate | Status |
|---|---|---|---|---|---|
| Cloud-specific controls | ISO 27017 §6 | ControlType | cloudControlReference | `cloud_security` | ✅ Gated |
| Cloud privacy controls | ISO 27018 | ControlType | cloudPrivacyReference | `cloud_security` | ✅ Gated |
| PIMS reference | ISO 27701 §6/7 | ControlType | pimsReference | `cloud_security` | ✅ Gated |
| Shared responsibility | ISO 27017 §6.3 | ControlType | customerOrProviderResponsibility | `cloud_security` | ✅ Gated |

### MaRisk (BaFin, 2021 revision)

| Requirement | Ref | FormType | Field(s) | Module Gate | Status |
|---|---|---|---|---|---|
| Outsourcing classification | AT 9.2 | SupplierType | outsourcingClassification | `marisk` | ✅ Gated |
| Regulatory notification duty | AT 9.2 Tz. 8 | SupplierType | aufsichtlicheMeldepflicht | `marisk` | ✅ Gated |
| Sub-outsourcing (Weiterverlagerung) | AT 9.2 | SupplierType | subcontractorChain | `marisk` | ✅ Gated |
| Exit strategy | AT 9.2 | SupplierType | exitStrategyDocumented | `marisk` | ✅ Gated |

---

## Security Fixes (Pentester Findings)

| Finding | Severity | Fix | Sprint |
|---|---|---|---|
| SSRF via IdentityProvider metadataUrl | HIGH | `NoInternalIp` constraint on URL field | 1.5 |
| SSRF via Patch repositoryUrl | HIGH | `NoInternalIp` constraint | 1.5 |
| SSRF via CrisisTeam virtualMeetingUrl | MEDIUM | `NoInternalIp` constraint | 1.5 |
| SSRF via TenantEmailBranding emailLogoUrl | MEDIUM | `NoInternalIp` constraint | 1.5 |
| Unrestricted file upload (5 FileTypes) | HIGH | MIME whitelist + size limit + `FileUploadSecurityService` | 1.6 |
| Privilege escalation on high-value risk acceptance | MEDIUM | `RiskAcceptanceVoter` — MANAGER ≥15, ADMIN ≥20 | 1.2 |

---

## Auditor Notes

### Evidence Collection

All form changes produce structured data in the database that can be exported via:

```
/admin/export  → Entity-level export (JSON/CSV)
/reports/audit-log  → AuditLogger trail for every field change
```

### Module Activation Audit Trail

Changes to `config/active_modules.yaml` (tenant module activation) are not automatically
logged. For audit evidence of module activation state changes, consult git history on
`config/active_modules.yaml` or the Setup Wizard completion timestamp stored in the
Tenant entity (`setupWizardCompletedAt`).

### GDPR Lawfulness of Processing

The `privacy` module gate ensures that GDPR-specific fields are only presented to
tenants who have declared themselves data controllers/processors. This reduces the
risk of incorrect data collection for tenants not subject to GDPR.

### Contact

Technical questions: open a GitHub issue (label `compliance`).  
Audit evidence requests: contact the CISO / DPO via the Tenant's designated contacts.
