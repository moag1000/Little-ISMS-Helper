<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;

/**
 * Exposes `doctrine:schema:validate` + `doctrine:schema:update --force`
 * to the admin health page. Keep expensive metadata loading behind
 * explicit calls — do NOT eager-load in the constructor.
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

        $overall = 'healthy';
        if (!$mappingInSync) {
            $overall = 'error';
        } elseif (!$databaseInSync) {
            $overall = 'warning';
        }

        return [
            'mapping_in_sync' => $mappingInSync,
            'database_in_sync' => $databaseInSync,
            'mapping_errors' => $mappingErrors,
            'pending_sql' => $pendingSql,
            'overall_status' => $overall,
        ];
    }

    /**
     * Equivalent to `doctrine:schema:update --force`. Destructive — runs all
     * pending SQL against the live DB. Every execution is audit-logged.
     *
     * @return array{success:bool, executed_sql:list<string>, error:?string}
     */
    public function applyUpdate(string $actor = 'system'): array
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->entityManager);
        $sql = $tool->getUpdateSchemaSql($metadata);

        if ($sql === []) {
            return [
                'success' => true,
                'executed_sql' => [],
                'error' => null,
            ];
        }

        try {
            $tool->updateSchema($metadata);
        } catch (\Throwable $e) {
            $this->auditLogger->logCustom(
                'admin.schema.update.failed',
                'Doctrine',
                null,
                null,
                ['error' => $e->getMessage(), 'sql_count' => count($sql)],
                sprintf('Schema update failed by %s: %s', $actor, $e->getMessage()),
            );
            return [
                'success' => false,
                'executed_sql' => $sql,
                'error' => $e->getMessage(),
            ];
        }

        $this->auditLogger->logCustom(
            'admin.schema.update.applied',
            'Doctrine',
            null,
            null,
            ['statements' => count($sql)],
            sprintf('Schema update applied by %s (%d SQL statements)', $actor, count($sql)),
        );

        return [
            'success' => true,
            'executed_sql' => $sql,
            'error' => null,
        ];
    }
}
