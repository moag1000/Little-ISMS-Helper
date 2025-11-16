<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

class RestoreService
{
    private const SUPPORTED_VERSIONS = ['1.0'];

    // Strategies for handling missing fields
    public const STRATEGY_SKIP_FIELD = 'skip_field';
    public const STRATEGY_USE_DEFAULT = 'use_default';
    public const STRATEGY_FAIL = 'fail';

    // Strategies for handling existing data
    public const EXISTING_SKIP = 'skip';
    public const EXISTING_UPDATE = 'update';
    public const EXISTING_REPLACE = 'replace';

    private array $validationErrors = [];
    private array $warnings = [];
    private array $statistics = [];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Validate backup data
     *
     * @param array $backup Backup data to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validateBackup(array $backup): array
    {
        $this->validationErrors = [];
        $this->warnings = [];

        // Check metadata
        if (!isset($backup['metadata'])) {
            $this->validationErrors[] = 'Missing metadata section';
            return ['valid' => false, 'errors' => $this->validationErrors, 'warnings' => $this->warnings];
        }

        // Check version compatibility
        $version = $backup['metadata']['version'] ?? null;
        if (!in_array($version, self::SUPPORTED_VERSIONS)) {
            $this->validationErrors[] = sprintf(
                'Unsupported backup version: %s (supported: %s)',
                $version,
                implode(', ', self::SUPPORTED_VERSIONS)
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

            // Validate entity structure
            $this->validateEntityData($entityClass, $entityName, $entities);
        }

        return [
            'valid' => empty($this->validationErrors),
            'errors' => $this->validationErrors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Get preview of restore operation
     *
     * @param array $backup Backup data
     * @return array Preview data
     */
    public function getRestorePreview(array $backup): array
    {
        $preview = [
            'metadata' => $backup['metadata'] ?? [],
            'entities' => [],
            'total_records' => 0,
        ];

        foreach ($backup['data'] as $entityName => $entities) {
            $entityClass = 'App\\Entity\\' . $entityName;

            if (!class_exists($entityClass)) {
                continue;
            }

            $existing = $this->entityManager->getRepository($entityClass)->count([]);

            $preview['entities'][$entityName] = [
                'to_restore' => count($entities),
                'existing' => $existing,
                'sample' => array_slice($entities, 0, 3), // First 3 records as sample
            ];

            $preview['total_records'] += count($entities);
        }

        return $preview;
    }

    /**
     * Restore data from backup
     *
     * @param array $backup Backup data
     * @param array $options Restore options
     * @return array Restore result with statistics
     */
    public function restoreFromBackup(array $backup, array $options = []): array
    {
        $this->statistics = [];
        $this->warnings = [];

        // Default options
        $options = array_merge([
            'missing_field_strategy' => self::STRATEGY_USE_DEFAULT,
            'existing_data_strategy' => self::EXISTING_UPDATE,
            'skip_entities' => [],
            'dry_run' => false,
            'clear_before_restore' => false, // New option: clear all data before restore
        ], $options);

        // Validate first
        $validation = $this->validateBackup($backup);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException('Invalid backup: ' . implode(', ', $validation['errors']));
        }

        $this->logger->info('Starting restore', [
            'version' => $backup['metadata']['version'],
            'options' => $options,
        ]);

        // Begin transaction
        $this->entityManager->beginTransaction();

        try {
            // Order entities by dependency (users and tenants first)
            $orderedEntities = $this->orderEntitiesByDependency(array_keys($backup['data']));

            // If clear_before_restore is set, delete all existing data first (in reverse order)
            if ($options['clear_before_restore'] && !$options['dry_run']) {
                $this->clearExistingData(array_reverse($orderedEntities), $options['skip_entities']);
            }

            foreach ($orderedEntities as $entityName) {
                if (in_array($entityName, $options['skip_entities'])) {
                    $this->logger->info('Skipping entity as per options', ['entity' => $entityName]);
                    continue;
                }

                $entities = $backup['data'][$entityName] ?? [];
                $entityClass = 'App\\Entity\\' . $entityName;

                if (!class_exists($entityClass)) {
                    continue;
                }

                $this->restoreEntity($entityClass, $entityName, $entities, $options);
            }

            if ($options['dry_run']) {
                $this->entityManager->rollback();
                $this->logger->info('Dry run completed, rolling back');
            } else {
                $this->entityManager->flush();
                $this->entityManager->commit();
                $this->logger->info('Restore completed successfully');

                // Log the restore operation
                $this->auditLogger->logImport(
                    'System Restore',
                    sprintf('Restored backup from %s', $backup['metadata']['created_at']),
                    $this->statistics
                );
            }

            return [
                'success' => true,
                'statistics' => $this->statistics,
                'warnings' => $this->warnings,
                'dry_run' => $options['dry_run'],
            ];
        } catch (\Exception $e) {
            // Try to rollback, but EntityManager might be closed
            try {
                if ($this->entityManager->isOpen() && $this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
            } catch (\Exception $rollbackException) {
                $this->logger->error('Rollback failed', [
                    'error' => $rollbackException->getMessage(),
                ]);
            }

            $this->logger->error('Restore failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Clear all existing data for the given entities
     *
     * @param array $entityNames Entities to clear (should be in reverse dependency order)
     * @param array $skipEntities Entities to skip
     */
    private function clearExistingData(array $entityNames, array $skipEntities = []): void
    {
        $this->logger->info('Clearing existing data before restore');

        foreach ($entityNames as $entityName) {
            if (in_array($entityName, $skipEntities)) {
                continue;
            }

            $entityClass = 'App\\Entity\\' . $entityName;
            if (!class_exists($entityClass)) {
                continue;
            }

            try {
                // Use DQL DELETE for efficiency
                $query = $this->entityManager->createQuery(
                    sprintf('DELETE FROM %s e', $entityClass)
                );
                $deleted = $query->execute();

                $this->logger->info('Cleared entity data', [
                    'entity' => $entityName,
                    'deleted' => $deleted,
                ]);

                $this->statistics[$entityName . '_cleared'] = $deleted;
            } catch (\Exception $e) {
                $this->warnings[] = sprintf(
                    'Failed to clear %s: %s',
                    $entityName,
                    $e->getMessage()
                );
                $this->logger->error('Failed to clear entity', [
                    'entity' => $entityName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Validate entity data structure
     *
     * @param string $entityClass
     * @param string $entityName
     * @param array $entities
     * @return void
     */
    private function validateEntityData(string $entityClass, string $entityName, array $entities): void
    {
        if (empty($entities)) {
            return;
        }

        $metadata = $this->entityManager->getClassMetadata($entityClass);
        $requiredFields = [];

        // Check for required fields (not nullable)
        foreach ($metadata->getFieldNames() as $fieldName) {
            $mapping = $metadata->getFieldMapping($fieldName);
            if (isset($mapping['nullable']) && !$mapping['nullable'] && $fieldName !== 'id') {
                $requiredFields[] = $fieldName;
            }
        }

        // Validate first entity as sample
        $firstEntity = $entities[0];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $firstEntity) || $firstEntity[$field] === null) {
                $this->warnings[] = sprintf(
                    'Required field "%s" missing in entity "%s" (can use default value strategy)',
                    $field,
                    $entityName
                );
            }
        }
    }

    /**
     * Restore a single entity type
     *
     * @param string $entityClass
     * @param string $entityName
     * @param array $entities
     * @param array $options
     * @return void
     */
    private function restoreEntity(string $entityClass, string $entityName, array $entities, array $options): void
    {
        $this->logger->info('Restoring entity', [
            'entity' => $entityName,
            'count' => count($entities),
        ]);

        $metadata = $this->entityManager->getClassMetadata($entityClass);
        $repository = $this->entityManager->getRepository($entityClass);
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // Get unique constraint fields for this entity (e.g., 'name' for Role)
        $uniqueFields = $this->getUniqueConstraintFields($entityClass, $entityName);

        foreach ($entities as $index => $data) {
            try {
                // Check if EntityManager is still open
                if (!$this->entityManager->isOpen()) {
                    $this->warnings[] = sprintf(
                        'EntityManager closed, skipping remaining %s entities from index %d',
                        $entityName,
                        $index
                    );
                    break;
                }

                // Check if entity already exists by ID first
                $existingEntity = null;
                $foundById = false;
                if (isset($data['id'])) {
                    $existingEntity = $repository->find($data['id']);
                    $foundById = ($existingEntity !== null);
                }

                // If not found by ID, check by unique constraint fields
                if ($existingEntity === null && !empty($uniqueFields)) {
                    $criteria = [];
                    foreach ($uniqueFields as $field) {
                        if (isset($data[$field])) {
                            $criteria[$field] = $data[$field];
                        }
                    }
                    if (!empty($criteria)) {
                        $existingEntity = $repository->findOneBy($criteria);
                        $this->logger->debug('Unique field lookup', [
                            'entity' => $entityName,
                            'criteria' => $criteria,
                            'found' => $existingEntity !== null,
                        ]);
                    }
                }

                // IMPORTANT: If found by ID but updating unique fields, check for conflicts
                // Example: Backup has ID=1 name="A", DB has ID=1 name="B", DB also has ID=2 name="A"
                // Updating ID=1 to name="A" would conflict with ID=2
                $conflictingEntity = null;
                if ($existingEntity !== null && $foundById && !empty($uniqueFields)) {
                    $criteria = [];
                    foreach ($uniqueFields as $field) {
                        if (isset($data[$field])) {
                            $criteria[$field] = $data[$field];
                        }
                    }
                    if (!empty($criteria)) {
                        $potentialConflict = $repository->findOneBy($criteria);
                        // If another entity (different ID) has the same unique field value
                        if ($potentialConflict !== null && $potentialConflict->getId() !== $existingEntity->getId()) {
                            $conflictingEntity = $potentialConflict;
                            $this->logger->warning('Unique field conflict detected', [
                                'entity' => $entityName,
                                'backup_id' => $data['id'],
                                'target_id' => $existingEntity->getId(),
                                'conflicting_id' => $conflictingEntity->getId(),
                                'criteria' => $criteria,
                            ]);
                        }
                    }
                }

                // Log what we're doing
                $this->logger->debug('Processing entity', [
                    'entity' => $entityName,
                    'index' => $index,
                    'has_id' => isset($data['id']),
                    'id' => $data['id'] ?? null,
                    'existing_found' => $existingEntity !== null,
                    'found_by_id' => $foundById,
                    'has_conflict' => $conflictingEntity !== null,
                    'strategy' => $options['existing_data_strategy'],
                ]);

                // Handle conflicting entity first - must resolve this before updating
                if ($conflictingEntity !== null) {
                    // We need to update or remove the conflicting entity first
                    // Skip this backup entry and log a warning
                    $this->warnings[] = sprintf(
                        'Skipping %s at index %d: unique field conflict (backup ID %s wants value that belongs to existing ID %s)',
                        $entityName,
                        $index,
                        $data['id'] ?? 'none',
                        $conflictingEntity->getId()
                    );
                    $stats['skipped']++;
                    continue;
                }

                // Handle existing data
                if ($existingEntity !== null) {
                    if ($options['existing_data_strategy'] === self::EXISTING_SKIP) {
                        $stats['skipped']++;
                        continue;
                    } elseif ($options['existing_data_strategy'] === self::EXISTING_UPDATE) {
                        $entity = $existingEntity;
                    } else {
                        // REPLACE: remove old and create new
                        $this->entityManager->remove($existingEntity);
                        $entity = new $entityClass();
                    }
                } else {
                    $entity = new $entityClass();
                }

                // Set field values
                foreach ($metadata->getFieldNames() as $fieldName) {
                    if ($fieldName === 'id' && $existingEntity !== null) {
                        continue; // Don't overwrite ID of existing entity
                    }

                    if (!array_key_exists($fieldName, $data)) {
                        // Handle missing field
                        if ($options['missing_field_strategy'] === self::STRATEGY_FAIL) {
                            throw new \RuntimeException(sprintf('Missing field: %s', $fieldName));
                        } elseif ($options['missing_field_strategy'] === self::STRATEGY_SKIP_FIELD) {
                            continue;
                        }
                        // USE_DEFAULT: continue with null or existing value
                    }

                    $value = $data[$fieldName] ?? null;

                    // Convert ISO 8601 strings back to DateTime
                    $type = $metadata->getTypeOfField($fieldName);
                    if (in_array($type, ['datetime', 'datetime_immutable', 'date', 'time']) && is_string($value)) {
                        $value = new \DateTime($value);
                        if ($type === 'datetime_immutable') {
                            $value = \DateTimeImmutable::createFromMutable($value);
                        }
                    }

                    try {
                        if ($propertyAccessor->isWritable($entity, $fieldName)) {
                            $propertyAccessor->setValue($entity, $fieldName, $value);
                        } else {
                            // Use reflection for non-accessible properties
                            $reflection = new \ReflectionClass($entity);
                            if ($reflection->hasProperty($fieldName)) {
                                $property = $reflection->getProperty($fieldName);
                                $property->setAccessible(true);
                                $property->setValue($entity, $value);
                            }
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to set property', [
                            'entity' => $entityName,
                            'field' => $fieldName,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Restore associations (ManyToOne, OneToOne)
                foreach ($metadata->getAssociationNames() as $assocName) {
                    if ($metadata->isSingleValuedAssociation($assocName)) {
                        $assocIdKey = $assocName . '_id';
                        if (isset($data[$assocIdKey])) {
                            $targetClass = $metadata->getAssociationTargetClass($assocName);
                            $assocId = $data[$assocIdKey];

                            // Handle array format from backup (e.g., ['id' => 5])
                            if (is_array($assocId) && isset($assocId['id'])) {
                                $assocId = $assocId['id'];
                            }

                            if ($assocId !== null) {
                                try {
                                    $relatedEntity = $this->entityManager->getReference($targetClass, $assocId);
                                    $propertyAccessor->setValue($entity, $assocName, $relatedEntity);
                                } catch (\Exception $e) {
                                    $this->logger->warning('Failed to restore association', [
                                        'entity' => $entityName,
                                        'association' => $assocName,
                                        'target_id' => $assocId,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }
                    }
                    // Skip collections (ManyToMany, OneToMany) for now - they're more complex
                }

                // Persist entity
                $this->entityManager->persist($entity);

                if ($existingEntity !== null) {
                    $stats['updated']++;
                } else {
                    $stats['created']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $this->warnings[] = sprintf(
                    'Error restoring %s entity at index %d: %s',
                    $entityName,
                    $index,
                    $e->getMessage()
                );
                $this->logger->error('Error restoring entity', [
                    'entity' => $entityName,
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                // If EntityManager is closed due to error, we can't continue
                if (!$this->entityManager->isOpen()) {
                    $this->warnings[] = 'EntityManager closed due to database error. Restore aborted.';
                    break;
                }
            }
        }

        // Flush all entities of this type at once
        if ($this->entityManager->isOpen()) {
            try {
                $this->entityManager->flush();
            } catch (\Exception $e) {
                $stats['errors']++;
                $this->warnings[] = sprintf(
                    'Error flushing %s entities (database constraint error): %s',
                    $entityName,
                    $e->getMessage()
                );
                $this->logger->error('Error flushing entities', [
                    'entity' => $entityName,
                    'error' => $e->getMessage(),
                ]);

                // Check if EntityManager is closed
                if (!$this->entityManager->isOpen()) {
                    $this->warnings[] = sprintf(
                        'EntityManager closed after %s flush error. Remaining entities will be skipped.',
                        $entityName
                    );
                }
            }
        }

        $this->statistics[$entityName] = $stats;

        $this->logger->info('Entity restore completed', [
            'entity' => $entityName,
            'statistics' => $stats,
        ]);
    }

    /**
     * Get unique constraint fields for an entity
     *
     * @param string $entityClass
     * @param string $entityName
     * @return array
     */
    private function getUniqueConstraintFields(string $entityClass, string $entityName): array
    {
        // Map of entity names to their unique constraint fields
        $uniqueFieldsMap = [
            'Role' => ['name'],
            'User' => ['email'],
            'Tenant' => ['slug'],
            'Permission' => ['name'],
            'ComplianceFramework' => ['code'],
            'Control' => ['controlId'],
            'ComplianceRequirement' => ['requirementId'],
        ];

        return $uniqueFieldsMap[$entityName] ?? [];
    }

    /**
     * Order entities by dependency
     * Users and Tenants should be restored first
     *
     * @param array $entityNames
     * @return array
     */
    private function orderEntitiesByDependency(array $entityNames): array
    {
        // Priority order: lower number = restored first
        // Entities without foreign keys first, then entities that depend on them
        $priorityOrder = [
            // Base entities (no dependencies)
            'Tenant' => 1,
            'User' => 2,
            'Role' => 3,
            'Permission' => 4,
            'SystemSettings' => 5,
            'Location' => 6,

            // Framework/Control entities (depend on base)
            'ComplianceFramework' => 10,
            'Control' => 11,

            // Entities that depend on frameworks/controls
            'ComplianceRequirement' => 20,
            'Risk' => 21,
            'Asset' => 22,
            'Supplier' => 23,
            'InterestedParty' => 24,
            'ISMSContext' => 25,
            'ISMSObjective' => 26,

            // Mapping entities (depend on requirements)
            'ComplianceMapping' => 30,
            'MappingGapItem' => 31,

            // Logs and audit trails (last)
            'AuditLog' => 90,
            'UserSession' => 91,
        ];

        usort($entityNames, function ($a, $b) use ($priorityOrder) {
            $priorityA = $priorityOrder[$a] ?? 50; // Default priority for unknown entities
            $priorityB = $priorityOrder[$b] ?? 50;

            return $priorityA <=> $priorityB;
        });

        return $entityNames;
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get warnings
     *
     * @return array
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Get statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }
}
