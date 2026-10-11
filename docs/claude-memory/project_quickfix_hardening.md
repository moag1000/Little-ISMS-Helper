---
name: project_quickfix_hardening
description: QuickFix schema-repair hardening — 10 safety findings (QF-1..QF-10) shipped via PR
metadata: 
  node_type: memory
  type: project
  originSessionId: 6854d1c7-c141-43e2-b8fd-bd0170dbdcad
---

QuickFix-Operator-UI (`/quick-fix`, `SchemaMaintenanceService` + `SchemaHealthService`) gegen ideales gestuftes DB-Repair-Modell auditiert → 10 Befunde, alle gefixt + gemerged **PR #927 (2026-06-12)**. Spec/Plan: `docs/superpowers/specs|plans/2026-06-12-quickfix-schema-repair-hardening*`.

Was sich am Verhalten änderte (für künftige Arbeit am QuickFix):
- **QF-1**: `SchemaHealthService::applyUpdate($actor, $bypassMigrationGate, $allowDestructive=false)` — destruktive DDL (DROP) wird per Default zurückgehalten (`skipped_destructive`), nur mit explizitem `allowDestructive` ausgeführt. ORM 3.6 `getUpdateSchemaSql` liefert vollen Diff inkl. DROP — frühere "saveMode/never-drops"-Garantie war FALSCH. Force-Route hat jetzt 2. Checkbox `confirm_destructive_force`.
- **QF-3**: `markMigrationAsExecuted($v, $verify=true)` — INSERT-then-verify-drift-then-rollback. `verify:false` für bulk.
- **QF-2**: `markAllPhantomDiff` = mark-only (verify:false) + EIN post-loop drift-probe → additiver reconcile.
- **QF-7**: alle 4 mutating entry-points über `withSchemaLock` (MySQL GET_LOCK); blocked → `{blocked:'locked'}`.
- **QF-8**: single `SchemaHealthService::classifyStatements()` = source of truth additive/destructive.
- **QF-6**: `SchemaSnapshotService` (mysqldump→logical→skip) vor jeder Mutation; im test-env No-Op (siehe [[feedback_inrequest_runner_failonwarning]]).
- QF-4 (FK-readd fatal-loud), QF-5 (echte executed-delta), QF-9 (`verifyClean()` + Index-Verdict-Band), QF-10 (offending-version = erste nicht-executed).

Methode: brainstorming→writing-plans→subagent-driven-development (frischer Subagent + spec+quality-Review pro Task), eigener worktree `lih-quickfix` off origin/main. 12 TDD-Commits. Reviews fanden je 1 echten Mangel (false docblocks, dropped_fks-key, collateral mark-all-Test, post_drift-key) — Reviews lohnen sich.

**B2-Follow-up (PR #948, 2026-06-13):** Live-Sim (throwaway `app:quickfix-sim` console-cmd gegen Scratch-DB, dev-env) deckte auf: `forceSchemaUpdate(allowDestructive=true)` droppt `doctrine_migration_versions`/`messenger_messages` (Bundle-Tabellen = Orphans für SchemaTool). Fix: `SchemaHealthService::PROTECTED_TABLES` + `isProtectedDrop()` — applyUpdate führt protected-Table-DROP NIE aus, auch nicht mit allowDestructive (audit `protected_table_skipped`, kein Return-Shape-Change). **Falle:** dbal `schema_filter` zum Ausschluss von `doctrine_migration_versions` BRICHT Doctrine-Migrations-Metadata-Storage (introspectet die Tabelle → MetadataStorageError im CI-test-DB-build). Also NICHT filtern — Schutz nur im Code. Lehre: jede Verbesserung erst per Live-Sim gegen Scratch-DB validieren, nicht nur Unit-Tests.
