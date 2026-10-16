# Status-Lifecycle — User Guide

## What is a lifecycle?

Every business entity in the ISMS (Document, Risk, Incident, Asset, and more) goes through a defined sequence of statuses — its **lifecycle**. Instead of changing status fields freely, the system enforces a transition matrix: only certain moves are allowed from a given status, and only by users who hold the right role.

The Status-Lifecycle feature makes this visible and actionable in the UI. You see the current status at a glance, you see which next steps are available to you, and you see the full history of every status change that has happened.

**Why does this matter for compliance?**
ISO 27001 Clause 7.5.3 requires an evidence trail for all documented information. Every status transition is automatically recorded in the audit log with: who made the change, when, what the previous status was, what the new status is, which transition was used, and (if applicable) the stated reason. This record cannot be edited or deleted.

---

## The status-pill

On every entity show-page (e.g. `/de/documents/42`) you will find a **status-pill** near the entity title. The pill color encodes the lifecycle stage at a glance:

| Color | Meaning | Example statuses |
|---|---|---|
| Grey (neutral) | Not yet active / early draft | `draft` |
| Blue (info) | Under review / in progress | `in_review`, `in_progress` |
| Green (success) | Approved / completed / achieved | `approved`, `completed`, `achieved` |
| Purple (primary) | Live / published / active | `published`, `active` |
| Muted | No longer active | `archived`, `returned`, `retired` |
| Red (danger) | Permanently removed from flow | `deleted`, `disposed` |

The pill is read-only — clicking it does nothing. To change status, use the **Aktionen** dropdown (see below).

---

## The Aktionen dropdown

Next to the status-pill you will find an **Aktionen** button (or a dropdown chevron on wider screens). It lists every transition your current role is allowed to execute from the entity's current status.

**Example — Document in status `in_review`:**

- Approve (→ `approved`) — available to MANAGER and above
- Request changes (→ `draft`) — available to MANAGER, requires a reason

If a transition is shown but greyed out, one of these prerequisites is not yet met:

- **4-eyes requirement:** A second authorized user must confirm the transition before it executes. The dropdown will show "Warte auf Vier-Augen-Freigabe" and the entity enters a pending state.
- **Module not activated:** The transition requires an optional module (e.g. `privacy` for GDPR-related ProcessingActivity transitions) that is not enabled for your tenant. Contact your admin.
- **Reason required, not yet provided:** Some transitions open a reason-prompt before executing (see below).

Transitions that are not applicable from the current status at all (e.g. "Publish" when status is `draft`) are not shown — they are not hidden, they simply do not exist as options in the current state.

---

## The reason-prompt

Some transitions require a **reason** before they execute — for example, "Request changes" (sending a document back to `draft`) or "Archive". When you click such a transition, a modal appears asking you to enter a short description of why you are making this move.

The reason is:
- **Mandatory** — the transition is rejected if you submit an empty reason.
- **Stored permanently** in the audit log alongside the transition record.
- **Visible** in the Status-History tab to all users with read access to the entity.

Keep reasons factual and brief (one to three sentences). They serve as compliance evidence, not internal notes.

---

## Status-History tab

On every lifecycle-managed entity show-page, a **Status-History** tab (or section) shows the chronological log of all status changes. Each row shows:

- The transition name (e.g. "Approve", "Request changes")
- Previous status → New status
- Who performed the transition (user display name)
- When (date + time, tenant timezone)
- Reason (if one was recorded)

The history is append-only. No entry can be modified or deleted. The newest change appears at the top.

**Compliance note:** This tab is your primary evidence artefact for ISO 27001 Cl. 7.5.3 and GDPR Art. 5(2) accountability audits. Auditors can be granted read access via `ROLE_AUDITOR` without needing write permissions.

---

## Entity-specific lifecycle stages

Different entities have different lifecycle definitions. Here is a quick reference for the most common ones:

### Document
`draft` → `in_review` → `approved` → `published` → `archived`

Publishing requires 4-eyes confirmation from a second Manager. Archiving requires a reason. Archived documents can be restored to `published`.

### Risk
`draft` → `identified` → `assessed` → `treated` → `accepted` → `monitored` → `closed`

Risk transitions are gated by `ROLE_RISK_MANAGER`. "Accepted" status requires the Risk Owner to confirm acceptance (4-eyes if configured). Closing a risk requires a reason and triggers a notification to the Risk Owner.

### Incident
`draft` → `reported` → `in_progress` → `resolved` → `closed`

High/Critical incidents trigger a parallel `WorkflowInstance` for the regulatory escalation chain (ISO 27001 Incident Response workflow). The lifecycle status and the approval-chain workflow run concurrently and are displayed separately.

### Asset
`active` → `in_use` → `returned` → `retired` → `disposed`

The physical-lifecycle has 7 places and 9 transitions. Disposal requires 4-eyes confirmation (two Managers must confirm) and a mandatory reason. The disposal transition is irreversible — no restore is available from `disposed`.

