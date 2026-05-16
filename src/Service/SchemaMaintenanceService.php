<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;

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
    /** Patterns the SchemaTool emits when it would drop tables/columns. */
    private const DESTRUCTIVE_PATTERNS = [
        '/^DROP TABLE/i',
        '/^ALTER TABLE .+ DROP /i',
    ];

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
     *     schema_drift:     array{count: int, statements: list<string>, destructive: list<string>}
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
        $destructive = array_values(array_filter(
            $statements,
            static function (string $sql): bool {
                foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $sql) === 1) {
                        return true;
                    }
                }
                return false;
            },
        ));

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
     * @return array{success: bool, executed: int, error: ?string, blocked: ?string}
     */
    public function reconcileSchema(string $actor = 'system', bool $bypassMigrationGate = false): array
    {
        $result = $this->schemaHealthService->applyUpdate($actor, $bypassMigrationGate);

        return [
            'success' => $result['success'],
            'executed' => count($result['executed_sql']),
            'error' => $result['error'],
            'blocked' => $result['blocked'],
        ];
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
        $statements = $validation['pending_sql'];

        // Exclude error-marker lines and destructive statements.
        return array_values(array_filter(
            $statements,
            static function (string $sql): bool {
                if (str_starts_with($sql, '-- ERROR:')) {
                    return false;
                }
                foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $sql) === 1) {
                        return false;
                    }
                }
                return true;
            },
        ));
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
    public function markMigrationAsExecuted(string $version): array
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

            // 3. Insert directly into doctrine_migration_versions.
            // TableMetadataStorage::complete() requires a running MigrationResult
            // object, only constructible during an actual migration run. Direct
            // INSERT via the platform connection is equivalent to what the CLI
            // `doctrine:migrations:execute --up` does internally.
            $df->getConnection()->insert('doctrine_migration_versions', [
                'version' => $version,
                'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'execution_time' => 0,
            ]);

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
     * Runs each pending migration in an INDEPENDENT single-version plan
     * (Approach C). A failure in version N does NOT abort the loop — all
     * remaining versions are attempted.
     *
     * Per-version outcome:
     *  - Migration executes cleanly → it is now recorded as executed naturally;
     *    pending count drops without any force-marking.
     *  - Migration throws a phantom-diff error (table/column already exists) →
     *    force-marked as executed via markMigrationAsExecuted().
     *  - Migration throws any other error → added to $skipped[version => reason];
     *    loop continues with next version.
     *
     * This replaces Approach A (schema-introspection), which was too conservative:
     * migrations using conditional logic or non-CREATE-TABLE patterns were missed,
     * so the pending count did not drop even for genuine phantom-diff versions.
     *
     * @return array{
     *     success: bool,
     *     marked: list<string>,
     *     skipped: array<string, string>,
     *     remaining_pending: int,
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
                    'stopped_at_error' => null,
                ];
            }

            $df = $this->migrationsDependencyFactory;
            $df->getMetadataStorage()->ensureInitialized();
            $planCalc = $df->getMigrationPlanCalculator();
            $migrator = $df->getMigrator();

            foreach ($pending as $version) {
                if (++$iter > $cap) {
                    break;
                }

                try {
                    // Build a single-version UP plan and execute it.
                    $plan = $planCalc->getPlanForVersions(
                        [new Version($version)],
                        Direction::UP,
                    );

                    $config = (new MigratorConfiguration())
                        ->setDryRun(false)
                        ->setAllOrNothing(false)
                        ->setNoMigrationException(true)
                        ->setTimeAllQueries(true);

                    $migrator->migrate($plan, $config);
                    // Migration ran cleanly → already recorded; nothing extra to do.
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();

                    // A failed migration may have closed the default ORM EM
                    // (constraint violation, integrity errors). Reset before
                    // continuing so subsequent iterations + audit-log writes
                    // do not throw EntityManagerClosed.
                    $this->resetEntityManagerIfClosed();

                    if ($this->isPhantomDiffError($msg)) {
                        // Schema already has the object → force-mark.
                        $markResult = $this->markMigrationAsExecuted($version);
                        if ($markResult['success']) {
                            $marked[] = $version;
                            $this->auditLogger->logCustom(
                                'quick_fix.force_mark_migration_executed',
                                'Migration',
                                null,
                                null,
                                ['version' => $version, 'batch' => 'mark_all_phantom_diff'],
                                sprintf(
                                    'QuickFix/mark-all: phantom-diff force-marked migration: %s',
                                    $version,
                                ),
                            );
                        } else {
                            $skipped[$version] = sprintf('[force-mark-failed] %s', $markResult['error'] ?? 'unknown');
                        }
                    } else {
                        // Real error — skip and continue with next version.
                        $category = $this->categorizeMigrationError($msg);
                        $skipped[$version] = sprintf('[%s] %s', $category, $msg);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Outer-loop setup failure (e.g. DependencyFactory not available).
            $skipped['__setup__'] = sprintf('[setup-failure] %s', $e->getMessage());
        }

        $remainingPending = count($this->listPendingMigrationVersionsFromFileSystem());

        return [
            'success' => $skipped === [],
            'marked' => $marked,
            'skipped' => $skipped,
            'remaining_pending' => $remainingPending,
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
            // (1091 = column, 1051 = table, 1086 = index)
            || preg_match("/Can't DROP COLUMN/i", $msg)
            || preg_match("/Can't DROP '[^']+'/i", $msg)
            || preg_match('/Unknown column .* in/i', $msg)
            || preg_match('/Unknown table/i', $msg)
            || preg_match('/1091|1051|1086/', $msg)
        );
    }

    /**
     * Categorizes a migration error message into a short token for the skipped
     * map so the operator gets actionable context in the flash messages.
     */
    private function categorizeMigrationError(string $msg): string
    {
        if (
            preg_match('/SAVEPOINT [A-Z_0-9]+ does not exist/', $msg)
            || preg_match('/There is no active transaction/i', $msg)
        ) {
            return 'savepoint_collapse';
        }

        if (preg_match('/SQLSTATE\[23000\].*foreign key/i', $msg)) {
            return 'foreign_key_constraint';
        }

        if (preg_match("/Field '([^']+)' doesn't have a default value/i", $msg)) {
            return 'missing_default_value';
        }

        if (preg_match("/Unknown column '([^']+)'/i", $msg)) {
            return 'unknown_column';
        }

        return 'unknown';
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
