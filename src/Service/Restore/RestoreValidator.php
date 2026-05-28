<?php

declare(strict_types=1);

namespace App\Service\Restore;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Validates backup data before a restore operation.
 *
 * Responsibilities:
 *  - Format/version validation
 *  - Schema-version advisory check
 *  - SHA256 integrity verification
 *  - Tenant-scope guard (ROLE_ADMIN vs SUPER_ADMIN)
 *  - Per-entity-type data structure validation (sample check)
 */
class RestoreValidator
{
    /**
     * Backup format versions that can be handled.
     * '1.0' = legacy JSON-only (no schema_version in metadata)
     * '2.0' = JSON + optional ZIP-embedded files + schema_version + app_version
     */
    private const array SUPPORTED_VERSIONS = ['1.0', '2.0'];

    private array $validationErrors = [];
    private array $warnings = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate backup data.
     *
     * @param array       $backup      Backup data to validate
     * @param Tenant|null $callerScope When set, rejects cross-tenant restore attempts.
     *                                 SUPER_ADMIN passes null and bypasses the check.
     * @param callable    $resolveTenantScopeIds  Callable(Tenant): int[] — injected to avoid cyclic dep
     * @param callable    $validateEntityData     Callable(string $class, string $name, array $rows): void
     * @return array Validation result with 'valid' boolean, 'errors' array, 'warnings' array
     *
     * @throws AccessDeniedException When $callerScope is set and the backup's
     *                               recorded tenant_scope has no overlap with
     *                               the caller's accessible tree.
     */
    public function validateBackup(
        array $backup,
        ?Tenant $callerScope,
        callable $resolveTenantScopeIds,
        callable $validateEntityData,
    ): array {
        $this->validationErrors = [];
        $this->warnings = [];

        // Tenant-scope guard: reject cross-tenant attempts before anything else.
        if ($callerScope !== null) {
            $callerScopeIds = $resolveTenantScopeIds($callerScope);
            $backupScopeIds = $backup['metadata']['tenant_scope'] ?? null;

            if ($backupScopeIds === null || !is_array($backupScopeIds)) {
                throw new AccessDeniedException(
                    'Cross-tenant restore denied: backup has no recorded tenant_scope (legacy/global backup).'
                );
            }

            $backupScopeIds = array_map(static fn($v): int => (int) $v, $backupScopeIds);

            if ($backupScopeIds === []) {
                throw new AccessDeniedException(
                    'Cross-tenant restore denied: backup was created with global scope.'
                );
            }

            if (array_intersect($backupScopeIds, $callerScopeIds) === []) {
                throw new AccessDeniedException(sprintf(
                    'Cross-tenant restore denied: backup tenant_scope [%s] does not overlap with caller scope [%s].',
                    implode(',', $backupScopeIds),
                    implode(',', $callerScopeIds)
                ));
            }
        }

        // Check metadata
        if (!isset($backup['metadata'])) {
            $this->validationErrors[] = 'Missing metadata section';
            return ['valid' => false, 'errors' => $this->validationErrors, 'warnings' => $this->warnings];
        }

        // Check version compatibility
        $version = $backup['metadata']['version'] ?? null;
        if ($version === null) {
            $this->warnings[] = 'Backup has no format version (assumed legacy 1.0). File restore will be skipped.';
        } elseif (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
            $this->validationErrors[] = sprintf(
                'Unsupported backup version: %s (supported: %s)',
                $version,
                implode(', ', self::SUPPORTED_VERSIONS)
            );
        }

        // Schema version advisory check (non-blocking)
        if (isset($backup['metadata']['schema_version'])) {
            $this->checkSchemaVersionCompatibility($backup['metadata']['schema_version']);
        }

        // Warn when files were in backup but extraction has not happened yet
        if (!empty($backup['metadata']['files_included']) && !isset($backup['metadata']['_extracted_file_count'])) {
            $this->warnings[] = sprintf(
                'Backup contains %d embedded file(s) — load via BackupService::loadBackupFromFile() to extract them.',
                $backup['metadata']['file_count'] ?? 0
            );
        }

        // Check data section
        if (!isset($backup['data']) || !is_array($backup['data'])) {
            $this->validationErrors[] = 'Missing or invalid data section';
            return ['valid' => false, 'errors' => $this->validationErrors, 'warnings' => $this->warnings];
        }

        // Validate each entity type
        foreach ($backup['data'] as $entityName => $entities) {
            $entityClass = 'App\\Entity\\' . $entityName;

            if (!class_exists($entityClass)) {
                $this->warnings[] = sprintf('Entity class not found: %s (will be skipped)', $entityName);
                continue;
            }

            if (!is_array($entities)) {
                $this->validationErrors[] = sprintf('Invalid data format for entity: %s', $entityName);
                continue;
            }

            $validateEntityData($entityClass, $entityName, $entities);
        }

        return [
            'valid' => $this->validationErrors === [],
            'errors' => $this->validationErrors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Compare the backup schema_version against the current database schema.
     * Adds a non-blocking warning when they differ.
     */
    private function checkSchemaVersionCompatibility(string $backupSchemaVersion): void
    {
        try {
            $connection = $this->entityManager->getConnection();
            $result = $connection->executeQuery(
                'SELECT version FROM doctrine_migration_versions ORDER BY executed_at DESC LIMIT 1'
            );
            $row = $result->fetchAssociative();
            if ($row !== false && isset($row['version'])) {
                $currentVersion = preg_replace('/^.*\\\\/', '', (string) $row['version']) ?? (string) $row['version'];
                if ($currentVersion !== $backupSchemaVersion) {
                    $this->warnings[] = sprintf(
                        'Schema version mismatch: backup was created with schema "%s", current schema is "%s". '
                        . 'Some fields may be missing or incompatible.',
                        $backupSchemaVersion,
                        $currentVersion
                    );
                }
            }
        } catch (Exception) {
            // Non-critical — if the table does not exist we simply skip the check
        }
    }

    /**
     * Verify the SHA256 integrity seal of the backup data section.
     *
     * - If `metadata.sha256` is present: recompute hash over `data` and throw on mismatch.
     * - If `metadata.sha256` is absent (legacy backup): log a warning and return the warning
     *   string so the caller can re-apply it after the next `validateBackup()` reset.
     *
     * @return string|null Warning message for legacy backups without a hash, null otherwise.
     * @throws \App\Exception\Io\IoException When the hash does not match.
     */
    public function verifyIntegrity(array $backup): ?string
    {
        $storedHash = $backup['metadata']['sha256'] ?? null;

        if ($storedHash === null) {
            $warning = 'Legacy backup restored without SHA256 integrity verification — hash absent in metadata.';
            $this->logger->warning($warning);
            return $warning;
        }

        $actualHash = hash('sha256', (string) json_encode($backup['data'] ?? []));

        if (!hash_equals($storedHash, $actualHash)) {
            throw new \App\Exception\Io\IoException(sprintf(
                'Backup integrity check failed: sha256 mismatch (expected %s, got %s)',
                $storedHash,
                $actualHash
            ));
        }

        $this->logger->info('Backup integrity check passed', ['sha256' => $storedHash]);
        return null;
    }

    /**
     * Validate entity data structure (sample check on first record).
     * Writes warnings directly into the facade's $warnings array via the callback approach;
     * here we write into our own $warnings which the facade merges back.
     *
     * @internal Called via closure injected from RestoreService::validateBackup().
     */
    public function validateEntityData(string $entityClass, string $entityName, array $entities): void
    {
        if ($entities === []) {
            return;
        }

        $classMetadata = $this->entityManager->getClassMetadata($entityClass);
        $requiredFields = [];

        foreach ($classMetadata->getFieldNames() as $fieldName) {
            $mapping = $classMetadata->getFieldMapping($fieldName);
            $nullable = is_array($mapping)
                ? ($mapping['nullable'] ?? false)
                : ($mapping->nullable ?? false);
            if (!$nullable && $fieldName !== 'id') {
                $requiredFields[] = $fieldName;
            }
        }

        $firstEntity = $entities[0];
        foreach ($requiredFields as $requiredField) {
            if (!array_key_exists($requiredField, $firstEntity) || $firstEntity[$requiredField] === null) {
                $this->warnings[] = sprintf(
                    'Required field "%s" missing in entity "%s" (can use default value strategy)',
                    $requiredField,
                    $entityName
                );
            }
        }
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
