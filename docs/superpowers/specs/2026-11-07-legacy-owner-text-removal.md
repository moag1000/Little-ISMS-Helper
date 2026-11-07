# Removing the free-text owner columns (2026-11)

Status: done (step 1 in #1123, steps 2–5 in the follow-up PR).

## Why

Owners, responsible persons, contacts, trainers, facilitators, lead auditors and
reporters were once typed as text. Text reaches nobody: no reminder mail, no My Day
entry, no approval routing (`ResponsibilityResolver`). Since the person pickers
(User + Person + deputies) exist, the text columns only kept old values alive.

## The 14 slots

`App\Service\Owner\LegacyOwnerColumns::FIELDS` (entity → old key, User slot, Person slot):

| Entity | Old text | User | Person |
|---|---|---|---|
| Asset | owner | ownerUser | ownerPerson |
| Control | responsiblePerson | responsiblePersonUser | responsiblePersonRef |
| BusinessProcess | processOwner | processOwnerUser | processOwnerPerson |
| BusinessContinuityPlan | planOwner | planOwnerUser | planOwnerPerson |
| BCExercise | facilitator | facilitatorUser | facilitatorPerson |
| Incident | reportedBy | reportedByUser | reportedByPerson |
| InternalAudit | leadAuditor | leadAuditorUser | leadAuditorPerson |
| Training | trainer | trainerUser | trainerPerson |
| ISMSObjective, Location, Vulnerability, Patch | responsiblePerson | responsiblePersonUser | responsiblePersonRef |
| Supplier, InterestedParty | contactPerson | contactPersonUser | contactPersonRef |

Not touched: approval fields (`Risk.acceptanceApprovedBy`, …), free texts without a
person slot (ISMS context, management review — still `LegacyFreeTextField`).

## Steps

1. (#1123) Hide empty legacy fields, admin page "Freitext-Verantwortliche" to assign them.
2. Data migration `Version20261108100000_OwnerTextToPersons`: per record without User
   and Person — exactly one active user of the organisation (e-mail, "first last",
   "last first", "last, first"), else exactly one active person (name or e-mail), else
   a new Person (type `other`, note "Aus Freitext übernommen"), one per organisation
   and name. Pending suggestions of the removed kind `legacy_owner.match` become
   `superseded`.
3. `Version20261108100100_DropLegacyOwnerColumns` drops the 14 columns (non-transactional
   DDL; `down()` re-adds them empty).
4. Code: entities lose property/getter/setter; `OwnerResolver` takes (User, Person);
   forms, templates, field lists, cloners, demo/baseline loaders, search and the
   owner picker lose the legacy branch. Admin page, `LegacyOwnerReport`,
   `LegacyOwnerMatchKind`, `AssetOwnerSyncListener`, `app:migrate:freetext-owners`
   and the Twig extension are removed.
5. Text that still arrives is turned into a reference by
   `App\Service\Owner\OwnerTextResolver` (find = read only, resolve = may create a
   Person, assign = only into empty slots):
   - spreadsheet import: the old column name (`owner`, `trainer`, …) is a
     `TYPE_OWNER` field; the preview shows the match, an unknown name travels as
     `OwnerText` and becomes a Person on commit; explicit User/Person columns win;
   - GSTOOL `<verantwortlich>`;
   - restore of an organisation backup written before the drop (old JSON key).

## Breaking changes

- REST API: the old fields are read-only. `owner`, `responsiblePerson`, `trainer`, …
  still appear in responses and carry the effective name (`#[SerializedName]` on the
  `getEffective*()` accessor); writing them has no effect — write the User/Person IRIs.
  The Asset collection filter `owner` is gone.
- CLI `app:migrate:freetext-owners` and the page `/admin/data-repair/legacy-owners` are gone.

## Operator

Run the two migrations via `/quick-fix` → *Pending-Migrations anwenden*. Afterwards,
link new persons from the migration to user accounts in the person directory where
the person has one.
