# Migrations

## "Executed Unavailable" warnings on existing installs

Running `php bin/console doctrine:migrations:status` may report ~46
**Executed Unavailable** migrations on databases that pre-date commit
`791ad4c4` (April 2026). This is **expected and harmless**.

### Background

Before April 2026 the project shipped 87 incremental migrations covering
the schema evolution from initial release. Commit `791ad4c4`
("chore(migrations): remove legacy/-folder from git — clean slate")
consolidated all of them into a single migration
`Version20260424150000.php` so that **fresh installs** apply one well-tested
migration instead of replaying 87 historical ones.

Existing databases still carry the original 87 entries in
`doctrine_migration_versions` — Doctrine flags those whose files are no
longer on disk as "Executed Unavailable". The schema is correct; only the
metadata table holds historical references.

### Why we do not clean up

Running `doctrine:migrations:rollup` would clear the metadata table and
re-stamp the consolidated migration as the only executed entry. We
deliberately do **not** do this:

- **Audit trail** (ISO 27001 / BSI C5): the metadata table is a record of
  schema changes applied to production. Wiping it removes audit evidence.
- **Idempotency risk**: if rollup is run on a database that has not yet
  applied the consolidated migration, the consolidated migration is
  marked as executed without actually running, leaving the schema in an
  unknown state.

### What to do

Nothing. The warning is informational. Fresh installs see only the
current set of files; existing installs keep their history. Both are in
the same end state.

If a future cleanup ever becomes necessary — e.g. switching to a
different migration tool — coordinate it with a fresh schema dump and
verified test restore, not with `rollup`.
