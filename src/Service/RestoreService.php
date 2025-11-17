<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
        private LoggerInterface $logger,
        private UserPasswordHasherInterface $passwordHasher
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
            // Disable foreign key checks for the duration of restore
            // This prevents FK constraint violations during entity restoration
            // Also needed for dry-run to properly test the restore process
            $connection = $this->entityManager->getConnection();
            $disableFKChecks = false;

            try {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
                $disableFKChecks = true;
                $this->logger->info('Disabled foreign key checks for restore');
            } catch (\Exception $e) {
                $this->logger->warning('Could not disable foreign key checks', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Disable Doctrine event listeners during restore to prevent PrePersist/PreUpdate callbacks
            // from overwriting restored data with incorrect types (e.g., DateTimeImmutable instead of DateTime)
            $eventManager = $this->entityManager->getEventManager();
            $originalListeners = [];
            $eventsToDisable = [
                \Doctrine\ORM\Events::prePersist,
                \Doctrine\ORM\Events::preUpdate,
                \Doctrine\ORM\Events::postPersist,
                \Doctrine\ORM\Events::postUpdate,
            ];

            // Get listeners for each event (Symfony's ContainerAwareEventManager requires event name)
            foreach ($eventsToDisable as $eventName) {
                try {
                    $listeners = $eventManager->getListeners($eventName);
                    $originalListeners[$eventName] = $listeners;
                    foreach ($listeners as $listener) {
                        $eventManager->removeEventListener($eventName, $listener);
                    }
                } catch (\Exception $e) {
                    $originalListeners[$eventName] = [];
                    $this->logger->debug('No listeners for event', [
                        'event' => $eventName,
                    ]);
                }
            }
            $this->logger->info('Disabled Doctrine lifecycle event listeners for restore');

            // Order entities by dependency (users and tenants first)
            $orderedEntities = $this->orderEntitiesByDependency(array_keys($backup['data']));

            // If clear_before_restore is set, delete all existing data first (in reverse order)
            // This applies to BOTH dry-run and real restore - dry-run will rollback at the end anyway
            if ($options['clear_before_restore']) {
                $this->clearExistingData(array_reverse($orderedEntities), $options['skip_entities']);
                // Clear the identity map after deleting to avoid stale references
                $this->entityManager->clear();
                if ($options['dry_run']) {
                    $this->warnings[] = 'Dry-run: Bestehende Daten wurden gelöscht (wird am Ende zurückgesetzt)';
                }
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

                // Re-enable foreign key checks after dry-run
                if ($disableFKChecks) {
                    try {
                        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                        $this->logger->info('Re-enabled foreign key checks after dry-run');
                    } catch (\Exception $fkException) {
                        $this->logger->error('Failed to re-enable foreign key checks', [
                            'error' => $fkException->getMessage(),
                        ]);
                    }
                }

                // Re-enable Doctrine event listeners after dry-run
                foreach ($originalListeners as $eventName => $listeners) {
                    foreach ($listeners as $listener) {
                        $eventManager->addEventListener($eventName, $listener);
                    }
                }
                $this->logger->info('Re-enabled Doctrine lifecycle event listeners after dry-run');
            } else {
                // Check if EntityManager is still open before final commit
                if (!$this->entityManager->isOpen()) {
                    $this->warnings[] = 'EntityManager was closed during restore. Some changes may not have been persisted.';
                    $this->logger->warning('EntityManager closed before final commit');

                    // Re-enable foreign key checks before returning
                    if ($disableFKChecks) {
                        try {
                            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                        } catch (\Exception $fkException) {
                            $this->logger->error('Failed to re-enable foreign key checks', [
                                'error' => $fkException->getMessage(),
                            ]);
                        }
                    }

                    // Re-enable Doctrine event listeners
                    foreach ($originalListeners as $eventName => $listeners) {
                        foreach ($listeners as $listener) {
                            $eventManager->addEventListener($eventName, $listener);
                        }
                    }

                    // Return partial success with warnings
                    return [
                        'success' => false,
                        'message' => 'Wiederherstellung teilweise fehlgeschlagen: Der EntityManager wurde während des Vorgangs geschlossen. Einige Daten wurden möglicherweise nicht gespeichert. Bitte aktivieren Sie "Bestehende Daten löschen" und versuchen Sie es erneut.',
                        'statistics' => $this->statistics,
                        'warnings' => $this->warnings,
                        'dry_run' => $options['dry_run'],
                    ];
                }

                $this->entityManager->flush();
                $this->entityManager->commit();
                $this->logger->info('Restore completed successfully');

                // Re-enable foreign key checks
                if ($disableFKChecks) {
                    try {
                        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                        $this->logger->info('Re-enabled foreign key checks');
                    } catch (\Exception $fkException) {
                        $this->logger->error('Failed to re-enable foreign key checks', [
                            'error' => $fkException->getMessage(),
                        ]);
                    }
                }

                // Re-enable Doctrine event listeners
                foreach ($originalListeners as $eventName => $listeners) {
                    foreach ($listeners as $listener) {
                        $eventManager->addEventListener($eventName, $listener);
                    }
                }
                $this->logger->info('Re-enabled Doctrine lifecycle event listeners');

                // Log the restore operation
                $totalRestored = 0;
                foreach ($this->statistics as $entityStats) {
                    if (is_array($entityStats) && isset($entityStats['created'])) {
                        $totalRestored += $entityStats['created'] + ($entityStats['updated'] ?? 0);
                    }
                }
                $this->auditLogger->logImport(
                    'Backup',
                    $totalRestored,
                    sprintf('Restored backup from %s. Statistics: %s', $backup['metadata']['created_at'], json_encode($this->statistics))
                );
            }

            // Add warning about password fields not being restored (security feature)
            if (isset($this->statistics['User']) && $this->statistics['User']['created'] > 0 && !$options['dry_run']) {
                // If admin password was provided, set it for the first admin user
                if (!empty($options['admin_password'])) {
                    $this->setAdminPassword($options['admin_password']);
                } else {
                    $this->warnings[] = 'WICHTIG: Benutzer-Passwörter werden aus Sicherheitsgründen nicht im Backup gespeichert. Alle wiederhergestellten Benutzer müssen ihr Passwort zurücksetzen oder neu setzen lassen. Nutzen Sie: php bin/console app:setup-permissions --admin-email=EMAIL --admin-password=PASSWORT';
                }
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

            // Re-enable foreign key checks in case of error
            if (isset($disableFKChecks) && $disableFKChecks) {
                try {
                    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                } catch (\Exception $fkException) {
                    $this->logger->error('Failed to re-enable foreign key checks after error', [
                        'error' => $fkException->getMessage(),
                    ]);
                }
            }

            // Re-enable Doctrine event listeners in case of error
            if (isset($originalListeners) && isset($eventManager)) {
                foreach ($originalListeners as $eventName => $listeners) {
                    foreach ($listeners as $listener) {
                        $eventManager->addEventListener($eventName, $listener);
                    }
                }
            }

            $this->logger->error('Restore failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Check if this is an EntityManager closed error
            if (strpos($e->getMessage(), 'EntityManager is closed') !== false) {
                return [
                    'success' => false,
                    'message' => 'Der EntityManager wurde geschlossen (Datenbankfehler). Bitte aktivieren Sie "Bestehende Daten löschen" um Konflikte zu vermeiden.',
                    'statistics' => $this->statistics,
                    'warnings' => $this->warnings,
                    'dry_run' => $options['dry_run'],
                ];
            }

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

        // If clear_before_restore is enabled, temporarily change ID generator to NONE
        // This allows us to preserve original IDs from the backup
        $originalIdGenerator = null;
        $originalGeneratorType = null;
        if ($options['clear_before_restore']) {
            $originalIdGenerator = $metadata->idGenerator;
            $originalGeneratorType = $metadata->generatorType;
            $metadata->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
            $metadata->setIdGenerator(new \Doctrine\ORM\Id\AssignedGenerator());
            $this->logger->debug('Changed ID generator to ASSIGNED for entity', [
                'entity' => $entityName,
            ]);
        }

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

                    // For new entities with clear_before_restore, preserve the original ID
                    // This is critical for maintaining foreign key references
                    if ($fieldName === 'id' && $existingEntity === null && isset($data['id']) && $options['clear_before_restore']) {
                        // Set the ID using reflection to bypass Doctrine's ID generation
                        try {
                            $reflection = new \ReflectionClass($entity);
                            if ($reflection->hasProperty('id')) {
                                $property = $reflection->getProperty('id');
                                $property->setAccessible(true);
                                $property->setValue($entity, $data['id']);
                                $this->logger->debug('Set entity ID from backup', [
                                    'entity' => $entityName,
                                    'id' => $data['id'],
                                ]);
                            }
                        } catch (\Exception $e) {
                            $this->logger->warning('Failed to set entity ID from backup', [
                                'entity' => $entityName,
                                'id' => $data['id'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                        continue;
                    }

                    // Skip foreign key fields that are handled via associations
                    // These end with _id and represent ManyToOne relationships
                    if (str_ends_with($fieldName, '_id') && $fieldName !== 'id') {
                        // Check if this is actually a mapped association
                        $assocName = substr($fieldName, 0, -3); // Remove _id suffix
                        if ($metadata->hasAssociation($assocName)) {
                            continue; // Will be handled in association restoration below
                        }
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

                    // Convert ISO 8601 strings back to DateTime/DateTimeImmutable
                    $type = $metadata->getTypeOfField($fieldName);
                    if (in_array($type, ['datetime', 'datetime_immutable', 'date', 'date_immutable', 'time', 'time_immutable'])) {
                        try {
                            // Check if type expects immutable or mutable
                            $expectsImmutable = str_contains($type, 'immutable');

                            if (is_string($value)) {
                                // Convert string to appropriate DateTime type
                                if ($expectsImmutable) {
                                    $value = new \DateTimeImmutable($value);
                                } else {
                                    $value = new \DateTime($value);
                                }
                            } elseif ($value instanceof \DateTimeImmutable && !$expectsImmutable) {
                                // Convert DateTimeImmutable to DateTime for mutable types
                                $value = \DateTime::createFromImmutable($value);
                            } elseif ($value instanceof \DateTime && $expectsImmutable) {
                                // Convert DateTime to DateTimeImmutable for immutable types
                                $value = \DateTimeImmutable::createFromMutable($value);
                            }
                            // If value is already the correct type, leave it as is
                        } catch (\Exception $dateException) {
                            $this->logger->warning('Failed to parse date/time value', [
                                'entity' => $entityName,
                                'field' => $fieldName,
                                'value' => is_object($value) ? get_class($value) : $value,
                                'error' => $dateException->getMessage(),
                            ]);
                            // Keep original value and let Doctrine handle it
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
                        // Log but don't fail - try to continue with other fields
                        $this->logger->warning('Failed to set property', [
                            'entity' => $entityName,
                            'field' => $fieldName,
                            'value_type' => is_object($value) ? get_class($value) : gettype($value),
                            'error' => $e->getMessage(),
                        ]);
                        $this->warnings[] = sprintf(
                            'Warning: Could not set %s.%s: %s',
                            $entityName,
                            $fieldName,
                            $e->getMessage()
                        );
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

        // Restore original ID generator if it was changed
        if ($originalIdGenerator !== null && $originalGeneratorType !== null) {
            $metadata->setIdGeneratorType($originalGeneratorType);
            $metadata->setIdGenerator($originalIdGenerator);
            $this->logger->debug('Restored original ID generator for entity', [
                'entity' => $entityName,
            ]);
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
        // IMPORTANT: If entity A has ManyToOne to entity B, then B must have lower priority than A
        $priorityOrder = [
            // Base entities (no dependencies except tenant)
            'Tenant' => 1,
            'User' => 2,
            'Role' => 3,
            'Permission' => 4,
            'SystemSettings' => 5,
            'Location' => 6,

            // Framework/Control entities (depend on base)
            'ComplianceFramework' => 10,
            'Control' => 11,

            // Independent entities (depend only on Tenant)
            'Asset' => 15,           // Asset has no other dependencies
            'Supplier' => 16,        // Supplier has no other dependencies
            'InterestedParty' => 17, // InterestedParty has no other dependencies

            // Entities that depend on frameworks/controls
            'ComplianceRequirement' => 20,

            // Risk depends on Asset, so must come after Asset
            'Risk' => 21,

            // Context entities (may depend on other entities)
            'ISMSContext' => 25,
            'ISMSObjective' => 26,

            // Mapping entities (depend on requirements and controls)
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
     * Set password for the first admin user after restore
     *
     * @param string $password Plain text password
     * @return void
     */
    private function setAdminPassword(string $password): void
    {
        try {
            // Find the first user with ROLE_ADMIN
            $adminUser = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
                ->where('u.roles LIKE :role')
                ->setParameter('role', '%ROLE_ADMIN%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($adminUser === null) {
                // Find the first user if no admin role found
                $adminUser = $this->entityManager->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);
            }

            if ($adminUser !== null) {
                $hashedPassword = $this->passwordHasher->hashPassword($adminUser, $password);
                $adminUser->setPassword($hashedPassword);
                $this->entityManager->flush();

                $this->warnings[] = sprintf(
                    'Admin-Passwort wurde für Benutzer "%s" gesetzt.',
                    $adminUser->getEmail()
                );
                $this->logger->info('Set admin password after restore', [
                    'user_email' => $adminUser->getEmail(),
                ]);
            } else {
                $this->warnings[] = 'Kein Benutzer gefunden, um Admin-Passwort zu setzen.';
            }
        } catch (\Exception $e) {
            $this->warnings[] = sprintf(
                'Fehler beim Setzen des Admin-Passworts: %s',
                $e->getMessage()
            );
            $this->logger->error('Failed to set admin password', [
                'error' => $e->getMessage(),
            ]);
        }
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
