# Person-Rollout Audit (User → Person FK Migration)

**Status (2026-05-25):** Phase A live. Phase B1 (BCM cluster) + B2
(Privacy + Incident + Audit narrow-scope) live via
`Version20260509050000_person_rollout_b2_privacy_incident`. **Phase B3
+ B4 still open.**

Phase-A/B1/B2 details previously documented here have been retired —
see git history for the original 200-line plan and the
`person_rollout_b2` migration for the live-as-of-now state.

## Decision Rule (Senior-Consultant-Heuristik)

| Field semantic | Target FK | Rationale |
|---|---|---|
| Approval-Chain / Sign-Off / 4-Eyes | `User` (REQUIRED) | Audit-trail demands login + identity. |
| Audit-Trail (uploadedBy / createdBy / processedBy / lockedBy) | `User` (REQUIRED) | System actor identity. |
| Witness / Reporter (system action) | `User` (REQUIRED) | Same. |
| Long-term governance role (Owner / Responsible / DPO / CISO holder) | `Person` (preferred) | May be external. `Person.linkedUser` upgrades to User if login granted later. |
| Action-bound assignment (assignedTo a ticket) | `User` (REQUIRED) | Action requires login. |
| Crisis-Team membership / Function-Owner / Risk-Owner | `Person` (preferred) | Roster, not action. |

**Migration approach:** Approach A (additive, non-breaking) — add
`<field>_person_id` alongside existing `<field>_id` (User FK), backfill
via `User.linkedPerson`, keep User FK for one to two release cycles
before deprecation.

## Phase B3 — Incident + Compliance long-tail (open)

| Entity.field | Migration |
|---|---|
| `AuditFinding.responsiblePerson` | NEW Person FK (verify if already covered by `assignedPerson`) |
| `CorrectiveAction.responsiblePerson` | NEW Person FK (re-classify long-term ownership vs current assignee) |

## Phase B4 — Governance + Training (open)

| Entity.field | Migration |
|---|---|
| `ManagementReview.chairpersonRef` | NEW Person FK (board-chair often external) |
| `CorporateGovernance.responsiblePartyPerson` | NEW Person FK |
| `Training.deliveredByPerson` | Verify if already covered by `trainerPerson`; otherwise NEW Person FK |

## Recipe per Phase-B sprint

- Add `<field>_person_id` FK column with `isTransactional()=false` migration.
- Backfill via `User.linkedPerson` inverse side.
- Update entity getters/setters + add `getEffective<Field>()` accessor (re-using `App\Service\OwnerResolver`).
- Update controllers/forms/templates to surface Person-Picker alongside the existing User-Picker.
- Update `templates/*/show.html.twig` to render the effective value via the OwnerResolver helper.

## Person-Picker UX (reference)

Use the canonical `_fa_person_picker.html.twig` macro — supports
`linkedUser`-resolution, person-create-modal, and external-role
indicator chip. The existing `OwnerPickerFormTrait` already wires the
`<entity>OwnerPerson` field consistently.

## Out-of-scope reminders

- Do NOT touch system-actor User FKs (uploadedBy / approvedBy /
  acknowledgedBy / etc.) — they remain User-only.
- Do NOT migrate `MfaToken.user`, `UserSession.user`,
  `PushSubscription.user`, `DashboardLayout.user` — these are
  login-bound by definition.
- 4-Eyes approval flows stay User-only.
- Crisis-Team M2M to Person is a slightly larger refactor (join-table
  schema change vs simple FK) — covered in Phase B1 already.
