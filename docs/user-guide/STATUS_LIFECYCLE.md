# Status-Lifecycle — User Guide

## What is a lifecycle?

Every business entity in the ISMS (Document, Risk, Incident, Asset, and more) goes through a defined sequence of statuses — its **lifecycle**. Instead of changing status fields freely, the system enforces a transition matrix: only certain moves are allowed from a given status, and only by users who hold the right role.

The Status-Lifecycle feature makes this visible and actionable in the UI. You see the current status at a glance, you see which next steps are available to you, and you see the full history of every status change that has happened.

**Why does this matter for compliance?**
ISO 27001 Clause 7.5.3 requires an evidence trail for all documented information. Every status transition is automatically recorded in the audit log with: who made the change, when, what the previous status was, what the new status is, which transition was used, and (if applicable) the stated reason. This record cannot be edited or deleted.

---

## The status-pill

On every entity show-page (e.g. `/de/document/42`) you will find a **status-pill** near the entity title. The pill color encodes the lifecycle stage at a glance:

| Color | Meaning | Example statuses |
|---|---|---|
| Grey (neutral) | Not yet active / early draft | `draft` |
| Brand (primary) | Under review / in progress, live / published / active | `in_review`, `in_progress`, `published`, `active` |
| Green (success) | Approved / completed / achieved | `approved`, `completed`, `achieved` |
| Grey (neutral) | No longer active | `archived`, `returned`, `retired` |
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
`identified` → `assessed` → `in_treatment` → `treated` → `monitored` → `closed`, or `assessed` → `accepted`

Risk transitions are gated by `ROLE_RISK_MANAGER`. "Accept" (CISO or risk manager) needs a reason and four-eyes; who accepted and when is written only by the acceptance approval ("Akzeptanz anfordern" on the risk page; above the manager threshold top management decides). Reopening a closed risk and reverting an acceptance need a reason.

### Incident
`reported` → `in_investigation` → `in_resolution` → `resolved` → `closed`; a false positive goes from `reported` straight to `closed`

Transitions need `ROLE_CISO`; closing needs a reason and four-eyes.

High/Critical incidents trigger a parallel `WorkflowInstance` for the regulatory escalation chain (ISO 27001 Incident Response workflow). The lifecycle status and the approval-chain workflow run concurrently and are displayed separately.

### Asset
`draft` → `active` ⇄ `inactive`, `active` → `in_use` → `returned` → `active`, `active`/`inactive` → `retired` → `disposed`

7 places, 9 transitions. Disposal requires 4-eyes confirmation (two Managers) and a reason, and cannot be undone. An asset can be deleted only once it is `disposed`, or within 24 hours of its creation to correct a mistake (`DeletionPolicy`).

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

Closing and cancelling a TLPT require a reason. The register lives at
*Compliance > DORA > TLPT* (`/tlpt`): managers (CISO, ISB) plan and edit a test,
everybody in the organisation reads it. The actual start and end dates are not
typed in: starting the red-team phase stamps the start, starting the report or
closing stamps the end. A closed or cancelled test is evidence and cannot be
edited; only a test still `planned` can be deleted. Findings of the test are
linked from the audit findings register.

### Supplier questionnaire
`draft` → `sent` → `completed` → `reviewed`

"Completed" is set only when the supplier submits the questionnaire through the public link.

### Access review campaign
`open` → `closed`

Nobody decides on their own access (segregation of duties, ISO 27001 A.5.18): a single decision on an item whose subject is the reviewer is refused, and a bulk decision leaves such items pending and reports how many it skipped.

### Organisation (tenant)
`draft` → `active` ⇄ `suspended` → `terminated` → `archived`

Suspending and reactivating an organisation is done in the "Organisation status" section of the organisation page (SUPER_ADMIN only). Both need a reason and a second super admin as four-eyes approver; if the organisation has no second super admin, document the offline counter-signature in the reason. The organisation edit form has no "active" switch.

### Compliance mapping (global catalogue data)
`draft` → `review` → `approved` → `published` → `deprecated`, restore → `published`

Mappings are shared by all organisations. Managers may submit a draft for review or send it back; approving, publishing, deprecating and restoring need ROLE_ADMIN. Organisation overrides do not apply to mappings.

---

## Who may take a step (S21)

Every status change checks the roles of its transition in one place, the lifecycle service: the roles of the
workflow definition, tightened by your organisation's overrides, through the role hierarchy. Transitions marked
`responsible` are also open to the record's responsible person (objective achieved, data subject request
completed, data breach assessed / reported / closed). It does not matter where the step is triggered — the
**Aktionen** dropdown, a button on the record's page, REST, the assistant (MCP), a background job or an accepted
suggestion. Without the role the step is refused with "Für diesen Schritt fehlt die nötige Rolle" (403).

Not role-checked: steps nobody takes by hand (an objective reaching its target value, auto-progression, the
nightly jobs), steps whose approver an approval chain or the risk-acceptance tier already chose, and a few steps
without roles that only their own feature runs (policy-section sign-off, the supplier answering a questionnaire,
the notification pipeline).

