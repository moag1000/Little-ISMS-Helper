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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
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
     * Equivalent to `doctrine:schema:update --force`. Destructive — runs all
     * pending SQL against the live DB. Every execution is audit-logged with
     * a SHA-256 of the executed SQL bundle so audit can reconcile the row.
     *
     * @return array{success:bool, executed_sql:list<string>, sql_hash:?string, error:?string, blocked:?string, dropped_fks:list<array{table:string,fk:string,sql_that_triggered:string}>}
     */
    public function applyUpdate(string $actor = 'system', bool $bypassMigrationGate = false): array
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
            ];
        }

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->entityManager);
        $sql = $tool->getUpdateSchemaSql($metadata);

        if ($sql === []) {
            return [
                'success' => true,
                'executed_sql' => [],
                'sql_hash' => null,
                'error' => null,
                'blocked' => null,
            ];
        }

        $sqlHash = hash('sha256', implode(";\n", $sql));

        $conn = $this->entityManager->getConnection();
        $droppedFks = [];

        try {
            foreach ($sql as $statement) {
                $this->executeStatementFkAware($conn, $statement, $droppedFks);
            }
        } catch (\Throwable $e) {
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
            ];
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
        ];
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
            throw new \RuntimeException(sprintf(
                'FK-aware reconcile recursion limit reached for: %s',
                substr($sql, 0, 100),
            ));
        }

        try {
            $conn->executeStatement($sql);
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            $msg = $e->getMessage();

            // Pattern: SQLSTATE[HY000]: General error: 1832 Cannot change column 'col':
            //          used in a foreign key constraint 'fk_name'
            if (preg_match(
                "/1832 Cannot change column '([^']+)': used in a foreign key constraint '([^']+)'/",
                $msg,
                $m,
            )) {
                $columnName = $m[1];
                $fkName = $m[2];

                if (!preg_match('/ALTER TABLE [`"]?(\w+)[`"]?/i', $sql, $tm)) {
                    throw $e; // can't determine table — bubble
                }
                $tableName = $tm[1];

                $fkDef = $this->captureForeignKeyDefinition($conn, $tableName, $fkName);
                if ($fkDef === null) {
                    throw $e; // could not introspect — bubble
                }

                // Step 1: drop the blocking FK
                $conn->executeStatement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $fkName));

                // Step 2: retry the original statement (depth-guarded)
                $this->executeStatementFkAware($conn, $sql, $droppedFks, $depth + 1);

                // Step 3: re-add the FK
                $conn->executeStatement(sprintf(
                    'ALTER TABLE `%s` ADD CONSTRAINT `%s` %s',
                    $tableName,
                    $fkName,
                    $fkDef,
                ));

                $droppedFks[] = [
                    'table' => $tableName,
                    'fk' => $fkName,
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

            // Other DriverException — bubble
            throw $e;
        }
    }

    /**
     * Introspects information_schema to reconstruct a FOREIGN KEY clause string
     * so the constraint can be re-added after a blocking ALTER COLUMN.
     * Returns null when the FK is not found in the schema.
     */
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

    private function pendingMigrationVersions(): array
    {
        /** @var Connection $conn */
        $conn = $this->entityManager->getConnection();
        try {
            $executed = $conn->executeQuery('SELECT version FROM doctrine_migration_versions')
                ->fetchFirstColumn();
        } catch (\Throwable) {
            return [];
        }
        $executedSet = array_flip(array_map(static fn(string $v): string => (string) $v, $executed));

        $allVersions = [];
        $dir = __DIR__ . '/../../migrations';
        if (is_dir($dir)) {
            foreach (glob($dir . '/Version*.php') ?: [] as $path) {
                $allVersions[] = 'DoctrineMigrations\\' . pathinfo($path, PATHINFO_FILENAME);
            }
        }

        $pending = [];
        foreach ($allVersions as $v) {
            if (!isset($executedSet[$v])) {
                $pending[] = $v;
            }
        }
        return $pending;
    }
}
