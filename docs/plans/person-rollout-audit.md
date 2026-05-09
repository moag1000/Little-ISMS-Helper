# Person-Rollout Audit (User → Person FK Migration)

**Sprint:** 2026-05-08 — Phase A (Policy-Wizard scope) + Phase B planning
**Branch:** `feature/policy-wizard`
**Status:** Phase A live. Phase B2 (Privacy + Incident + Audit cluster
narrow-scope) live as of `Version20260509050000_person_rollout_b2_privacy_incident`
— adds the three remaining genuinely-missing governance Person FKs:
`incident.responsible_person_id`, `data_subject_request.dpo_person_id`,
`compliance_requirement_fulfillment.attestation_owner_person_id`.

Most other Phase-B candidates (DPIA, ProcessingActivity, DataBreach,
AuditFinding, CorrectiveAction, Training, BCM cluster) ALREADY have
Person FKs from prior sprint work — see "Already migrated" matrix
below. B1 (BCM cluster) is owned by the parallel agent.

## Background

DACH-Mittelstand realität: many ISMS roles (CISO/ISB/DPO/BCM-Officer)
are routinely held by **external advisors** (consultant, auditor, vendor)
who do not have an application login. The previous design wired every
"owner" / "responsible" field to `App\Entity\User` (login required),
forcing customers to create dummy users for external role-holders —
audit-trail noise + license-cost inflation + breaks the principle of
least privilege.

`App\Entity\Person` already exists (originally for `PhysicalAccessLog`)
with `personType IN ('employee', 'contractor', 'visitor', 'vendor',
'auditor', 'consultant', 'other')` and an optional `linkedUser` FK that
attaches a Person to a User account when one exists.

## Decision Rule (Senior-Consultant-Heuristik)

| Field semantic | Target FK | Rationale |
|---|---|---|
| Approval-Chain / Sign-Off / 4-Eyes | `User` (REQUIRED) | Audit-trail demands login + identity. |
| Audit-Trail (uploadedBy / createdBy / processedBy / lockedBy) | `User` (REQUIRED) | System actor identity. |
| Witness / Reporter (system action) | `User` (REQUIRED) | Same. |
| Long-term governance role (Owner / Responsible / DPO / CISO holder) | `Person` (preferred) | May be external. Person.linkedUser may upgrade to User if login granted later. |
| Action-bound assignment (assignedTo a ticket) | `User` (REQUIRED) | Action requires login to act. |
| Crisis-Team membership / Function-Owner / Risk-Owner | `Person` (preferred) | Roster, not action. |

**Migration approach:** Approach A (additive, non-breaking).
Add `<field>_person_id` alongside existing `<field>_id` (User FK).
Keep User FK so existing code keeps working.
Backfill: copy `User.linkedPerson.id` (inverse side) into the new column.
Where no Person exists for a User, leave NULL — admins can create the
Person on demand. After 1-2 release cycles, deprecate the User FK in
favour of the Person column.

## Phase A — In this PR

| Entity | Field added | Status | Notes |
|---|---|---|---|
| `Asset` | `ownerPerson` | EXISTING (Pattern A live) | `ownerUser` + `ownerPerson` + deputy collection. |
| `Risk` | `riskOwnerPerson` | EXISTING (Pattern A live) | `riskOwner` (User) + `riskOwnerPerson` + deputies. |
| `Control` | `responsiblePersonRef` | EXISTING (Pattern A live) | `responsiblePersonUser` + `responsiblePersonRef` + deputies + legacy string. |
| `Document` | `ownerPerson` (NEW) | ADDED in `Version20260509030000_person_owner_rollout` | Governance-side owner. `uploadedBy` (User) STAYS — that's the upload action. |

Backfill in same migration:
- `Document.ownerPerson` populated from `Document.uploadedBy.linkedPerson` where present
  (i.e. if the uploader has a Person profile, treat that as the initial governance owner).

Plus:
- `User.linkedPerson` inverse-side accessor (read-only, OneToOne mappedBy).
- `PersonRepository::findActiveByTenant()`, `findRoleHoldersByTenant()`,
  `findOneByLinkedUserId()`.
- `_fa_person_picker` Twig macro with TomSelect + empty-state.
- Policy-Wizard Step-4 Roles refactored to use Person-Picker (4 roles +
  6 function-owners). Approval-chain stays User (action).
