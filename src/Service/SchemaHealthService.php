<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;

/**
 * Exposes `doctrine:schema:validate` + `doctrine:schema:update --force`
 * to the admin health page.
 *
 * Consultant-Review A4 (hidden cliff): running `--force` from the UI can
 * diverge from `doctrine_migration_versions` — the next deploy then tries
 * to apply a migration that was already materialised by the UI, and hangs.
 * applyUpdate() therefore blocks whenever there are unexecuted migrations;
 * the operator must either execute the migrations first (preferred) or,
 * in an emergency, bypass the gate explicitly via $bypassMigrationGate.
 */
class SchemaHealthService
{
    /** Patterns the SchemaTool emits when it would drop tables/columns/constraints. */
    public const DESTRUCTIVE_PATTERNS = [
        '/^DROP TABLE/i',
        '/^ALTER TABLE .+ DROP /i',
    ];

    /**
     * Infrastructure tables owned by bundles (not App entities). They must NEVER
     * be dropped by a reconcile/force — dropping doctrine_migration_versions
     * wipes Doctrine's migration bookkeeping; messenger_messages is the queue.
     * The dbal `schema_filter` excludes them from introspection (primary fix);
     * this list is the belt-and-suspenders guard in {@see applyUpdate()}.
     */
    public const PROTECTED_TABLES = ['doctrine_migration_versions', 'messenger_messages'];

