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
     * @return array{success:bool, executed_sql:list<string>, sql_hash:?string, error:?string, blocked:?string}
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

        try {
            $tool->updateSchema($metadata);
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
                ],
                sprintf('Schema update failed by %s: %s', $actor, $e->getMessage()),
            );
            return [
                'success' => false,
                'executed_sql' => $sql,
                'sql_hash' => $sqlHash,
                'error' => $e->getMessage(),
                'blocked' => null,
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
            ],
            sprintf(
                'Schema update applied by %s (%d statements, sha256=%s%s)',
                $actor,
                count($sql),
                substr($sqlHash, 0, 16),
                $bypassMigrationGate ? ', migration-gate BYPASSED' : '',
            ),
        );

        return [
            'success' => true,
            'executed_sql' => $sql,
            'sql_hash' => $sqlHash,
            'error' => null,
            'blocked' => null,
        ];
    }

    /**
     * Returns pending Doctrine-migration version IDs, or [] when the
     * doctrine_migration_versions table does not exist yet (fresh install
     * before the first migrate run).
     *
     * @return list<string>
     */
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