- RolesStep validator accepts integer Person.id with backwards-compat
  fallback: legacy User.id is auto-resolved to its linked Person.id
  when possible.

## Phase B — Verdict matrix for follow-up sprint

Each row lists `<entity>.<field>` → classification → migration plan.

### KEEP as User (audit-trail / approval-action / system-actor)

| FK | Why |
|---|---|
| `Document.uploadedBy` | Upload action |
| `WizardRun.startedByUser` | Start action |
| `WizardSession.user` | Session owner |
| `PolicyAcknowledgement.acknowledgedBy` | Sign-off action |
| `WorkflowInstance.witnessUser`, `WorkflowInstance.startedBy` | Workflow signing actions |
| `AuditLog.user` (where present) | Audit actor |
| `MfaToken.user` | Login-bound |
| `PushSubscription.user` | Login-bound |
| `UserSession.user` | Login-bound |
| `DashboardLayout.user` | Per-user UI state |
| `EntityTag.createdBy` | Author |
| `AlvaHintDismissal.user` | Per-user UI state |
| `SsoUserApproval.{requestedBy, approvedBy}` | Approval actions |
| `FourEyesApprovalRequest.{requester, approver, denier}` | 4-Eyes action |
| `RiskApprovalConfig.createdBy` | Author |
| `IncidentSlaConfig.createdBy` | Author |
| `ScheduledReport.createdBy` | Author |
| `SampleDataImport.importedBy` | Action |
| `ImportSession.{startedBy, finishedBy}` | Action |
| `ChangeRequest.requestedBy` (verify) | Author |
| `AuditFreeze.{frozenBy, unfrozenBy}` | Action |
| `CryptographicOperation.user` | Action |
| `TenantPolicySetting.lastEditedBy`, `TenantPolicySettingChangeAttempt.attemptedBy` | Action |
| `FulfillmentInheritanceLog.{actorUser, ...}` | Action |
| `ComplianceRequirementFulfillment.{lastReviewedBy, attestationOwner?}` | review-action keeps User; attestation-owner may be Person — re-classify |
| `CustomReport.createdBy` | Author |
| `TenantBranding.lastEditedBy` | Author |
| `RiskTreatmentPlan.approvedBy` | Approval |
| `Risk.acceptanceApprovedByUser` | Approval |
| `AuditFinding.{reportedBy, assignee?}` | reportedBy=action; assignee may be Person — re-classify |
| `CorrectiveAction.assignee?` | re-classify (long-term assignment vs action) |
| `PrototypeProtectionAssessment.assessedBy` | Author |
| `Training.assignedBy` | Author |
| `Person.linkedUser` | The link itself |
| `BusinessProcess.processOwner` | re-classify — could be Person (governance) |
| `BusinessContinuityPlan.planOwner` | re-classify — Person preferred |
| `ManagementReview.chairperson` | Person preferred (could be external Board-Member) |
| `CrisisTeam.{members, leader}` | Person preferred (most CT members ARE Persons) |
| `CorporateGovernance.responsibleParty` | Person preferred |
| `RiskAppetite.approvedBy` | KEEP User (approval) |
| `Consent.{capturedBy, withdrawnBy, ...}` | Action — KEEP |
| `DataSubjectRequest.handledBy` | Action — KEEP |
| `DataBreach.dpoNotifiedBy` | Action — KEEP |
| `DataBreach.{reportedBy, assignedTo, ...}` | reportedBy=action KEEP; assignedTo may be Person re-classify |
| `DPIA.{conductedBy, reviewedBy, ...}` | conductedBy could be Person (external DPO); reviewedBy=action KEEP |
| `ProcessingActivity.{controllerContact, dpoContact, ...}` | Person preferred (governance contacts) |
| `ThreatIntelligence.acknowledgedBy` | Action — KEEP |
| `PhysicalAccessLog.recordedBy` | Action — KEEP |
| `DocumentSection.{createdBy, lastEditedBy, lockedBy}` | Action — KEEP |
| `AppliedBaseline.appliedBy` | Action — KEEP |

### MIGRATE to Person (governance / long-term role-holder)