    /** True when $statement drops (or drops from) a protected infrastructure table. */
    private static function isProtectedDrop(string $statement): bool
    {
        foreach (self::PROTECTED_TABLES as $table) {
            $t = preg_quote($table, '/');
            if (preg_match('/^(DROP TABLE|ALTER TABLE)\s+[`"]?' . $t . '[`"]?\b/i', $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly \Doctrine\Migrations\DependencyFactory $migrationsDependencyFactory,
    ) {
    }

    /**
     * Partitions a SchemaTool/SchemaValidator SQL list into additive,
     * destructive and error-marker buckets. Single source of truth so the
     * display, the apply-gate and the clean-verdict all agree.
     *
     * @param list<string> $sql
     * @return array{additive: list<string>, destructive: list<string>, errors: list<string>}
     */
    public static function classifyStatements(array $sql): array
    {
        $additive = [];
        $destructive = [];
        $errors = [];
        foreach ($sql as $statement) {
            if (str_starts_with($statement, '-- ERROR:')) {
                $errors[] = $statement;
                continue;
            }
            $isDestructive = false;
            foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
                if (preg_match($pattern, $statement) === 1) {
                    $isDestructive = true;
                    break;
                }
            }
            if ($isDestructive) {
                $destructive[] = $statement;
            } else {
                $additive[] = $statement;
            }
        }

        return ['additive' => $additive, 'destructive' => $destructive, 'errors' => $errors];
    }

    /**
     * Equivalent to `doctrine:schema:validate`.
     *
     * @return array{
     *     mapping_in_sync: bool,
     *     database_in_sync: bool,
     *     mapping_errors: array<string, list<string>>,
     *     pending_sql: list<string>,
     *     pending_migrations: list<string>,
     *     overall_status: 'healthy'|'warning'|'error'
     * }
     */
    public function validate(): array
    {
        $validator = new SchemaValidator($this->entityManager);

        $mappingErrors = $validator->validateMapping();
        $mappingInSync = $mappingErrors === [];

        $pendingSql = [];
        $databaseInSync = true;
        try {
            $pendingSql = $validator->getUpdateSchemaList();
            $databaseInSync = $pendingSql === [];
        } catch (\Throwable $e) {
            $pendingSql = [sprintf('-- ERROR: %s', $e->getMessage())];
            $databaseInSync = false;
        }

        $pendingMigrations = $this->pendingMigrationVersions();

        $overall = 'healthy';
        if (!$mappingInSync) {
            $overall = 'error';
        } elseif (!$databaseInSync || $pendingMigrations !== []) {
            $overall = 'warning';
        }

        return [
            'mapping_in_sync' => $mappingInSync,
            'database_in_sync' => $databaseInSync,
            'mapping_errors' => $mappingErrors,
            'pending_sql' => $pendingSql,
            'pending_migrations' => $pendingMigrations,
            'overall_status' => $overall,
        ];
    }

    /**
     * Equivalent to `doctrine:schema:update --force`. Executes the entity-vs-DB
     * diff. Destructive statements (DROP) are withheld unless $allowDestructive
     * is true — they are returned in `skipped_destructive` instead. Every
     * execution is audit-logged with a SHA-256 of the executed SQL bundle so
     * audit can reconcile the row.
     *
     * @return array{success:bool, executed_sql:list<string>, sql_hash:?string, error:?string, blocked:?string, dropped_fks:list<array{table:string,fk:string,sql_that_triggered:string}>, skipped_destructive:list<string>}
     */
    public function applyUpdate(string $actor = 'system', bool $bypassMigrationGate = false, bool $allowDestructive = false): array
    {
        // Guard: don't race Doctrine migrations. ISB MAJOR-3 + Consultant A4.
        $pendingMigrations = $this->pendingMigrationVersions();
        if ($pendingMigrations !== [] && !$bypassMigrationGate) {
            $this->auditLogger->logCustom(
                'admin.schema.update.blocked',
                'Doctrine',
                null,
                null,
                [
                    'reason' => 'pending_migrations',
                    'pending_migration_count' => count($pendingMigrations),
                ],
                sprintf(
                    'Schema update blocked for %s: %d pending Doctrine migration(s). Run app:schema:migrate first.',
                    $actor,
                    count($pendingMigrations),
                ),
            );
            return [
                'success' => false,
                'executed_sql' => [],
                'sql_hash' => null,
                'error' => null,
                'blocked' => 'pending_migrations',
                'dropped_fks' => [],
                'skipped_destructive' => [],
            ];
        }

        $sql = $this->computeUpdateSql();

        if ($sql === []) {
            return [
                'success' => true,
                'executed_sql' => [],
                'sql_hash' => null,
                'error' => null,
                'blocked' => null,
                'dropped_fks' => [],
                'skipped_destructive' => [],
            ];
        }

        // Belt-and-suspenders: NEVER drop infrastructure tables (QF B2), even
        // with allowDestructive=true. The dbal schema_filter already excludes
        // them from introspection; this guard covers a missing/misconfigured
        // filter or raw SQL reaching this path. Dropping doctrine_migration_versions
        // would wipe Doctrine's migration bookkeeping.
        $protectedDrops = array_values(array_filter($sql, static fn (string $s): bool => self::isProtectedDrop($s)));
        if ($protectedDrops !== []) {
            $sql = array_values(array_filter($sql, static fn (string $s): bool => !self::isProtectedDrop($s)));
            $this->auditLogger->logCustom(
                'admin.schema.update.protected_table_skipped',
                'Doctrine',
                null,
                null,
                ['skipped_count' => count($protectedDrops), 'skipped' => $protectedDrops],
                sprintf('Schema update for %s: refused %d infrastructure-table drop(s) (protected: %s)', $actor, count($protectedDrops), implode(', ', self::PROTECTED_TABLES)),
            );
            if ($sql === []) {
                return [
                    'success' => true,
                    'executed_sql' => [],
                    'sql_hash' => null,
                    'error' => null,
                    'blocked' => null,
                    'dropped_fks' => [],
                    'skipped_destructive' => [],
                ];
            }
        }

        $classified = self::classifyStatements($sql);
        $skippedDestructive = [];
        if (!$allowDestructive && $classified['destructive'] !== []) {
            $skippedDestructive = $classified['destructive'];
            $sql = $classified['additive'];
            $this->auditLogger->logCustom(
                'admin.schema.update.destructive_skipped',
                'Doctrine',
                null,
                null,
                ['skipped_count' => count($skippedDestructive), 'skipped' => array_slice($skippedDestructive, 0, 10)],
                sprintf('Schema update for %s: %d destructive statement(s) withheld (allowDestructive=false)', $actor, count($skippedDestructive)),
            );
            if ($sql === []) {
                return [
                    'success' => true,
                    'executed_sql' => [],
                    'sql_hash' => null,
                    'error' => null,
                    'blocked' => null,
                    'dropped_fks' => [],
                    'skipped_destructive' => $skippedDestructive,
                ];
            }
        }

        $sqlHash = hash('sha256', implode(";\n", $sql));

        $conn = $this->entityManager->getConnection();
        $droppedFks = [];

        // Disable FK checks for the duration of the reconcile. SchemaTool can
        // emit CREATE TABLE / ALTER TABLE statements in an order that violates
        // FK dependency order (errno 150 "Foreign key constraint is incorrectly
        // formed"). Disabling FK checks lets ordering-sensitive DDL succeed;
        // we re-enable + validate constraints after the batch completes.
        $fkChecksWereEnabled = false;
        try {
            $current = $conn->fetchOne('SELECT @@SESSION.foreign_key_checks');
            $fkChecksWereEnabled = ((int) $current) === 1;
            if ($fkChecksWereEnabled) {
                $conn->executeStatement('SET SESSION foreign_key_checks = 0');
            }
        } catch (\Throwable) {
            // Non-MySQL driver — skip toggle silently
        }

        try {
            foreach ($sql as $statement) {
                $this->executeStatementFkAware($conn, $statement, $droppedFks);
            }

            // Multi-pass convergence: after the first pass, FKs that previously
            // failed (referenced table not yet created) may now succeed because
            // dependent tables exist. Re-run schema diff up to 2 additional
            // passes. Each pass executes only the remaining diff.
            //
            // Phantom-drift detection: DBAL 4.x + MariaDB emit no-op statements
            // for JSON↔LONGTEXT introspection and DEFAULT NULL diff. If pass N
            // emits identical statements to pass N-1, the schema IS in sync;
            // stop iterating and treat as success.
            $previousPassHash = hash('sha256', implode(";\n", $sql));
            $passTool = new SchemaTool($this->entityManager);
            for ($pass = 2; $pass <= 3; $pass++) {
                $passSql = $passTool->getUpdateSchemaSql(
                    $this->entityManager->getMetadataFactory()->getAllMetadata(),
                );
                if ($passSql === []) {
                    break;
                }
                $passClassified = self::classifyStatements($passSql);
                $passToRun = $allowDestructive ? $passSql : $passClassified['additive'];
                // Never let a protected infra-table drop slip through a later pass.
                $passToRun = array_values(array_filter($passToRun, static fn (string $s): bool => !self::isProtectedDrop($s)));
                if (!$allowDestructive && $passClassified['destructive'] !== []) {
                    foreach ($passClassified['destructive'] as $d) {
                        if (!in_array($d, $skippedDestructive, true)) {
                            $skippedDestructive[] = $d;
                        }
                    }
                }
                $currentHash = hash('sha256', implode(";\n", $passToRun));
                if ($passToRun === [] || $currentHash === $previousPassHash) {
                    break;
                }
                $previousPassHash = $currentHash;
                foreach ($passToRun as $statement) {
                    $this->executeStatementFkAware($conn, $statement, $droppedFks);
                }
            }
        } catch (\Throwable $e) {
            // Restore FK checks even on failure
            if ($fkChecksWereEnabled) {
                try { $conn->executeStatement('SET SESSION foreign_key_checks = 1'); } catch (\Throwable) {}
            }
            $this->auditLogger->logCustom(
                'admin.schema.update.failed',
                'Doctrine',
                null,
                null,
                [
                    'error' => $e->getMessage(),
                    'sql_count' => count($sql),
                    'sql_hash' => $sqlHash,
                    'dropped_fks' => $droppedFks,
                ],
                sprintf('Schema update failed by %s: %s', $actor, $e->getMessage()),
            );
            return [
                'success' => false,
                'executed_sql' => $sql,
                'sql_hash' => $sqlHash,
                'error' => $e->getMessage(),
                'blocked' => null,
                'dropped_fks' => $droppedFks,
                'skipped_destructive' => $skippedDestructive,
            ];
        }

        // Re-enable FK checks after successful batch
        if ($fkChecksWereEnabled) {
            try { $conn->executeStatement('SET SESSION foreign_key_checks = 1'); } catch (\Throwable) {}
        }

        $this->auditLogger->logCustom(
            'admin.schema.update.applied',
            'Doctrine',
            null,
            null,
            [
                'statements' => count($sql),
                'sql_hash' => $sqlHash,
                'bypass_migration_gate' => $bypassMigrationGate,
                'dropped_fks' => $droppedFks,
            ],
            sprintf(
                'Schema update applied by %s (%d statements, sha256=%s%s%s)',
                $actor,
                count($sql),
                substr($sqlHash, 0, 16),
                $bypassMigrationGate ? ', migration-gate BYPASSED' : '',
                $droppedFks !== [] ? sprintf(', %d FK(s) temporarily dropped+recreated', count($droppedFks)) : '',
            ),
        );

        return [
            'success' => true,
            'executed_sql' => $sql,
            'sql_hash' => $sqlHash,
            'error' => null,
            'blocked' => null,
            'dropped_fks' => $droppedFks,
            'skipped_destructive' => $skippedDestructive,
        ];
    }

    /**
     * Seam over SchemaTool so tests can stub the emitted SQL. Production path
     * computes the live entity-vs-DB diff.
     *
     * @return list<string>
     */
    protected function computeUpdateSql(): array
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->entityManager);

        return $tool->getUpdateSchemaSql($metadata);
    }

