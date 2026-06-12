<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Aggregates schema-maintenance status (pending Doctrine migrations + schema
 * drift between entity metadata and the live DB) and exposes idempotent
 * apply-actions for both. Powers the admin Data-Repair page.
 *
 * The design keeps {@see SchemaHealthService} unchanged (used by the
 * monitoring page) and decorates it with two extras:
 *
 *  - migration_status — count + names of versions still to execute
 *  - schema_drift     — count + statements + destructive-statement subset
 *
 * Reconcile is delegated back to SchemaHealthService::applyUpdate() so the
 * existing audit-log + bypass-gate semantics remain the single source of
 * truth.
 */
class SchemaMaintenanceService
{
    public function __construct(
        private readonly SchemaHealthService $schemaHealthService,
        private readonly DependencyFactory $migrationsDependencyFactory,
        private readonly AuditLogger $auditLogger,
        private readonly \Doctrine\Persistence\ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * Reset the default ORM EntityManager if it was closed by a prior
     * exception. Used by mark-all-phantom-diff between isolated migration
     * iterations so subsequent persist/flush calls (including audit-log)
     * do not throw EntityManagerClosed.
     */
    private function resetEntityManagerIfClosed(): void
    {
        $em = $this->managerRegistry->getManager();
        if ($em instanceof \Doctrine\ORM\EntityManagerInterface && !$em->isOpen()) {
            $this->managerRegistry->resetManager();
        }
    }

    /**
     * @return array{
     *     migration_status: array{pending: int, names: list<string>},
     *     schema_drift:     array{count: int, statements: list<string>, destructive: list<string>},
     *     entity_drift:     array{count: int, statements: list<string>},
     * }
     */
    public function getMaintenanceStatus(): array
    {
        $pendingNames = $this->collectPendingMigrationNames();

        $validation = $this->schemaHealthService->validate();
        // pending_sql may contain a single '-- ERROR: …' marker when the
        // SchemaValidator throws; treat those as empty drift but surface
        // the marker so the operator sees something is wrong.
        $statements = $validation['pending_sql'];
        $destructive = SchemaHealthService::classifyStatements($statements)['destructive'];

        // Entity-vs-DB drift: additive-only statements (ALTER TABLE ADD /
        // CREATE TABLE IF NOT EXISTS) that would bring the live DB in sync
        // with entity metadata without destroying any data. This is the
        // same source that SchemaExceptionSubscriber uses for phantom-diff
        // detection, but surfaced here independently so the operator can
        // act even when pending and schema_drift both report zero.
        $entityDriftStatements = [];
        try {
            $entityDriftStatements = $this->getEntityVsDbDrift();
        } catch (\Throwable) {
            // Non-fatal — entity_drift.count stays 0.
        }

        return [
            'migration_status' => [
                'pending' => count($pendingNames),
                'names' => $pendingNames,
            ],
            'schema_drift' => [
                'count' => count($statements),
                'statements' => array_values($statements),
                'destructive' => $destructive,
            ],
            'entity_drift' => [
                'count' => count($entityDriftStatements),
                'statements' => $entityDriftStatements,
            ],
        ];
    }

    /**
     * Executes every pending migration in order. No-op when the plan is empty.
     *
     * @return array{success: bool, executed: int, error: ?string}
     */
    public function executePendingMigrations(string $actor = 'system'): array
    {
        $df = $this->migrationsDependencyFactory;

        try {
            $df->getMetadataStorage()->ensureInitialized();

            $latest = $df->getVersionAliasResolver()->resolveVersionAlias('latest');
            $plan = $df->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
        } catch (\Throwable $e) {
            // No migrations to execute / repository empty → not an error.
            $message = $e->getMessage();
            if (
                str_contains($message, 'No migrations')
                || str_contains($message, 'already at')
                || str_contains($message, 'already at the latest')
            ) {
                return ['success' => true, 'executed' => 0, 'error' => null];
            }
            $this->auditLogger->logCustom(
                'admin.schema.migrate.failed',
                'Doctrine',
                null,
                null,
                ['error' => $message],
                sprintf('Schema migrate failed for %s: %s', $actor, $message),
            );
            return ['success' => false, 'executed' => 0, 'error' => $message];
        }

        if ($this->isPlanEmpty($plan)) {
            return ['success' => true, 'executed' => 0, 'error' => null];
        }

        try {
            $config = (new MigratorConfiguration())
                ->setDryRun(false)
                ->setAllOrNothing(false)
                ->setNoMigrationException(true);
            $df->getMigrator()->migrate($plan, $config);
        } catch (\Throwable $e) {
            $diagnosis = $this->diagnoseMigrationFailure($e->getMessage(), $plan);
            $this->auditLogger->logCustom(
                'admin.schema.migrate.failed',
                'Doctrine',
                null,
                null,
                [
                    'error' => $e->getMessage(),
                    'planned' => count($plan->getItems()),
                    'diagnosis' => $diagnosis['category'],
                ],
                sprintf('Schema migrate failed for %s: %s', $actor, $e->getMessage()),
            );
            return [
                'success' => false,
                'executed' => 0,
                'error' => $e->getMessage(),
                'diagnosis' => $diagnosis,
            ];
        }

        $executed = count($plan->getItems());
        $this->auditLogger->logCustom(
            'admin.schema.migrate.applied',
            'Doctrine',
            null,
            null,
            [
                'executed' => $executed,
                'versions' => array_map(
                    static fn (MigrationPlan $p): string => (string) $p->getVersion(),
                    $plan->getItems(),
                ),
            ],
            sprintf('Schema migrate applied by %s (%d versions)', $actor, $executed),
        );

        return ['success' => true, 'executed' => $executed, 'error' => null];
    }

    /**
     * Reconciles entity metadata against the live DB by running the same
     * SQL `app:schema:reconcile` would emit. Delegates to SchemaHealthService
     * so the audit trail + migration-gate stay consistent with the
     * monitoring-page action.
     *
     * @return array{success: bool, executed: int, auto_marked: list<string>, error: ?string, blocked: ?string}
     */
    public function reconcileSchema(string $actor = 'system', bool $bypassMigrationGate = false): array
    {
        $result = $this->schemaHealthService->applyUpdate($actor, $bypassMigrationGate);

        // After a successful reconcile, the live schema matches entity metadata.
        // Any remaining file-system-pending migrations would phantom-diff on
        // their next run (their target state is already applied). Auto-mark
        // them as executed so the operator does not have to click through
        // each one. Idempotent: markMigrationAsExecuted() skips already-executed.
        $autoMarked = [];
        $autoMarkFailed = [];
        if ($result['success']) {
            foreach ($this->listPendingMigrationVersionsFromFileSystem() as $version) {
                $r = $this->markMigrationAsExecuted($version);
                if ($r['success']) {
                    $autoMarked[] = $version;
                } else {
                    $autoMarkFailed[$version] = (string) ($r['error'] ?? 'unknown');
                }
            }
            if ($autoMarkFailed !== []) {
                $this->auditLogger->logCustom(
                    'admin.schema.reconcile.auto_mark_failed',
                    'Doctrine',
                    null,
                    null,
                    [
                        'failed_count' => count($autoMarkFailed),
                        'marked_count' => count($autoMarked),
                        'first_5_errors' => array_slice($autoMarkFailed, 0, 5, true),
                    ],
                    sprintf(
                        'Reconcile auto-mark partial: %d marked, %d failed. First errors: %s',
                        count($autoMarked),
                        count($autoMarkFailed),
                        implode(' | ', array_slice(array_map(
                            fn($v, $e) => "$v: $e",
                            array_keys($autoMarkFailed),
                            array_values($autoMarkFailed),
                        ), 0, 3)),
                    ),
                );
            }
        }

        return [
            'success' => $result['success'],
            'executed' => count($result['executed_sql']),
            'auto_marked' => $autoMarked,
            'auto_mark_failed' => $autoMarkFailed,
            'error' => $result['error'],
            'blocked' => $result['blocked'],
        ];
    }

    /**
     * Nuclear fallback: runs `doctrine:schema:update --force` (saveMode=true)
     * programmatically. Use when reconcile still fails after the FK-check
     * envelope is applied — e.g. complex cross-table constraint ordering that
     * SchemaTool cannot resolve automatically.
     *
     * saveMode=true → SchemaTool only emits ADD/CREATE statements; it never
     * emits DROP TABLE so existing data is never destroyed by this call.
     *
     * @return array{success: bool, statements_executed: int, error: ?string}
     */
    public function forceSchemaUpdate(string $actor = 'system'): array
    {
        // Delegate to applyUpdate's FK-aware envelope which handles
        // errno 1091/1176/1832/1833/1822/150 + multi-pass convergence +
        // phantom-drift detection. Force = bypass migration gate so the
        // operator can recover even with pending migrations.
        $result = $this->schemaHealthService->applyUpdate($actor, bypassMigrationGate: true);

        $statementsCount = count($result['executed_sql']);

        if ($statementsCount === 0 && $result['success']) {
            $this->auditLogger->logCustom(
                'admin.schema.force_update.noop',
                'Doctrine',
                null,
                null,
                ['actor' => $actor],
                sprintf('Schema force-update by %s — no statements needed (schema already in sync)', $actor),
            );
            return ['success' => true, 'statements_executed' => 0, 'error' => null];
        }

        if (!$result['success']) {
            $this->auditLogger->logCustom(
                'admin.schema.force_update.failed',
                'Doctrine',
                null,
                null,
                ['error' => $result['error'], 'sql_count' => $statementsCount, 'actor' => $actor],
                sprintf('Schema force-update FAILED by %s: %s', $actor, (string) $result['error']),
            );
            return ['success' => false, 'statements_executed' => 0, 'error' => $result['error']];
        }

        $this->auditLogger->logCustom(
            'admin.schema.force_update.applied',
            'Doctrine',
            null,
            null,
            ['statements' => $statementsCount, 'actor' => $actor],
            sprintf('Schema force-update applied by %s (%d statement(s), saveMode=true)', $actor, $statementsCount),
        );

        return ['success' => true, 'statements_executed' => $statementsCount, 'error' => null];
    }

    /**
     * Compares Doctrine entity metadata to the live DB schema and returns the
     * SQL statements needed to bring the DB in sync — bypassing the Doctrine
     * migrations table entirely.
     *
     * This is the canonical fallback for the phantom-diff-state case (CLAUDE.md
     * Pitfall #6): migrations are MARKED executed in doctrine_migration_versions
     * but the actual DDL never ran (e.g. legacy PREPARE/EXECUTE pattern). In
     * that state getMaintenanceStatus() reports pending=0 and drift=0 while the
     * live DB is genuinely missing columns or tables.
     *
     * Filters to additive-only statements (ALTER TABLE … ADD / CREATE TABLE);
     * DROP statements are excluded so the caller never accidentally destroys
     * data without explicit operator confirmation.
     *
     * @return list<string> SQL statements that would bring the DB in sync
     */
    public function getEntityVsDbDrift(): array
    {
        $validation = $this->schemaHealthService->validate();

        // Exclude error-marker lines and destructive statements.
        return SchemaHealthService::classifyStatements($validation['pending_sql'])['additive'];
    }

    /**
     * Returns file-system-discovered pending-migration list (same source
     * SchemaHealthService::applyUpdate() gates on). Surfaces the count to
     * the QuickFix UI even when the Doctrine MigrationPlanCalculator
     * reports 0 (file/DB-list discrepancy).
     *
     * @return list<string>
     */
    public function listPendingMigrationVersionsFromFileSystem(): array
    {
        return $this->schemaHealthService->listPendingMigrationVersions();
    }

    /**
     * Marks a migration version as executed in the metadata storage WITHOUT
     * running its up() method. This is the safe recovery path for
     * phantom_diff_migration errors: the schema already has the column/table,
     * so the migration's DDL would fail with "already exists" — marking it
     * executed unblocks the migrations chain.
     *
     * Safety gates applied here:
     * - The version must exist on the file system (i.e. in the migration list)
     * - If the version is already executed, the call is a no-op (idempotent)
     *
     * @return array{success: bool, version: string, error: ?string}
     */
    public function markMigrationAsExecuted(string $version, bool $verify = true): array
    {
        $df = $this->migrationsDependencyFactory;

        try {
            $df->getMetadataStorage()->ensureInitialized();

            // 1. Verify the version exists in the file-system migration list.
            // getMigrations() returns AvailableMigrationsSet; getItems() returns
            // AvailableMigration[] each with getVersion(): Version.
            $rawKnown = [];
            foreach ($df->getMigrationRepository()->getMigrations()->getItems() as $available) {
                $rawKnown[] = (string) $available->getVersion();
            }

            if (!in_array($version, $rawKnown, true)) {
                return [
                    'success' => false,
                    'version' => $version,
                    'error' => sprintf(
                        'Version "%s" not found in migration list. Known: %s',
                        $version,
                        implode(', ', array_slice($rawKnown, 0, 5)),
                    ),
                ];
            }

            // 2. Check it is not already executed (idempotent guard).
            // getExecutedMigrations() returns ExecutedMigrationsList; getItems()
            // returns ExecutedMigration[] each with getVersion(): Version.
            $executedVersions = $df->getMetadataStorage()->getExecutedMigrations();
            $executedStrings = array_map(
                static fn (\Doctrine\Migrations\Metadata\ExecutedMigration $m): string => (string) $m->getVersion(),
                $executedVersions->getItems(),
            );

            if (in_array($version, $executedStrings, true)) {
                $this->auditLogger->logCustom(
                    'admin.schema.force_mark_executed.skipped',
                    'Doctrine',
                    null,
                    null,
                    ['version' => $version],
                    sprintf('force-mark-executed skipped — version already recorded: %s', $version),
                );
                return ['success' => true, 'version' => $version, 'error' => null];
            }

            // 3. Insert directly into doctrine_migration_versions, then verify
            // the schema genuinely matches the migration's end-state. If
            // additive entity-vs-DB drift remains, this version was NOT phantom
            // — undo the metadata row and refuse so the caller runs migrate/reconcile.
            $df->getConnection()->insert('doctrine_migration_versions', [
                'version' => $version,
                'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'execution_time' => 0,
            ]);

            if ($verify) {
                $remainingDrift = $this->getEntityVsDbDrift();
                if ($remainingDrift !== []) {
                    $df->getConnection()->delete('doctrine_migration_versions', ['version' => $version]);
                    $this->auditLogger->logCustom(
                        'admin.schema.force_mark_executed.refused_drift',
                        'Doctrine',
                        null,
                        null,
                        ['version' => $version, 'remaining_drift' => array_slice($remainingDrift, 0, 5)],
                        sprintf('QuickFix: refused to mark %s — %d additive drift statement(s) remain; not phantom. Run migrate/reconcile.', $version, count($remainingDrift)),
                    );
                    return [
                        'success' => false,
                        'version' => $version,
                        'error' => sprintf('Refused: %d additive schema drift statement(s) remain after marking — migration "%s" is not phantom. Run migrate or reconcile instead.', count($remainingDrift), $version),
                    ];
                }
            }

            $this->auditLogger->logCustom(
                'admin.schema.force_mark_executed',
                'Doctrine',
                null,
                null,
                ['version' => $version],
                sprintf('QuickFix: force-mark-executed — version recorded without DDL run: %s', $version),
            );

            return ['success' => true, 'version' => $version, 'error' => null];
        } catch (\Throwable $e) {
            $this->auditLogger->logCustom(
                'admin.schema.force_mark_executed.failed',
                'Doctrine',
                null,
                null,
                ['version' => $version, 'error' => $e->getMessage()],
                sprintf('QuickFix: force-mark-executed FAILED for %s: %s', $version, $e->getMessage()),
            );
            return ['success' => false, 'version' => $version, 'error' => $e->getMessage()];
        }
    }

    /**
     * Records every file-system-pending migration as executed WITHOUT running
     * its DDL (metadata-only INSERT, equivalent to
     * `doctrine:migrations:version --add --all`). Per-version verify is skipped
     * here (verify:false) — instead, after the loop a single residual-drift
     * probe runs an additive-only reconcile to close any gap the operator's
     * "all already applied" assertion missed. Never executes destructive DDL.
     *
     * @return array{
     *     success: bool,
     *     marked: list<string>,
     *     skipped: array<string, string>,
     *     remaining_pending: int,
     *     post_drift_reconciled: bool,
     *     stopped_at_error: null,
     * }
     */
    public function markAllPhantomDiffMigrationsAsExecuted(string $actor = 'system'): array
    {
        /** @var list<string> $marked */
        $marked = [];
        /** @var array<string, string> $skipped */
        $skipped = [];
        $cap = 200;
        $iter = 0;

        try {
            $pending = $this->listPendingMigrationVersionsFromFileSystem();

            if ($pending === []) {
                return [
                    'success' => true,
                    'marked' => [],
                    'skipped' => [],
                    'remaining_pending' => 0,
                    'post_drift_reconciled' => false,
                    'stopped_at_error' => null,
                ];
            }

            // The operator has explicitly confirmed (safety checkbox in the
            // Quick-Fix UI) that EVERY table/column of the pending migrations is
            // already present in the schema — i.e. they are ALL phantom. We
            // therefore RECORD each version as executed via a metadata-only
            // INSERT and never run its DDL.
            //
            // The previous implementation ran each migration and only marked the
            // ones that errored with a phantom-diff (already-exists). On a
            // reconcile-built schema that drifted it badly: old migrations whose
            // DDL "succeeds" (e.g. an ALTER resetting a column to an earlier
            // definition a later migration had changed) were applied for real,
            // leaving the schema out of sync with the entity metadata. Marking
            // without running is the safe, correct behaviour for the operator's
            // "they all already exist" assertion — equivalent to
            // `doctrine:migrations:version --add --all`.
            $this->migrationsDependencyFactory->getMetadataStorage()->ensureInitialized();

            foreach ($pending as $version) {
                if (++$iter > $cap) {
                    break;
                }

                $markResult = $this->markMigrationAsExecuted($version, verify: false);
                if ($markResult['success']) {
                    $marked[] = $version;
                    $this->auditLogger->logCustom(
                        'quick_fix.force_mark_migration_executed',
                        'Migration',
                        null,
                        null,
                        ['version' => $version, 'batch' => 'mark_all_phantom_diff'],
                        sprintf(
                            'QuickFix/mark-all: marked phantom migration as executed (no DDL): %s',
                            $version,
                        ),
                    );
                } else {
                    $this->resetEntityManagerIfClosed();
                    $skipped[$version] = sprintf('[mark-failed] %s', $markResult['error'] ?? 'unknown');
                }
            }
        } catch (\Throwable $e) {
            // Outer-loop setup failure (e.g. DependencyFactory not available).
            $skipped['__setup__'] = sprintf('[setup-failure] %s', $e->getMessage());
        }

        // Post-loop integrity guard: if additive entity-vs-DB drift remains, the
        // operator's "they're all already applied" assertion was wrong for at
        // least one version. Reconcile additively (never destructive) + warn.
        $postDriftReconciled = false;
        $residualDrift = $this->getEntityVsDbDrift();
        if ($residualDrift !== []) {
            $reconcile = $this->schemaHealthService->applyUpdate('quick-fix', true, false);
            $postDriftReconciled = (bool) $reconcile['success'];
            $this->auditLogger->logCustom(
                'admin.schema.mark_all.post_drift_reconcile',
                'Doctrine',
                null,
                null,
                [
                    'residual_drift_count' => count($residualDrift),
                    'residual_drift' => array_slice($residualDrift, 0, 5),
                    'reconcile_success' => $postDriftReconciled,
                ],
                sprintf('QuickFix/mark-all: %d additive drift statement(s) remained after marking — additive reconcile %s.', count($residualDrift), $postDriftReconciled ? 'applied' : 'FAILED'),
            );
        }

        $remainingPending = count($this->listPendingMigrationVersionsFromFileSystem());

        return [
            'success' => $skipped === [],
            'marked' => $marked,
            'skipped' => $skipped,
            'remaining_pending' => $remainingPending,
            'post_drift_reconciled' => $postDriftReconciled,
            'stopped_at_error' => null, // kept for backward-compat; always null in Approach C
        ];
    }

    /**
     * Returns true when the Doctrine/DBAL error message indicates the migration
     * is a phantom-diff: the target column or table already exists in the live
     * schema, so the migration's DDL is redundant and safe to skip.
     */
    private function isPhantomDiffError(string $msg): bool
    {
        return (bool) (
            // Forward phantom-diff: adds something that already exists
            preg_match('/Duplicate column name/i', $msg)
            || preg_match("/Table '[^']+' already exists/i", $msg)
            || preg_match('/SQLSTATE\[42S01\]/', $msg)
            || preg_match('/SQLSTATE\[42S21\]/', $msg)
            // Reverse phantom-diff: drops something that does NOT exist
            // 1091=column, 1051=table, 1086=constraint, 1176=index/key,
            // 3940=check constraint missing.
            || preg_match("/Can't DROP COLUMN/i", $msg)
            || preg_match("/Can't DROP '[^']+'/i", $msg)
            || preg_match("/Key '[^']+' doesn't exist in table/i", $msg)
            || preg_match('/Unknown column .* in/i', $msg)
            || preg_match('/Unknown table/i', $msg)
            || preg_match('/1091|1051|1086|1176|3940/', $msg)
        );
    }

    /**
     * Returns pending migration class names sorted by version. Tolerates a
     * Categorise a Doctrine-Migrations error and emit a human-readable
     * suggestion the operator can act on. Patterns observed in production:
     * phantom diff-migrations (duplicate column/table), missing default
     * values on NOT NULL columns inserted in data migrations, SAVEPOINT
     * collapse when DDL inside a transactional migration commits implicitly.
     *
     * @param MigrationPlanList $plan The plan that was executing when the
     *                                error fired (used to identify the
     *                                offending version).
     * @return array{
     *     category: string,
     *     message: string,
     *     suggested_action: string,
     *     auto_repairable: bool,
     *     offending_version: ?string,
     * }
     */
    private function diagnoseMigrationFailure(string $errorMessage, MigrationPlanList $plan): array
    {
        $offending = null;
        $items = $plan->getItems();
        if ($items !== []) {
            $offending = (string) end($items)->getVersion();
        }

        // Pattern 1: phantom diff-migration retries DDL that's already
        // applied — covers both forward (ADD-existing) and reverse
        // (DROP-non-existing) since both indicate the schema already
        // matches the migration's intended end-state.
        if ($this->isPhantomDiffError($errorMessage)) {
            return [
                'category' => 'phantom_diff_migration',
                'message' => 'Migration tries to add a column or table that already exists in the database.',
                'suggested_action' => $offending !== null
                    ? sprintf('Likely a stale `doctrine:migrations:diff` output. Verify the offending migration (%s) — if a previous migration already applied the change, mark the offending migration as executed without running it: `php bin/console doctrine:migrations:execute --up --no-interaction "%s"` (only after confirming the schema already has the column/table).', $offending, $offending)
                    : 'Likely a stale diff-migration. Compare the offending migration\'s DDL with the live schema and either delete the redundant migration or mark it executed.',
                'auto_repairable' => false,
                'offending_version' => $offending,
            ];
        }

        // Pattern 2: SAVEPOINT collapse / no active transaction
        if (
            preg_match('/SAVEPOINT [A-Z_0-9]+ does not exist/', $errorMessage)
            || preg_match('/There is no active transaction/i', $errorMessage)
        ) {
            return [
                'category' => 'savepoint_collapse',
                'message' => 'A migration ran DDL (CREATE/ALTER/DROP TABLE) inside a transactional migration. MySQL implicitly commits DDL, so the SAVEPOINT Doctrine tracks per migration is gone before the next one starts.',
                'suggested_action' => $offending !== null
                    ? sprintf('Add `public function isTransactional(): bool { return false; }` to migration %s (and any later DDL migration in the same run). See CLAUDE.md Pitfall #6.', $offending)
                    : 'Add `public function isTransactional(): bool { return false; }` to every migration containing DDL.',
                'auto_repairable' => false,
                'offending_version' => $offending,
            ];
        }

        // Pattern 3: NOT NULL column without default value on INSERT
        if (preg_match("/Field '([^']+)' doesn't have a default value/i", $errorMessage, $m)) {
            return [
                'category' => 'missing_default_value',
                'message' => sprintf('Migration INSERT omits a NOT NULL column without default: `%s`.', $m[1]),
                'suggested_action' => sprintf('Either widen the INSERT to set %s explicitly, or add a default to the column via `ALTER TABLE ... MODIFY %s ... DEFAULT ...` in an earlier migration.', $m[1], $m[1]),
                'auto_repairable' => false,
                'offending_version' => $offending,
            ];
        }

        // Pattern 4: foreign-key violation
        if (preg_match('/SQLSTATE\[23000\].*foreign key/i', $errorMessage)) {
            return [
                'category' => 'foreign_key_constraint',
                'message' => 'Migration tries to insert/delete a row that violates a foreign-key constraint.',
                'suggested_action' => 'Run dependent rows first (parent before child for INSERT, child before parent for DELETE). Consider `SET FOREIGN_KEY_CHECKS=0` only for data-migrations that legitimately need it.',
                'auto_repairable' => false,
                'offending_version' => $offending,
            ];
        }

        // Pattern 5: unknown column referenced
        if (preg_match("/Unknown column '([^']+)'/i", $errorMessage, $m)) {
            return [
                'category' => 'unknown_column',
                'message' => sprintf('Migration references column `%s` which is not in the schema.', $m[1]),
                'suggested_action' => 'Additive drift — run `php bin/console app:schema:reconcile` (or trigger via Quick-Fix UI). The runtime SchemaExceptionSubscriber auto-applies additive-only diffs.',
                'auto_repairable' => true,
                'offending_version' => $offending,
            ];
        }

        return [
            'category' => 'unknown',
            'message' => 'Migration failed without matching a known pattern.',
            'suggested_action' => 'Read the full Doctrine error above. Common follow-ups: `app:schema:reconcile --dry-run`, `doctrine:migrations:list`, `doctrine:migrations:status`.',
            'auto_repairable' => false,
            'offending_version' => $offending,
        ];
    }

    /**
     * @return list<string>
     */
    private function collectPendingMigrationNames(): array
    {
        try {
            $latest = $this->migrationsDependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
            $plan = $this->migrationsDependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($latest);
        } catch (\Throwable) {
            return [];
        }

        if ($plan->getDirection() !== Direction::UP) {
            return [];
        }

        return array_map(
            static fn (MigrationPlan $p): string => (string) $p->getVersion(),
            $plan->getItems(),
        );
    }

    private function isPlanEmpty(MigrationPlanList $plan): bool
    {
        return $plan->getItems() === [];
    }
}