### ProcessingActivity (GDPR module required)
`draft` → `in_review` → `approved` → `published` → `archived`

Identical stage names to Document, but only visible / accessible when the `privacy` module is active for your tenant. The audit trail for ProcessingActivity transitions feeds directly into your GDPR Article 30 Register of Processing Activities evidence package.

### Audit programme
`planning` → `active` → `completed` → `archived`

Archiving an audit programme requires a reason (entered on the programme page) and is recorded in the Status-History.

### Policy section (generated policies)
`draft` / `dpo_sign_off` → `approved`, or → `rejected` → `draft`

Privacy and joint sections of a generated policy need their own sign-off (DPO or CISO) before the host document's approval workflow is released (GDPR Art. 38(3)). These transitions are only available on the section approval page, not through the generic Aktionen dropdown, because the self-approval ban and the approver-role check live there.

### Transfer Impact Assessment (GDPR module required)
`draft` → `assessed`, reopen → `draft`

Reopening an assessed TIA requires DPO or Manager and a reason.

### Threat-Led Penetration Test (DORA module required)
`planned` → `scoping` → `red_team` → `reporting` → `closed`

Closing and cancelling a TLPT require a reason.

### Supplier questionnaire
`draft` → `sent` → `completed` → `reviewed`

"Completed" is set only when the supplier submits the questionnaire through the public link.

### Access review campaign
`open` → `closed`

### Compliance mapping (global catalogue data)
`draft` → `review` → `approved` → `published` → `deprecated`, restore → `published`

Mappings are shared by all organisations. Managers may submit a draft for review or send it back; approving, publishing, deprecating and restoring need ROLE_ADMIN. Organisation overrides do not apply to mappings.

---

## Admin overrides (ROLE_ADMIN only)

Each tenant can customize the lifecycle behavior for their organization without touching code. The admin interface at `/admin/lifecycle-overrides` allows:

- **Role override:** Change which roles are allowed to execute a specific transition. For example, allow ROLE_AUDITOR to trigger "Request changes" on Documents.
- **Reason required:** Toggle whether a reason prompt is shown for a specific transition.
- **4-eyes toggle:** Enable or disable the 4-eyes confirmation requirement for a transition.
- **Module gate:** Change which module key must be active for a transition to be available.

Overrides are **tenant-scoped** — they affect only your tenant and are invisible to other tenants. They take effect immediately without a deployment.

**What admins cannot change:** The list of valid statuses and the allowed from/to wiring of transitions are fixed in the system configuration. If you need a new status stage or a new transition direction (e.g. allowing `approved` → `draft` directly), contact your implementation partner — that requires a code and configuration change.

---

## Bulk status-change

On list pages (e.g. the Documents list), selecting multiple rows via the checkboxes reveals the **bulk action bar** at the bottom of the screen. The "Status ändern" option in that bar applies the same transition to all selected entities in a single operation.

The same role checks, 4-eyes requirements, reason prompts, and module gates apply to bulk transitions as to single-entity transitions. Bulk status changes follow the canonical matrix only (`draft→in_review`, `in_review→approved`, `in_review→draft`, `approved→published`, `published→archived`, `archived→published`); any other target is rejected per entity as "transition not allowed". If any of the selected entities cannot receive the transition (wrong current status, role denied, version conflict), those are reported individually in the result summary — the others succeed.

Bulk transitions are recorded as a **batch** in the audit log: one batch entry with a shared `batch_id` (UUID v4), plus one individual entry per entity. The batch entry counts as a single compliance record; the per-entity entries provide the granular trail.

---

## Troubleshooting

**"Transition X nicht möglich aus Status Y"**
The entity is in a status from which this transition is not wired. Check the Status-History tab to understand how the entity arrived at its current status.

**"Berechtigung fehlt für Transition X"**
Your role does not allow this transition. Ask a user with MANAGER or higher access to perform it, or ask your admin to adjust the role configuration for this transition.

**"Entity wurde geändert — neu laden"**
Another user saved a change to this entity between the time you opened the page and the time you clicked the transition. Reload the page to get the latest version, then try again.

**"Modul 'X' nicht aktiviert"**
This transition requires an optional module that is not currently enabled for your tenant. Your admin can enable it at `/admin/modules`.

**"Begründung erforderlich für Transition X"**
You attempted the transition without providing a reason. Enter a reason in the prompt and resubmit.

---

## Related documentation

- ADR (why we chose Symfony Workflow): `docs/decisions/2026-05-17-lifecycle-state-machine.md`
- Admin override UI: `/admin/lifecycle-overrides` (ROLE_ADMIN required)
- Workflow System (multi-step approval chains): see CLAUDE.md § "Workflow System"
- Module activation: `docs/user-guide/MODULE_AKTIVIERUNG.md`
- Quick Fix operator UI: `docs/user-guide/QUICK_FIX.md`