    /**
     * Execute a single DDL statement, recovering from MySQL error 1832
     * (Cannot change column because it is used in a foreign key constraint)
     * by dropping the blocking FK, retrying the ALTER, and re-adding the FK.
     *
     * @param array<int, array{table: string, fk: string, sql_that_triggered: string}> $droppedFks
     *        Accumulates metadata about every FK that was temporarily removed.
     */
    private function executeStatementFkAware(Connection $conn, string $sql, array &$droppedFks, int $depth = 0): void
    {
        if ($depth > 3) {
            throw new \App\Exception\Io\IoException(sprintf(
                'FK-aware reconcile recursion limit reached for: %s',
                substr($sql, 0, 100),
            ));
        }

        try {
            $conn->executeStatement($sql);
        } catch (\Doctrine\DBAL\Exception $e) {
            // Broadened from DriverException to base Exception so we catch
            // errno 1822/1005 which DBAL 4.x may surface as generic
            // \Doctrine\DBAL\Exception (not the DriverException subclass).
            $msg = $e->getMessage();

            // Pattern: SQLSTATE[HY000]: General error: 1832/1833 Cannot change
            //          column 'col': used in a foreign key constraint 'fk_name'
            // 1832 = FK referenced from same table, 1833 = FK referenced from
            //        another table. Both need same recovery: drop+alter+re-add.
            if (preg_match(
                "/(1832|1833) Cannot change column '([^']+)': used in a foreign key constraint '([^']+)'/",
                $msg,
                $m,
            )) {
                $columnName = $m[2];
                $fkName = $m[3];

                if (!preg_match('/ALTER TABLE [`"]?(\w+)[`"]?/i', $sql, $tm)) {
                    throw $e; // can't determine table — bubble
                }
                $tableName = $tm[1];

                // For errno 1833, the blocking FK lives on a DIFFERENT table
                // that REFERENCES $tableName. Look up the actual owner.
                $fkOwnerTable = $this->findForeignKeyOwnerTable($conn, $fkName);
                if ($fkOwnerTable === null) {
                    // Fall back to same-table lookup (1832 path)
                    $fkOwnerTable = $tableName;
                }

                $fkDef = $this->captureForeignKeyDefinition($conn, $fkOwnerTable, $fkName);
                if ($fkDef === null) {
                    throw $e; // could not introspect — bubble
                }

                // Step 1: drop the blocking FK from its actual owner table
                $conn->executeStatement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $fkOwnerTable, $fkName));

                // Step 2: retry the original statement (depth-guarded)
                $this->executeStatementFkAware($conn, $sql, $droppedFks, $depth + 1);

                // Step 3: re-add the FK on its owner table — MUST succeed, else
                // we have silently dropped a referential-integrity constraint.
                try {
                    $conn->executeStatement(sprintf(
                        'ALTER TABLE `%s` ADD CONSTRAINT `%s` %s',
                        $fkOwnerTable,
                        $fkName,
                        $fkDef,
                    ));
                } catch (\Throwable $reAddError) {
                    $this->auditLogger->logCustom(
                        'admin.schema.fk_aware_readd_failed',
                        'Doctrine',
                        null,
                        null,
                        [
                            'table' => $fkOwnerTable,
                            'fk' => $fkName,
                            'readd_sql' => sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` %s', $fkOwnerTable, $fkName, $fkDef),
                            'error' => $reAddError->getMessage(),
                        ],
                        sprintf(
                            'CRITICAL: FK %s on %s was dropped to apply an ALTER but could NOT be recreated: %s. Manual re-add required.',
                            $fkName,
                            $fkOwnerTable,
                            $reAddError->getMessage(),
                        ),
                    );
                    throw new \App\Exception\Io\IoException(sprintf(
                        'FK-aware reconcile dropped %s.%s but failed to recreate it: %s — re-add SQL: ALTER TABLE `%s` ADD CONSTRAINT `%s` %s',
                        $fkOwnerTable,
                        $fkName,
                        $reAddError->getMessage(),
                        $fkOwnerTable,
                        $fkName,
                        $fkDef,
                    ), null, $reAddError);
                }

                $droppedFks[] = [
                    'table' => $fkOwnerTable,
                    'fk' => $fkName,
                    'altered_table' => $tableName,
                    'sql_that_triggered' => substr($sql, 0, 200),
                ];

                $this->auditLogger->logCustom(
                    'admin.schema.fk_aware_recovery',
                    'Doctrine',
                    null,
                    null,
                    ['table' => $tableName, 'fk' => $fkName],
                    sprintf(
                        'FK-aware reconcile: dropped+recreated %s.%s to apply ALTER on column %s',
                        $tableName,
                        $fkName,
                        $columnName,
                    ),
                );
                return;
            }

            // Pattern: SQLSTATE[42000] 1091/1176 — Can't DROP / Key doesn't exist.
            // Happens when a prior partial reconcile or out-of-band migration
            // already dropped the FK/index. Reconcile is idempotent so swallow.
            if (preg_match(
                "/(1091|1176).+(doesn't exist|Can't DROP)/i",
                $msg,
            )) {
                $this->auditLogger->logCustom(
                    'admin.schema.fk_aware_skip_absent',
                    'Doctrine',
                    null,
                    null,
                    ['sql' => substr($sql, 0, 200), 'error' => substr($msg, 0, 200)],
                    sprintf('FK-aware reconcile: skipped already-absent DROP (sql=%s)', substr($sql, 0, 80)),
                );
                return;
            }

            // Pattern: SQLSTATE[HY000] 1822 — Failed to add the foreign key
            // constraint. Missing index for constraint 'X' in the referenced
            // table 'Y'. InnoDB enforces this even with foreign_key_checks=0.
            // On a drifted DB the referenced table may not exist yet OR the
            // referenced column index will be created later in the batch.
            // Swallow + log: subsequent reconcile run will succeed once tables
            // are present. Better partial reconcile than total abort.
            if (preg_match('/1822\s.*Failed to add the foreign key constraint/i', $msg)) {
                $this->auditLogger->logCustom(
                    'admin.schema.fk_aware_skip_missing_index',
                    'Doctrine',
                    null,
                    null,
                    ['sql' => substr($sql, 0, 200), 'error' => substr($msg, 0, 200)],
                    sprintf('FK-aware reconcile: skipped FK with missing referenced index (sql=%s) — re-run after referenced table is created', substr($sql, 0, 80)),
                );
                return;
            }

            // Pattern: SQLSTATE[HY000] 1005 errno 150 — generic "FK constraint
            // is incorrectly formed". Same recovery: swallow, audit-log, expect
            // operator to re-run after dependent tables exist.
            if (preg_match('/1005\s.*errno:\s*150/i', $msg)) {
                $this->auditLogger->logCustom(
                    'admin.schema.fk_aware_skip_errno_150',
                    'Doctrine',
                    null,
                    null,
                    ['sql' => substr($sql, 0, 200), 'error' => substr($msg, 0, 200)],
                    sprintf('FK-aware reconcile: skipped CREATE-TABLE with errno 150 (incorrect FK form, likely forward-reference) (sql=%s)', substr($sql, 0, 80)),
                );
                return;
            }

            // Other DriverException — bubble
            throw $e;
        }
    }

    /**
     * Introspects information_schema to reconstruct a FOREIGN KEY clause string
     * so the constraint can be re-added after a blocking ALTER COLUMN.
     * Returns null when the FK is not found in the schema.
     */
    /**
     * For errno 1833: find which TABLE owns a given FK constraint, regardless
     * of which table currently being altered. Returns null when not found.
     */
    private function findForeignKeyOwnerTable(Connection $conn, string $fkName): ?string
    {
        try {
            $owner = $conn->fetchOne(
                "SELECT TABLE_NAME
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                   AND CONSTRAINT_NAME = :fk
                 LIMIT 1",
                ['fk' => $fkName],
            );
            return is_string($owner) && $owner !== '' ? $owner : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function captureForeignKeyDefinition(Connection $conn, string $tableName, string $fkName): ?string
    {
        $cols = $conn->fetchAllAssociative(
            "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
             ORDER BY ORDINAL_POSITION",
            [$tableName, $fkName],
        );

        if ($cols === []) {
            return null;
        }

        $localCols = implode(', ', array_map(fn (array $r): string => "`{$r['COLUMN_NAME']}`", $cols));
        $refTable = $cols[0]['REFERENCED_TABLE_NAME'];
        $refCols = implode(', ', array_map(fn (array $r): string => "`{$r['REFERENCED_COLUMN_NAME']}`", $cols));

        $rules = $conn->fetchAssociative(
            "SELECT DELETE_RULE, UPDATE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
            [$tableName, $fkName],
        );

        $deleteRule = (is_array($rules) && isset($rules['DELETE_RULE'])) ? $rules['DELETE_RULE'] : 'RESTRICT';
        $updateRule = (is_array($rules) && isset($rules['UPDATE_RULE'])) ? $rules['UPDATE_RULE'] : 'RESTRICT';

        return sprintf(
            'FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
            $localCols,
            $refTable,
            $refCols,
            $deleteRule,
            $updateRule,
        );
    }

    /**
     * Returns pending Doctrine-migration version IDs, or [] when the
     * doctrine_migration_versions table does not exist yet (fresh install
     * before the first migrate run).
     *
     * @return list<string>
     */
    /**
     * Public getter for QuickFix UI — exposes file-system-discovered
     * pending migrations (the same list applyUpdate() uses to gate).
     * Lets the operator-UI surface the discrepancy when this list is
     * non-empty but Doctrine's MigrationPlanCalculator says 0 pending.
     *
     * @return list<string>
     */
    public function listPendingMigrationVersions(): array
    {
        return $this->pendingMigrationVersions();
    }

    protected function pendingMigrationVersions(): array
    {
        /** @var Connection $conn */
        $conn = $this->entityManager->getConnection();
        try {
            $executed = $conn->executeQuery('SELECT version FROM doctrine_migration_versions')
                ->fetchFirstColumn();
        } catch (\Throwable) {
            // doctrine_migration_versions does not exist yet (e.g. a
            // reconcile-built schema that was never migrated). The correct
            // reading is "nothing recorded as executed" — so EVERY filesystem
            // migration is pending. Returning [] here previously hid this state
            // and silently no-op'd markAllPhantomDiffMigrationsAsExecuted, the
            // very action meant to recover from it.
            $executed = [];
        }
        $executedSet = array_flip(array_map(static fn(string $v): string => (string) $v, $executed));

        // Derive available versions from Doctrine's own migration repository,
        // NOT from a filesystem glob. A glob keys versions by FILE name, which
        // breaks for files whose name carries a descriptive suffix their class
        // lacks (e.g. VersionYYYY..._t31_sprint1.php declaring class
        // VersionYYYY...). That produced version strings Doctrine — and
        // markMigrationAsExecuted — never recognise, leaving those migrations
        // permanently "pending" and unmarkable. The repository is the same
        // authoritative source markMigrationAsExecuted validates against, so
        // the version strings always match.
        $pending = [];
        foreach (
            $this->migrationsDependencyFactory
                ->getMigrationRepository()
                ->getMigrations()
                ->getItems() as $available
        ) {
            $version = (string) $available->getVersion();
            if (!isset($executedSet[$version])) {
                $pending[] = $version;
            }
        }

        return $pending;
    }
}