Internal audits: submitting and reworking the report need `ROLE_AUDITOR`, approving, rejecting and closing need
`ROLE_CISO` (four eyes on approval). Who reported, approved and closed — and the rejection reason — are written by
the step itself. Creating and copying an audit is a manager task; editing is open to managers and the lead auditor.

## Evidence is written by the step, not typed in (S12)

Who approved, implemented, verified, closed or reported something — and when — comes from the step that does it.
The edit forms show these fields read-only:

| Record | Field (form, read-only) | How it is set now |
|---|---|---|
| Change request | approved on / by, approval comment | lifecycle step **Approve** (with four eyes the second person; the comment is the decision reason) |
| Change request | implemented on / by | lifecycle step **Implemented** |
| Change request | verified on / by | lifecycle step **Verify** |
| Change request | closed on | lifecycle step **Close** |
| Incident | closed at | lifecycle step **Close** (or *false positive*) |
| Incident | early warning, notification, final and DORA report times | **Mark as reported** in the reporting duties section; the time can be entered afterwards (not in the future, not before detection). Manager or the incident's responsible |
| BC exercise | report completed | derived: exercise completed (lifecycle) **and** results + report date filled in |
| BC exercise | achieved RTO / RPO | editable until the exercise is completed, then frozen |
| BC plan | last tested | completing an exercise that tests the plan; record an earlier test as a completed exercise |
| Supplier | board-level risk acceptance (MaRisk AT 4.3) | supplier page → **Put before top management**; the approval by top management (ROLE_TOP_MGMT) sets it |
| Risk | acceptance reason, valid until | **Request acceptance** (reason and valid-until date, at most 36 months) |
| Risk | residual probability / impact | frozen while the risk is accepted; **Re-evaluate or extend** ends the acceptance and sends a new request to the responsible level |
| Risk | treatment decision approved by / on | risk page → **Approve treatment** (risk owner or manager, ISO 27001 6.1.3 f) |

Still typed in, because typing is the evidence itself: a control's effectiveness test result and date (control owner or
manager), an objective's measured value (its responsible), the authority's name and case number of an incident report.
REST and the assistant (MCP) cannot write any of the fields above either.

## Admin overrides (ROLE_ADMIN only)

Each tenant can customize the lifecycle behavior for their organization without touching code. The admin interface at `/admin/lifecycle-overrides` allows:

- **Role override:** Pick which roles may execute a specific transition (checkboxes; ROLE_USER and the read-only reviewer roles are not offered).
- **Reason required:** Switch the reason prompt on for a transition.
- **4-eyes toggle:** Switch the 4-eyes confirmation on for a transition.
- **Module gate:** Put a transition behind a module where the definition has none.

**Tighten, never loosen (S15).** An override may make a transition stricter, not drop a norm duty:

- transitions decided by top management (`ROLE_TOP_MGMT`, e.g. approving a management decision) or by the data protection officer (`ROLE_DPO`) keep their roles — an organisation admin cannot hand them to ROLE_ADMIN;
- where the definition requires four eyes (asset disposal, document publication, CAPA effectiveness, …) or a reason, the override cannot switch it off;
- a module gate of the definition cannot be replaced.

The page refuses such values with a message, and overrides stored before this rule (free-text roles, four eyes switched off) have no effect any more. The overrides of the **record's** organisation apply — also in background jobs and for holding users acting in another organisation.

Overrides are **tenant-scoped** — they affect only your tenant and are invisible to other tenants. They take effect immediately without a deployment.

Only workflows of records that belong to your organisation can be overridden. Workflows of instance-wide records (organisations, roles, permissions, policy templates, mappings) are not offered; their transitions follow the system configuration.

**Approval chains** (`/admin/workflows`, "Freigabeketten") are shown read-only: steps, approver role and deadline come from the norm definition and are the same for every organisation. Who approves is whoever holds the step's role in your organisation (user management); `owner` means the record's responsible person. Until S15 the page offered per-organisation step overrides that nothing applied; they were removed.

**What admins cannot change:** The list of valid statuses and the allowed from/to wiring of transitions are fixed in the system configuration. If you need a new status stage or a new transition direction (e.g. allowing `approved` → `draft` directly), contact your implementation partner — that requires a code and configuration change.

---

## Bulk status-change

On list pages (e.g. the Documents list), selecting multiple rows via the checkboxes reveals the **bulk action bar** at the bottom of the screen. The "Status ändern" option in that bar applies the same transition to all selected entities in a single operation.

The same role checks, 4-eyes requirements, reason prompts, and module gates apply to bulk transitions as to single-entity transitions. Bulk status changes follow the canonical matrix only (`draft→in_review`, `in_review→approved`, `in_review→draft`, `approved→published`, `published→archived`, `archived→published`); any other target is rejected per entity as "transition not allowed". If any of the selected entities cannot receive the transition (wrong current status, role denied, version conflict), those are reported individually in the result summary — the others succeed.

Bulk transitions are recorded as a **batch** in the audit log: one batch entry with a shared `batch_id` (UUID v4), plus one individual entry per entity. The batch entry counts as a single compliance record; the per-entity entries provide the granular trail. The generic endpoint `POST /lifecycle/{type}/bulk-transition` returns that id as `audit_log_batch_id` (`null` when nothing changed) and processes only records of the organisation you are working in; others are reported as `not_found`.

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
