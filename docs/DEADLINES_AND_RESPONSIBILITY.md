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
