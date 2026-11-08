# Fristen und Zuständigkeit (Deadlines and responsibility)

Stand 2026-11-07. One mechanism answers two questions for every duty the tool
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

Sources are tagged `app.deadline_source` automatically and collected by
`DeadlineSourceRegistry`. Sources the action planner may turn into Maßnahmen
implement `App\Service\Planning\Source\SourceAdapter`, which extends
`DeadlineSource`. Inbox-only sources (suggestions, decisions) implement
`DeadlineSource` alone.
Example of a recurring review without a planner step: `App\Service\Register\RegisterReviewSource`
(norm registers, owner → deputies → the register's maintaining persona).

**Adding a norm** means adding sources for its duties. A DE + EN label
`my_day.section.<bucket>` / `my_day.kpi.<bucket>` is required;
`tests/Service/Deadline/DeadlineSourceContractTest.php` checks every source.

## Duty calendar (`App\Service\RecurringDuty`)

Duties the norms repeat but no record carries a date for live in
`RecurringDutyCatalog` (key, module, NIS-2/DORA scope, norm reference, default
interval, fallback roles): risk acceptance criteria (6.1.2), SoA review
(6.1.3 d), key rotation (A.8.24), access
recertification (A.5.18), BIA (ISO 22301 8.2.2), BC exercise (8.5), TOM review
(DSGVO Art. 32), NIS-2 management training (Art. 20) and measures review
(Art. 21), DORA register of information and exit plans (Art. 28).

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
Objectives linked to a KPI are measured by the tool and carry no duty. (Until
P8 this was one catalogue duty for all objectives; it is gone so nobody is
reminded twice.)

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
4. **Admins**: the last resort, so a duty never reaches nobody.

```php
return Responsibility::holders($risk->getRiskOwner(), $risk->getRiskOwnerPerson())
    ->withDeputies(...$risk->getRiskOwnerDeputyPersons())
    ->orRoles('ROLE_RISK_MANAGER');
```

API: `resolve()`, `isResponsible()`, `responsibleFor($record)`,
`usersReaching()` (all with hierarchy, admins included), `usersHolding()`
(without mere admin inheritance).

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
15 minutes. There is no reminder command per duty any more.

- **Daily digest**: one mail per person with everything in their My Day list
  that is due within the source's lead days or overdue. It goes out at the
  organisation's hour (default 07:00, Admin → Organisation → Einstellungen →
  Standards, stored in `notificationPreferences.digest.hour` via
  `ReminderSettings`). Each person can switch it off in the profile
  (`users.reminder_digest_enabled`).
- **Immediate notice**: duties with an hour deadline (`DeadlineProfile::$urgentHours`,
  e.g. reporting duties) are mailed as soon as they become urgent, regardless
  of the digest setting.
- Every sent duty is stored as an `ObligationNotice` (unique per user and
  key), which keeps a duty from being mailed twice and fills the personal
  "Meine Erinnerungen" list in the bell and the notification centre. Notices
  older than 90 days are purged.

Rule-based notifications (`NotificationRule`, webhooks) stay separate; they
react to events, not to deadlines.
