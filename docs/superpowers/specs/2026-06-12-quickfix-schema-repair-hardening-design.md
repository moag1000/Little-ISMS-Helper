# Quick-Fix Schema-Repair Hardening — Design

**Date:** 2026-06-12
**Status:** Approved (scope + 4 design decisions confirmed by user; remaining decisions delegated to implementer)
**Author:** Claude (Opus 4.8)

## Problem

The Quick-Fix operator UI (`/quick-fix`) repairs Doctrine schema/migration drift
without shell access. A source-grounded review against an ideal staged
repair model (full-dump → read-only diagnosis → migration-table reconcile →
canonical `migrate` → additive diff → destructive force, plus a final verdict
gate) surfaced **10 findings**, four of them genuine data-safety holes. The
current implementation is architecturally sound (FK-aware multi-pass reconcile,
idempotent data-repairs, audit-logging, async dispatch) but several safety
guarantees printed in docblocks and UI copy are **not enforced in code**.

## Findings (evidence-grounded)

| ID | Sev | Title | Evidence |
|----|-----|-------|----------|
| QF-1 | CRITICAL | "additive-only / never drops" guarantee not enforced | `SchemaHealthService::applyUpdate()` calls `SchemaTool::getUpdateSchemaSql($metadata)` (`:118`) — ORM 3.6 signature is single-arg `getUpdateSchemaSql(classes)`, **no saveMode**; returns full diff incl. `DROP`. `DESTRUCTIVE_PATTERNS` filtering lives only in `getMaintenanceStatus()`/`getEntityVsDbDrift()` (display). Force route copy (`QuickFixController.php:614`, `index.html.twig:179/252/373`) falsely states "never destroys data / saveMode=true". |
| QF-2 | CRITICAL | `markAllPhantomDiff` code vs docblock divergence | Docblock (`SchemaMaintenanceService.php:471-495`) describes run-in-isolated-plan-then-mark; code (`:535`) marks every pending version blind without running. A genuinely-missing migration gets marked executed → DDL never runs → "unknown column" later. Only guard: operator checkbox. |
| QF-3 | CRITICAL | No verify-before-mark | `markMigrationAsExecuted()` (`:442`) `INSERT`s into `doctrine_migration_versions` with no check that the migration's target table/column actually exists in the live DB. Wrong operator assertion → silent gap (CLAUDE.md Pitfall #6 symptom). |
| QF-4 | HIGH | Dropped FK never restored on re-add failure | `executeStatementFkAware()` step 3 (`SchemaHealthService.php:310`) re-adds FK with no try-catch. If re-add throws, FK is permanently lost → silent referential-integrity regression. |
| QF-5 | HIGH | Partial migration reports full success | `executePendingMigrations()` uses `setAllOrNothing(false)` + `setNoMigrationException(true)` (`:160-161`) but reports `executed = count($plan->getItems())` (`:185`) — counts all planned as executed even on mid-plan failure. |
| QF-6 | HIGH | No pre-mutation snapshot | No DB dump before any mutating action (`apply`/`reconcile`/`force`/`mark-*`). No rollback anchor. |
| QF-7 | MED | No concurrency guard | Two operators hitting mutating routes concurrently → racing DDL. Async jobs increase likelihood. No advisory lock. |
| QF-8 | MED | Two divergent drift sources | Display/verdict uses `SchemaValidator::getUpdateSchemaList()` (`:54`); apply uses `SchemaTool::getUpdateSchemaSql()` (`:118`). Can disagree → operator sees "clean" while apply still mutates, or vice versa. |
| QF-9 | MED | No final verification gate | After repair there is no `up-to-date` + empty-`dump-sql` green/red verdict. Operator cannot confirm "truly clean". |
| QF-10 | LOW | Offending-version guess | `diagnoseMigrationFailure()` assumes `end($items)` is the failing version (`:624`); under non-all-or-nothing the failure may be elsewhere → wrong suggestion. |

## Design Decisions (confirmed)

1. **QF-6 snapshot:** `mysqldump` primary (schema+data → `var/quickfix-snapshots/`),
   `BackupService` logical export as fallback when the binary is absent
   (shared hosting). Graceful-skip + loud warn, never block the repair on a
   missing dump tool.