| FK | Migration | Phase | Notes |
|---|---|---|---|
| `Document.ownerPerson` | NEW Person FK | A (this PR) | Governance owner of the policy/document. |
| `BusinessContinuityPlan.planOwnerPerson` | NEW alongside `planOwner` (User) | B | Plan owner can be external BC consultant. |
| `BusinessProcess.processOwnerPerson` | NEW alongside `processOwner` (User) | B | Process owner is governance. |
| `CrisisTeam.members` (Person collection) + `CrisisTeam.leaderPerson` | NEW M2M / FK | B | CT roster is mostly Persons; leader may be external consultant. |
| `ManagementReview.chairpersonRef` | NEW Person FK | B | Board chair often not a system user. |
| `CorporateGovernance.responsiblePartyPerson` | NEW Person FK | B | Governance role-holder. |
| `DataProtectionImpactAssessment.{conductedByPerson, dataProcessorContact}` | NEW Person FKs | B | External DPO/processor contacts. |
| `ProcessingActivity.{controllerContactPerson, dpoContactPerson, processorContactPerson}` | NEW Person FKs | B | External-by-default contacts. |
| `DataBreach.dpoPerson` | covered by `dataProtectionOfficerPerson` (prior work) | B | Same governance DPO concept. |
| `Incident.responsiblePerson` | NEW Person FK alongside legacy `assignedTo` string + `reportedByPerson` | **B2 (live)** | Long-term ownership vs ticket assignment. |
| `AuditFinding.responsiblePerson` | covered by existing `assignedPerson` (prior work) | B | Same governance role-holder concept. |
| `CorrectiveAction.responsiblePerson` | EXISTING (prior work) | B | Pattern A live. |
| `Training.deliveredByPerson` | covered by `trainerPerson` (prior work) | B | External trainers via `trainerPerson`. |
| `ComplianceRequirementFulfillment.attestationOwnerPerson` | NEW Person FK | **B2 (live)** | Attestation responsibility, distinct from day-to-day responsible. |
| `DataSubjectRequest.dpoPerson` | NEW Person FK alongside `assignedTo` (User) + `assignedPerson` | **B2 (live)** | Governance DPO sign-off, distinct from action handler. |

### Already migrated (Pattern A live)

| Entity | User FK | Person FK |
|---|---|---|
| `Asset` | `ownerUser` | `ownerPerson` + `ownerDeputyPersons` |
| `Risk` | `riskOwner` | `riskOwnerPerson` + `riskOwnerDeputyPersons` |
| `Control` | `responsiblePersonUser` | `responsiblePersonRef` + `responsibleDeputyPersons` |

## Phase B sequencing recommendation

1. Sprint B-1: BCM cluster (`BusinessContinuityPlan`, `CrisisTeam`,
   `BusinessProcess`). Highest user-pain — BCM officers + crisis-team
   members are the most-frequent external-role population.
2. Sprint B-2: Privacy cluster (`DataProtectionImpactAssessment`,
   `ProcessingActivity`, `DataBreach`). External DPO is the typical case.
3. Sprint B-3: Incident + Compliance cluster
   (`Incident.responsiblePerson`, `AuditFinding.responsiblePerson`,
   `CorrectiveAction.responsiblePerson`,
   `ComplianceRequirementFulfillment.attestationOwnerPerson`).
4. Sprint B-4: Governance + Training
   (`ManagementReview.chairpersonRef`, `CorporateGovernance`, `Training`).

Each Phase-B sprint follows the same recipe:
- Add `<field>_person_id` FK column with `isTransactional()=false` migration.
- Backfill via `User.linkedPerson` inverse where possible.
- Update entity getters/setters + add `getEffective<Field>()` accessor
  (re-using `App\Service\OwnerResolver`).
- Update controllers/forms/templates to surface Person-Picker alongside
  the User picker.
- Tests: 1 backward-compat test per field.

## Person-Picker UX

`templates/_components/_fa_person_picker.html.twig` macro:
- TomSelect-driven (`data-controller="tom-select"`).
- Renders `<option value="{Person.id}">FullName — JobTitle @ Company [EXT]
  ✓</option>` (✓ = has linkedUser).
- Empty-state alert when tenant has no Persons.
- Single-select default; pass `multiple: true` for picker arrays.

## Out-of-scope reminders

- Do NOT migrate `User.linkedPerson` semantics — that's the link itself.
- Do NOT touch approval-chain in Step-4 — stays User.
- Do NOT drop any User FK column in Phase A or B. Removal is a separate
  Phase C decision after 1-2 release cycles of dual-state operation.
- Crisis-Team M2M to Person is a slightly larger refactor (join-table
  schema change vs simple FK) — keep in B-1 even if it slows the sprint.
