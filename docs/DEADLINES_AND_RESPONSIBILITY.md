# Fristen und Zuständigkeit (Deadlines and responsibility)

One mechanism answers two questions for every duty the tool
reminds people of: **what is due when** and **who has to act**.

## Deadline sources (`App\Service\Deadline\DeadlineSource`)

Every kind of duty with a deadline is one source: risk review, expiring risk
acceptance, finding, corrective action, document review, DPIA review,
supplier reassessment, approvals, … A source answers:

| Method | Answer |
|---|---|
| `findOpen(Tenant)` | the tenant's records of this kind |
| `dueDateOf()` / `isCompleted()` / `titleOf()` | the deadline, done or not, a title |
| `responsibleOf()` | a `Responsibility` (see below) |
| `linkOf()` | the record's page (`RecordLink`) |
| `profile()` | `DeadlineProfile`: My Day bucket, lead days, norm reference, decision flag, hour deadline |

**Deadline types as configuration** (`config/deadline_types.yaml`). Most
duties have the same shape: one date field of one tenant-owned entity, a
"done" condition, holders on the record, fallback roles, a page, a My Day
bucket, lead days, a norm. These are entries in `config/deadline_types.yaml`,
not classes. One generic `App\Service\Deadline\Configured\ConfiguredDeadlineSource`
reads each entry (`DeadlineTypeDefinition`); `DeadlineSourceRegistry` adds them
after the class sources. 28 types live there (risk review, risk acceptance
without expiry, treatment plan, the three control dates, objectives, context
review, interested-party communication, CAPA effectiveness, certificates,
evidence re-verification, access review, access end of a person, supplier
reassessment and contract end, patch deadline, BC plan review and test,
crisis-team training, VVT and DPIA review, consent expiry, TISAX prototype
protection, team validity, nonconformity correction date of an audit finding
(ISO 27001 10.2 c), TIA re-evaluation (GDPR Art. 46, EDPB 01/2020; owner and
date on the TIA, one year after the assessment by default), fulfilment review
of a requirement (`fulfillment_review`, ISO 27001 9.1: `nextReviewDate`, set by
certificates and by "Prüfung bestätigen" on the requirement page; the
requirement's responsible person, else compliance manager and ISB)).

```yaml
consent_expiry:
    label: Einwilligung läuft ab
    entity: App\Entity\Consent
    module: privacy
    due: expiresAt                      # property path; ~ = open without a date
    done: "item.getStatus() != 'active' or item.isRevoked() or item.getWithdrawnAt() != null"
    title: "words(item.getProcessingActivity()?.getName(), '#' ~ item.getId())"
    holders: [contactPersonUser]        # User/Person on the record (collections allowed)
    deputies: [contactDeputyPersons]
    roles: [ROLE_DPO]                   # fallback
    inform: [ROLE_ISB]                  # ROLE_* or property path, self = the record
    link: app_consent_show              # gets {id}; link_id: false for an index page,
                                        # link_id: requirement.id for a related record's page
    bucket: consents_expiring
    lead_days: 30                       # default 14; ~ = while open
    norm: DSGVO Art. 7
```

`done` and `title` are Symfony expressions over `item` (`words(a, b, …)` joins
the non-empty parts). A new duty of this shape is one entry plus the My Day
labels; `tests/Service/Deadline/Configured/DeadlineTypesConfigTest.php` checks
entity, getters, expressions, labels and slug, the controller test checks the
route. Duties with logic of their own stay classes: computed dates (training
refresh, audit programme follow-up, TLPT, DORA exit plan, NIS-2 registration,
management training), several records or rules across records (Maßnahmen,
change approval, mapping validity, retention, reporting duties, objective
measurement with its conditional owner) and every planner adapter.

Admin > Benachrichtigungen > **Fristtypen** (`/admin/deadline-types`, read-only)
lists every type — configured and programmed — with norm, date field, lead
time, who acts and module.

Sources are tagged `app.deadline_source` automatically and collected by
`DeadlineSourceRegistry`. Sources the action planner may turn into Maßnahmen
implement `App\Service\Planning\Source\SourceAdapter`, which extends
`DeadlineSource`. Inbox-only sources (suggestions, decisions) implement
`DeadlineSource` alone.
Example of a recurring review without a planner step: `App\Service\Register\RegisterReviewSource`
(norm registers, owner → deputies → the register's maintaining persona).

A duty without a date that stays open while a condition holds: `CompetenceGapSource` (ISO 27001
7.2 — an ISMS role whose holder lacks a required competence; holder acts, ISB informed). The same
sources answer ISO 27001 8.1 and 9.1 in the compliance wizard (`deadline_adherence`: share of open
duties not overdue).

**Every date field that carries a duty has a source**:
besides the reviews and approvals there are consent expiry (`consent_expiry`,
DPO), team validity (`team_validity`, team lead), end of a person's access
(`person_access_expiry`, admins, the person informed), patch deadline
(`patch_deployment`, responsible person), supplier contract end
(`supplier_contract_end`, 90 days), crisis-team training
(`crisis_team_training`, team lead → deputies → BCM), TISAX prototype
protection (`prototype_protection_reassessment`, assessor) — all entries in
`config/deadline_types.yaml` — own mappings'
validity (`MappingValiditySource`, only mappings of uploaded requirements),
TLPT (`ThreatLedPenetrationTestSource`: planned end of a running test, else
three years after the last one), DORA exit-plan test (`DoraExitPlanTestSource`:
yearly, untested plans three months after creation), the retention sweep's
deletions within 14 days (`RetentionDeletionSource`, DPO/admins, due at the
next Sunday 04:00 sweep) and Maßnahmen (`ActionItemDueSource`; tasks from a
management review and Maßnahmen converted from another source are left out,
that source reminds already). Missing or expiring standing approvals of top
management (policy, risk criteria, SoA, objectives, resources, NIS-2 measures)
reach the ISB through `StandingApprovalSource` — the board panel reads the same
list. Multi-tenant user assignments (`UserTenantAssignment.validTo`) get a
source when that entity goes live.

### Retention sweep: end states only

`RetentionEnforcementService` (Sundays 04:00, `app:enforce-retention --force`)
deletes a record past its retention period only when it is in an end state
(`RetentionEnforcementService::END_STATES`): asset `disposed`, risk `closed`,
incident `closed`, audit finding `closed`, document `archived`/`deleted`,
processing activity `archived`, workflow instance `approved`/`rejected`/`cancelled`.
A `retired` asset is kept because `dispose` needs four-eyes approval; `accepted`
risks stay under periodic review. Expired records that are still active are not
touched and not transitioned automatically; the run report notes "N overdue for
retention, still active (not deleted)" so a person decides. The sweep runs per
tenant with the tenant in every query and writes one `logBulk()` batch per
tenant and type, stamped with that tenant (`AuditLogger::withTenant()`).

One record belongs to one source at a time: a change request waiting for
approval to `ChangeApprovalSource`, afterwards to `ChangeRequestAdapter`;
both decide `supports()` by status.

**NIS-2 registration** (`Nis2RegistrationSource`): with the NIS-2 module an
organisation is due at once, also before anyone opened the registration page
(the profile is built on the spot). A registration date recorded on the
organisation counts as the last report; "Als gemeldet markieren" stores the
date on the organisation too and moves the next confirmation a year ahead.
Organisations classified as not regulated have no such duty. The
classification lives on the organisation only (`Tenant.nis2Classification`); the
registration profile reads and writes it, and `FrameworkApplicabilityService::nis2Category()`
suggests it from sector, staff and turnover (P7).

**NIS-2 management training** (`ManagementTrainingSource`, Art. 20 (2)): every
holder of ROLE_TOP_MGMT in an NIS-2 regulated organisation is due twelve months
after their last completed training (at once if never trained), the ISB/CISO
informed. The compliance check for Art. 20 (2) counts the same people
(`training_participation` with `roles`).

**Statutory reports on an incident** (`ReportingDutySource`, `ReportingPlanner`):
besides the NIS-2 24 h / 72 h / one-month and DORA reports, an intermediate report
the authority requests (Art. 23 (4) (c)) becomes a duty once the request is
recorded on the incident ("Zwischenbericht angefordert", due date given or 72 h).
The NIS-2 significance thresholds (financial loss, affected persons) are set per
organisation under Admin > Incident-SLAs. DORA reports export as JSON per phase
(`DoraIncidentReportExport`, Art. 19 / RTS 2025/302).

**Next management review** (`ManagementReviewFollowUpSource`, ISO 27001 9.3): once
the organisation's newest review is completed, the next one is due at its
`nextReviewDate` (twelve months after the review when empty) until a later review
is planned — a class, because "is there a later review" spans records.

**NIS-2 registration changes** (`Nis2RegistrationSource`, Art. 3 (4), Art. 27, BSIG § 33):
editing reported registration data (organisation, classification, contacts)
notes the first change; the duty is then due two weeks later instead of at the
yearly confirmation. "Als gemeldet markieren" clears it, reverting the data too.

**Adding a norm** means adding sources for its duties — an entry in
`config/deadline_types.yaml` where the duty is a date field, a class otherwise.
A DE + EN label `my_day.section.<bucket>` / `my_day.kpi.<bucket>` is required;
`tests/Service/Deadline/DeadlineSourceContractTest.php` checks every class source,
`tests/Service/Deadline/Configured/DeadlineTypesConfigTest.php` every entry.

## Duty calendar (`App\Service\RecurringDuty`)

Duties the norms repeat but no record carries a date for live in
`RecurringDutyCatalog` (key, module, NIS-2/DORA scope, norm reference, default
interval, fallback roles): risk acceptance criteria (6.1.2), SoA review
(6.1.3 d), key rotation (A.8.24), access
recertification (A.5.18), BIA (ISO 22301 8.2.2), BC exercise (8.5), TOM review
(DSGVO Art. 32), NIS-2 measures review (Art. 21), DORA register of information and exit plans (Art. 28).

`RecurringDutyService::applicable()` seeds one `RecurringDuty` row per
organisation the first time a duty applies (module active, organisation in
scope; first due one month later) and hides rows whose module is off.
`RecurringDutyAdapter` is the deadline source (bucket `recurring_duties`,
30 days lead, `ownsRecurrence`). "Erledigt" (`/recurring-duties/{id}/done`, the
responsible person or a manager) stores date, evidence document and note and
moves `nextDueAt` by the interval; managers set interval, owner and due date
per organisation. Both are audit-logged. Duties with a date on a record get a
normal source instead — a catalogue entry for them would remind twice.

Cadence words stored on records (`AuditProgram.frequency`,
`InterestedParty.communicationFrequency`, `ISMSObjective.measurementFrequency`)
are read as months only through `App\Util\Cadence`: the next communication
falls back to last communication + frequency, a completed audit programme
with a frequency asks for its successor, and every ISMS objective measured by
hand is due again one cadence after its last measurement
(`ObjectiveMeasurementSource`, ISO 27001 6.2 b / 9.1; `ISMSObjective::getNextMeasurementDue()`).
The measuring `Person` receives it, else the objective's owner; a changed current
value or "Heute gemessen, Wert unverändert" on the objective sets `lastMeasuredAt`.
Objectives linked to a KPI are measured by the tool and carry no duty.

## Responsibility (`App\Service\Responsibility\ResponsibilityResolver`)

The resolver turns a `Responsibility` into user accounts, in a fixed order.
The first step that yields an active account of the tenant wins:

0. **Other record** (`Responsibility::ofRecord($risk)`): whoever is responsible
   for that record. A workflow step with `approver_role: owner` goes to the
   risk or document owner.
1. **Holders on the record**: owner, assignee, named approver. A `Person`
   counts through its linked user account.
2. **Deputies**: used only when no holder has an active account.
3. **Fallback roles**: users who hold the role themselves or through a
   non-admin role. An admin who inherits ROLE_ISB is not the ISB.
   `->thenRoles('ROLE_MANAGER')` names a wider circle asked only when nobody
   holds the first roles (a risk acceptance on the manager level: risk manager,
   CISO or ISB, else every manager of the organisation).
4. **Admins**: the last resort, so a duty never reaches nobody — unless only
   the role may discharge it (`->withoutAdminFallback()`): a board decision
   or an acceptance on the executive level then waits for top management;
   admins get the duty "Keine Leitung zugewiesen" instead of a decision that
   would answer 403.

A record several sources know (a supplier with assessment and contract end,
a risk with review and acceptance expiry) is the responsibility of everybody
the sources name on the strongest step any of them reaches (`responsibleFor()`,
`RECORD_RESPONSIBLE`): two holders both count, a source that only names a
fallback role does not widen an owner step; the order in which sources are
registered does not matter.

```php
return Responsibility::holders($risk->getRiskOwner(), $risk->getRiskOwnerPerson())
    ->withDeputies(...$risk->getRiskOwnerDeputyPersons())
    ->orRoles('ROLE_RISK_MANAGER');
```

API: `resolve()`, `isResponsible()`, `responsibleFor($record)`,
`usersReaching()` (all with hierarchy, admins included), `usersHolding()`
(without mere admin inheritance), `recipients()`.

**Also inform** (`->alsoInform('ROLE_ISB', $person)`): people or role holders
who are told about a duty without acting on it. `recipients()` = who acts plus
them; My Day and the reminder mails use it, decisions (`resolve()`,
`isResponsible()`) never stop at them. Used for: the ISB/CISO on every
statutory report (also when the incident has an owner), top management on an
upcoming management review, the person whose access ends.

## Act where reminded (`RecordResponsibleVoter`)

Whoever My Day or the reminder mail sends to a record may act on it there.
The voter attribute `RECORD_RESPONSIBLE` asks the resolver
(`isResponsibleFor($user, $record)`, cached per request); actions a role may do
anyway accept "role OR responsible":

```php
#[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'dsr')]
```

- `MANAGER_OR_RESPONSIBLE` — decisions and status actions (DSR, data breach,
  management review task, risk "Akzeptanz anfordern", four-eyes approval,
  audit programme and prototype-protection edit).
- `AUDITOR_OR_RESPONSIBLE` — read pages normally for reviewers (audit programme).
- `EDIT_OR_RESPONSIBLE` — records everyone may edit (Maßnahmen, teams): the
  `edit` voter, read-only reviewer roles only when responsible.
- `RiskVoter`, `ControlVoter` and `Nis2RegistrationProfileVoter` answer EDIT
  (VIEW for NIS-2) the same way: manager scope, the permission, or responsible.
  A plain user edits only their own risks, not every risk of the organisation.

`ReadOnlyRoleSubscriber` lets such actions through for reviewer roles (top
management named on a task marks it done); the voter has checked the
assignment.

**Nobody takes responsibility for themselves (S20).** Because responsibility is
a right, a person without `ROLE_MANAGER` cannot make themselves responsible for
an existing record: `App\EventListener\SelfAssignmentGuard` (onFlush, so web
form, REST, MCP `save_record` and imports alike) refuses a change of a
User/Person reference after which the resolver names them and before which it
did not (`App\Service\Responsibility\SelfAssignmentCheck`; the web form shows
it as an error via `SelfAssignmentFormExtension`). The current holder may hand
the record on, creating a record with oneself as owner stays allowed, and
`Person.linkedUser` — the account a Person acts through — is set only by a
manager. `tests/Contract/ResponsibleSlotWriteContractTest` finds the slots
itself from the deadline sources; a new slot needs no list entry.

**Permanent check:** `tests/Service/Deadline/DeadlineLinkReachabilityTest.php`
runs end to end: for every source it creates an organisation
with only the source's modules (`requiredModule()`, `module:` in
`deadline_types.yaml`), one account per role of `responsibleOf()` plus a plain
user and top management as record holders, and a record that
`ResponsibilityResolver::recipients()` really sends to that account. It then
opens the source's link as that account, follows redirects, and fails on any
answer other than 200 or a landing on dashboard, login, MFA, setup or a
"module not active" page. Since W6 (wave 8) every source has a page (DORA TLPT:
`/tlpt`, `WITHOUT_LINK` is empty), and for review duties (`MAIN_ACTION`:
records of processing, DPIA, TIA, risk and control reviews) the test also
submits the page's main form ("Review abschließen") as that person and fails
when it answers 403 or lands elsewhere. A new source whose link the reminded
person cannot open, or whose main action they cannot take, fails CI.

Two duties live here because nothing else carried them:
`RiskAcceptanceRequestSource` (a requested risk acceptance, to the approval
level's role — risk personas or top management — so it reaches mail and MCP
`my_day`) and `BoardUnassignedSource` (no account holds ROLE_TOP_MGMT: admins
act, ISB and CISO are informed; link = decision register with the role hint).

Persona questions (landing page, dashboard, tour, downloads) stay with
`PersonaResolver`; the responsibility resolver builds on it for role checks.

## Workflow steps owned by the record

A regulatory workflow step with `approver_role: owner` is approved by whoever
is responsible for the workflow's record (risk owner, control owner, data
owner), resolved through `Responsibility::ofRecord()`; without an owner it
falls back to ROLE_MANAGER. Gate 82 (`check_workflow_roles.py`) accepts
`owner` next to the roles defined in `security.yaml`. The steps "Risk Owner
Approval", "Risk Owner Certification" and "DPIA Creation (Data Owner)" use it.

`UserRepository::findByRoleInTenant()` returns users who reach a role through
the hierarchy over their own non-admin roles — admins only when an admin role
is asked for. Reminders and approvals still go through the resolver.

## Reminders (`App\Service\Reminder\ObligationReminderService`)

One path sends every reminder: `app:send-reminders`, run by the scheduler every
15 minutes. There is no reminder command per duty.

- **Daily digest**: one mail per person with everything in their My Day list
  that is due within the source's lead days or overdue. It goes out at the
  organisation's hour (default 07:00, Admin → Organisation → Einstellungen →
  Standards, stored in `notificationPreferences.digest.hour` via
  `ReminderSettings`). Each person can switch it off in the profile
  (`users.reminder_digest_enabled`).
- **Immediate notice**: duties with an hour deadline (`DeadlineProfile::$urgentHours`,
  e.g. reporting duties) are mailed as soon as they become urgent, regardless
  of the digest setting. Data-subject requests use it too: 72 hours before the
  answer is due (DSGVO Art. 12 (3)).
- Every sent duty is stored as an `ObligationNotice` (unique per user and
  key), which keeps a duty from being mailed twice and fills the personal
  "Meine Erinnerungen" list in the bell and the notification centre. Notices
  older than 90 days are purged.

Rule-based notifications (`NotificationRule`, webhooks) stay separate; they
react to events, not to deadlines. Overdue control reviews are a deadline
source, not a rule event; the SLA watcher sends no "approaching" notices for
statutory reports of incidents and breaches, it only marks missed clocks. Rules that forward hour deadlines listen to
`obligation.urgent`.