2. **QF-1 gate:** Default-filter destructive statements out of `applyUpdate`'s
   execution path; execute them only when an explicit `allowDestructive` flag is
   set, which is wired to a new destructive-specific confirmation on the force
   route (analogous to reconcile's `confirm_destructive`). Correct the false
   docblocks + UI copy.
3. **QF-2 fix:** Keep mark-only behaviour (intentional per code comment), add a
   post-mark `getEntityVsDbDrift()` check: if drift appears after marking, not
   all versions were phantom → auto-reconcile (additive) + loud audit warn.
   Align docblock to actual behaviour.
4. **Delivery:** One dedicated git worktree off `origin/main`, one PR, one commit
   per fix group, one consolidated migration if any DDL is needed (none expected).
   Auto-merge on fully-green CI.

## Architecture

No new top-level components. Changes are surgical, inside the existing two
services + one controller + one template + one new small helper:

- **`SchemaSnapshotService`** (new, `src/Service/`) — single responsibility:
  produce a pre-mutation snapshot. `snapshot(string $reason): SnapshotResult`.
  Tries `mysqldump` (parsed from the DBAL connection params), falls back to
  `BackupService`, returns `{path, method: 'mysqldump'|'logical'|'skipped', warning?}`.
  Called by every mutating job before its first write. Depends on: DBAL
  connection params, `BackupService`, a configurable `var/quickfix-snapshots/`
  dir, `AuditLogger`.
- **`SchemaHealthService::applyUpdate(actor, bypassMigrationGate, allowDestructive=false)`**
  — new third param. Partition `$sql` into additive vs destructive via the
  shared `DESTRUCTIVE_PATTERNS` (moved to a shared const/helper so QF-8 unifies
  the source). Execute destructive only when `allowDestructive`. Return the
  skipped-destructive list so the caller can surface it. Wrap FK re-add (step 3)
  in try-catch → on failure: `success=false`, loud audit, return the re-add SQL
  for manual recovery (QF-4).
- **`SchemaMaintenanceService`** —
  - `markMigrationAsExecuted()` gains a pre-INSERT verification: introspect the
    migration's intended effect and confirm the target exists before marking;
    refuse + recommend reconcile when it does not (QF-3).
  - `markAllPhantomDiffMigrationsAsExecuted()` runs a post-loop drift check and
    auto-reconciles additive drift + warns (QF-2); docblock aligned.
  - `executePendingMigrations()` computes `executed` from a `getExecutedMigrations()`
    delta (before/after) instead of `count(plan)` (QF-5); `diagnoseMigrationFailure()`
    identifies the offending version from the executed-delta gap, not `end()` (QF-10).
  - `forceSchemaUpdate()` threads `allowDestructive` through.
  - New `verifyClean(): array{migrations_up_to_date: bool, drift_empty: bool, ok: bool}`
    final gate (QF-9), surfaced on the index page green/red.
- **Concurrency:** a thin `withSchemaLock(callable)` wrapper using MySQL
  `GET_LOCK('quickfix_schema', 0)` / `RELEASE_LOCK` around every mutating
  service entry point; on busy → return a `blocked: 'locked'` result the
  controller maps to a 409 + flash (QF-7).
- **Controller/template:** force route gains destructive-specific confirmation
  + honest copy; index page renders `verifyClean()` verdict + any
  skipped-destructive list.

## QF-3 verification strategy (the nuanced one)

We cannot reliably parse arbitrary `up()` SQL. Pragmatic, safe rule:
mark-as-executed is **only** legitimate when the live schema already satisfies
the migration's end-state. We assert that indirectly: after a candidate mark,
the entity-vs-DB additive drift must NOT grow. Implementation: snapshot
`getEntityVsDbDrift()` count before, perform the mark, re-check; if additive
drift for a table the migration touches appears/grows, the migration was NOT
phantom → roll the metadata INSERT back (DELETE the just-inserted row) and
return a refuse-result recommending `migrate`/`reconcile`. This keeps the rule
behaviour-based (does the schema actually match?) rather than fragile
SQL-parsing.

## Error Handling

- Every mutating path: snapshot first (QF-6), advisory lock (QF-7), then mutate.
- Destructive statements never run without `allowDestructive` (QF-1).
- FK re-add failure is fatal-loud, not swallowed (QF-4).
- Mark operations are reversible within the call (QF-3 rollback).
- All new branches audit-logged consistent with existing `admin.schema.*` events.

## Testing

`KernelTestCase` service-level tests (no HTTP needed for core logic):

- QF-1: `applyUpdate(allowDestructive=false)` skips a synthetic `DROP COLUMN`
  statement; `=true` executes it. Skipped list populated.
- QF-2: mark-all on a DB with one genuinely-missing migration → post-drift
  detected → auto-reconcile invoked + warn audit row present.
- QF-3: `markMigrationAsExecuted` on a version whose column is missing → refused,
  no row inserted; on a present column → marked.
- QF-4: forced FK re-add failure → `success=false` + audit row + re-add SQL
  returned.
- QF-5: simulated partial-plan failure → `executed` reflects the real delta.
- QF-6: snapshot returns `mysqldump` path when binary present; `logical`/`skipped`
  + warning otherwise (env-guarded).
- QF-7: second concurrent call returns `blocked: 'locked'`.
- QF-9: `verifyClean()` true on a synced schema, false with injected drift.
- QF-10: offending-version equals the actually-failed version in a 2-version
  partial-failure fixture.

Existing QuickFix controller/web tests must stay green (the destructive-gate
copy change touches the template — update assertions if any).

## Out of Scope (YAGNI)

- No new mega-UI; index page gains a verdict band + skipped-destructive list only.
- No Messenger-mode-specific changes; snapshot/lock run in-request like the jobs.
- No schema-diff engine replacement; we reuse Doctrine's tools, only unify the
  source (QF-8).
