<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\BackupEncryptionService;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Exception;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Id\AssignedGenerator;
use ReflectionClass;
use RuntimeException;
use DateTimeImmutable;
use DateTime;
use Doctrine\ORM\Id\AbstractIdGenerator;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

class RestoreService
{
    /**
     * Backup format versions that this RestoreService can handle.
     * '1.0' = legacy JSON-only (no schema_version in metadata)
     * '2.0' = JSON + optional ZIP-embedded files + schema_version + app_version
     */
    private const array SUPPORTED_VERSIONS = ['1.0', '2.0'];

    // Strategies for handling missing fields
    public const string STRATEGY_SKIP_FIELD = 'skip_field';
    public const string STRATEGY_USE_DEFAULT = 'use_default';
    public const string STRATEGY_FAIL = 'fail';

    // Strategies for handling existing data
    public const string EXISTING_SKIP = 'skip';
    public const string EXISTING_UPDATE = 'update';
    public const string EXISTING_REPLACE = 'replace';

    private array $validationErrors = [];
    private array $warnings = [];
    private array $statistics = [];

    /** Accumulated row-level failures when best_effort=true. */
    private array $failures = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly UserPasswordHasherInterface $userPasswordHasher,
        private readonly ?BackupEncryptionService $backupEncryption = null,
        private readonly ?ManagerRegistry $registry = null,
    ) {
    }

    /**
     * Validate backup data.
     *
     * Checks:
     *  - metadata section present
     *  - format version supported (major-version check)
     *  - schema_version warning if present and different from current
     *  - data section present and valid per entity
     *
     * Legacy backups (version missing) are assumed to be format 1.0 and accepted
     * with a warning so old JSON-only backups remain restoreable.
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
        if ($version === null) {
            // Legacy backup without version field — treat as 1.0 (JSON-only)
            $this->warnings[] = 'Backup has no format version (assumed legacy 1.0). File restore will be skipped.';
        } elseif (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
            // Major-version guard: reject if major part differs from all supported
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

            // Validate entity structure
            $this->validateEntityData($entityClass, $entityName, $entities);
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
     * Restore data from backup.
     *
     * When $targetTenantScope is provided, only entities belonging to that tenant
     * (and its subsidiaries for holding tenants) are restored.  Entities from
     * other tenants present in the backup are silently skipped.
     *
     * The clear_before_restore option is also tenant-aware: when a scope is active,
     * only rows belonging to the scope tree are deleted before the restore, leaving
     * all other tenants' data untouched.
     *
     * A cross-tenant warning is emitted when the backup's recorded tenant_scope does
     * not overlap with $targetTenantScope.
     *
     * @param array       $backup            Backup data
     * @param array       $options           Restore options
     * @param Tenant|null $targetTenantScope When set, restricts restore to this tenant's data
     * @return array Restore result with statistics
     * @throws \Doctrine\DBAL\Exception
     */
    public function restoreFromBackup(array $backup, array $options = [], ?Tenant $targetTenantScope = null): array
    {
        $this->statistics = [];
        $this->warnings   = [];
        $this->failures   = [];

        // --- SHA256 integrity check (Feature 1) ---
        // verifyIntegrity() may return a legacy-backup warning. We collect it here so it
        // survives the validateBackup() call that follows (which resets $this->warnings).
        // In best_effort mode a hash mismatch is demoted to a warning + failure record.
        $bestEffort = (bool) ($options['best_effort'] ?? false);
        try {
            $integrityWarning = $this->verifyIntegrity($backup);
        } catch (RuntimeException $integrityException) {
            if ($bestEffort) {
                $this->logger->warning('Best-effort: SHA256 mismatch — continuing anyway', [
                    'error' => $integrityException->getMessage(),
                ]);
                $integrityWarning = 'Best-effort: ' . $integrityException->getMessage();
                $this->failures[] = [
                    'entity'        => '__integrity__',
                    'row_index'     => null,
                    'row_id'        => null,
                    'error_class'   => $integrityException::class,
                    'error_message' => $integrityException->getMessage(),
                    'original_data' => [],
                ];
            } else {
                throw $integrityException;
            }
        }

        // Resolve scope IDs for the target tenant (empty = global)
        $targetScopeIds = $this->resolveTenantScopeIds($targetTenantScope);

        // Collect warnings that must survive the validateBackup() reset into a local variable.
        $prependWarnings = array_filter([$integrityWarning]);
        if ($targetTenantScope !== null) {
            $backupScopeIds = $backup['metadata']['tenant_scope'] ?? [];
            if ($backupScopeIds !== [] && array_intersect($targetScopeIds, $backupScopeIds) === []) {
                $prependWarnings[] = sprintf(
                    'Cross-Tenant-Restore: Das Backup wurde für Tenant-IDs [%s] erstellt, '
                    . 'aber die Wiederherstellung erfolgt für Tenant-IDs [%s]. '
                    . 'Stellen Sie sicher, dass dies beabsichtigt ist.',
                    implode(', ', $backupScopeIds),
                    implode(', ', $targetScopeIds)
                );
            }
        }

        // Default options
        $options = array_merge([
            'missing_field_strategy' => self::STRATEGY_USE_DEFAULT,
            'existing_data_strategy' => self::EXISTING_UPDATE,
            'skip_entities' => [],
            'dry_run' => false,
            'clear_before_restore' => false, // New option: clear all data before restore
            'best_effort' => false,          // P5: opt-in — skip failing rows instead of aborting
        ], $options);

        // Validate first (this resets $this->warnings internally)
        $validation = $this->validateBackup($backup);
        if (!$validation['valid']) {
            throw new InvalidArgumentException('Invalid backup: ' . implode(', ', $validation['errors']));
        }

        // Re-apply cross-tenant warning that was collected before validateBackup() reset warnings
        foreach ($prependWarnings as $warning) {
            $this->warnings[] = $warning;
        }

        $this->logger->info('Starting restore', [
            'version' => $backup['metadata']['version'],
            'options' => $options,
        ]);

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
            } catch (Exception $e) {
                $this->logger->warning('Could not disable foreign key checks', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Disable Doctrine event listeners during restore to prevent PrePersist/PreUpdate callbacks
            // from overwriting restored data with incorrect types (e.g., DateTimeImmutable instead of DateTime)
            $eventManager = $this->entityManager->getEventManager();
            $originalListeners = [];
            $eventsToDisable = [
                Events::prePersist,
                Events::preUpdate,
                Events::postPersist,
                Events::postUpdate,
            ];

            // Get listeners for each event (Symfony's ContainerAwareEventManager requires event name)
            foreach ($eventsToDisable as $eventName) {
                try {
                    $listeners = $eventManager->getListeners($eventName);
                    $originalListeners[$eventName] = $listeners;
                    foreach ($listeners as $listener) {
                        $eventManager->removeEventListener($eventName, $listener);
                    }
                } catch (Exception) {
                    $originalListeners[$eventName] = [];
                    $this->logger->debug('No listeners for event', [
                        'event' => $eventName,
                    ]);
                }
            }
            $this->logger->info('Disabled Doctrine lifecycle event listeners for restore');

            // Decrypt sensitive SystemSettings values before restore (Feature 2).
            // In best_effort mode, per-row decryption failures are caught inside
            // restoreEntity() when $options['best_effort'] is true.
            if ($this->backupEncryption !== null && isset($backup['data']['SystemSettings'])) {
                if ($options['best_effort']) {
                    // Defer decryption to per-row level inside restoreEntity() so
                    // a bad key on one row does not abort the whole entity-type.
                    // We mark the rows so restoreEntity() knows they need decryption.
                    $backup['data']['SystemSettings'] = $this->markSystemSettingsForDecryption(
                        $backup['data']['SystemSettings']
                    );
                } else {
                    $backup['data']['SystemSettings'] = $this->decryptSystemSettingsValues($backup['data']['SystemSettings']);
                }
            }

            // Order entities by dependency (users and tenants first)
            $orderedEntities = $this->orderEntitiesByDependency(array_keys($backup['data']));

            // If clear_before_restore is set, delete all existing data first (in reverse order)
            // IMPORTANT: We do NOT start transaction before clearExistingData because:
            // - clearExistingData() uses ALTER TABLE which causes implicit COMMIT in MySQL
            // - Starting transaction first would just get committed anyway
            // - We start the transaction AFTER clearExistingData instead
            if ($options['clear_before_restore']) {
                $this->clearExistingData(array_reverse($orderedEntities), $options['skip_entities'], $targetScopeIds);
                // Clear the identity map after deleting to avoid stale references
                $this->entityManager->clear();

                if ($options['dry_run']) {
                    $this->warnings[] = 'Dry-run: Bestehende Daten wurden gelöscht (wird am Ende zurückgesetzt)';
                }
            }

            // Start transaction for entity restoration
            // Must be AFTER clearExistingData to avoid ALTER TABLE's implicit COMMIT
            $this->entityManager->beginTransaction();
            $this->logger->info('Started transaction for entity restoration');

            // Enable nested transactions (savepoints) when best_effort mode is active.
            if ($options['best_effort']) {
                try {
                    $connection->setNestTransactionsWithSavepoints(true);
                } catch (\Throwable) {
                    // Driver may not support savepoints — best-effort will still work
                    // but without savepoint isolation (row errors abort the entity-type batch).
                    $this->logger->warning('Best-effort: driver does not support nested savepoints — row isolation disabled');
                }
            }

            // First pass: restore all scalar fields and single-valued (ManyToOne/OneToOne) associations
            foreach ($orderedEntities as $orderedEntity) {
                if (in_array($orderedEntity, $options['skip_entities'])) {
                    $this->logger->info('Skipping entity as per options', ['entity' => $orderedEntity]);
                    continue;
                }

                $allEntities = $backup['data'][$orderedEntity] ?? [];
                $entityClass = 'App\\Entity\\' . $orderedEntity;

                if (!class_exists($entityClass)) {
                    continue;
                }

                // Tenant-scope filtering: only restore entities belonging to the target scope
                $entities = $this->filterEntitiesByScope($allEntities, $targetScopeIds);

                if ($targetScopeIds !== [] && count($entities) < count($allEntities)) {
                    $this->logger->info('Tenant-scoped restore: filtered entities', [
                        'entity'  => $orderedEntity,
                        'total'   => count($allEntities),
                        'in_scope' => count($entities),
                        'skipped'  => count($allEntities) - count($entities),
                    ]);
                }

                if ($options['best_effort']) {
                    // Wrap each entity-type in a savepoint so a hard failure on flush
                    // only rolls back that entity-type, not the whole restore.
                    $spName = 'sp_' . preg_replace('/[^a-zA-Z0-9]/', '_', $orderedEntity);
                    $savepointCreated = false;
                    try {
                        $connection->createSavepoint($spName);
                        $savepointCreated = true;
                    } catch (\Throwable) {
                        // Savepoint creation failed — fall through without savepoint isolation
                    }

                    try {
                        $this->restoreEntity($entityClass, $orderedEntity, $entities, $options);
                        if ($savepointCreated) {
                            try {
                                $connection->releaseSavepoint($spName);
                            } catch (\Throwable) {
                                // Non-critical; continue
                            }
                        }
                    } catch (\Throwable $entityTypeError) {
                        // Entity-type level failure in best_effort mode
                        $this->logger->warning('Best-effort: entity-type restore failed, rolling back savepoint', [
                            'entity' => $orderedEntity,
                            'error'  => $entityTypeError->getMessage(),
                        ]);

                        if ($savepointCreated) {
                            try {
                                $connection->rollbackSavepoint($spName);
                            } catch (\Throwable) {
                                // Ignore rollback errors
                            }
                        }

                        $this->failures[] = [
                            'entity'        => $orderedEntity,
                            'row_index'     => null,
                            'row_id'        => null,
                            'error_class'   => $entityTypeError::class,
                            'error_message' => $entityTypeError->getMessage(),
                            'original_data' => [],
                        ];

                        // If EntityManager was closed by the error, try to reopen it
                        if (!$this->entityManager->isOpen() && $this->registry !== null) {
                            try {
                                $this->registry->resetManager();
                                $this->logger->info('Best-effort: EntityManager was reset after entity-type failure', [
                                    'entity' => $orderedEntity,
                                ]);
                            } catch (\Throwable $resetError) {
                                $this->logger->warning('Best-effort: could not reset EntityManager', [
                                    'error' => $resetError->getMessage(),
                                ]);
                            }
                        }
                    }
                } else {
                    $this->restoreEntity($entityClass, $orderedEntity, $entities, $options);
                }
            }

            // Second pass: restore ManyToMany associations via direct DBAL pivot-table inserts.
            // Must run AFTER all entity rows are flushed (done inside restoreEntity) so that all
            // referenced IDs exist in the database when we write the pivot rows.
            // Skipped in dry_run mode because the transaction is rolled back anyway.
            if (!$options['dry_run'] && $this->entityManager->isOpen()) {
                $this->logger->info('Starting ManyToMany second-pass restore');
                foreach ($orderedEntities as $orderedEntity) {
                    if (in_array($orderedEntity, $options['skip_entities'])) {
                        continue;
                    }
                    $allEntities  = $backup['data'][$orderedEntity] ?? [];
                    $entityClass  = 'App\\Entity\\' . $orderedEntity;
                    if (!class_exists($entityClass) || $allEntities === []) {
                        continue;
                    }
                    // Apply the same scope filter for the M2M second-pass
                    $entities = $this->filterEntitiesByScope($allEntities, $targetScopeIds);
                    if ($entities === []) {
                        continue;
                    }
                    $this->restoreManyToManyAssociations($entityClass, $orderedEntity, $entities);
                }
                $this->logger->info('ManyToMany second-pass restore completed');
            }

            if ($options['dry_run']) {
                $this->entityManager->rollback();
                $this->logger->info('Dry run completed, rolling back');

                // Re-enable foreign key checks after dry-run
                if ($disableFKChecks) {
                    try {
                        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                        $this->logger->info('Re-enabled foreign key checks after dry-run');
                    } catch (Exception $fkException) {
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
                        } catch (Exception $fkException) {
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
                        'success'    => false,
                        'message'    => 'Wiederherstellung teilweise fehlgeschlagen: Der EntityManager wurde während des Vorgangs geschlossen. Einige Daten wurden möglicherweise nicht gespeichert. Bitte aktivieren Sie "Bestehende Daten löschen" und versuchen Sie es erneut.',
                        'statistics' => $this->statistics,
                        'warnings'   => $this->warnings,
                        'failures'   => $this->failures,
                        'dry_run'    => $options['dry_run'],
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
                    } catch (Exception $fkException) {
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
                foreach ($this->statistics as $statistic) {
                    if (is_array($statistic) && isset($statistic['created'])) {
                        $totalRestored += $statistic['created'] + ($statistic['updated'] ?? 0);
                    }
                }
                $this->auditLogger->logImport(
                    'Backup',
                    $totalRestored,
                    sprintf('Restored backup from %s. Statistics: %s', $backup['metadata']['created_at'] ?? 'unknown', json_encode($this->statistics))
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
                'success'    => true,
                'statistics' => $this->statistics,
                'warnings'   => $this->warnings,
                'failures'   => $this->failures,
                'dry_run'    => $options['dry_run'],
            ];
        } catch (Exception $e) {
            // Try to rollback, but EntityManager might be closed
            try {
                if ($this->entityManager->isOpen() && $this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
            } catch (Exception $rollbackException) {
                $this->logger->error('Rollback failed', [
                    'error' => $rollbackException->getMessage(),
                ]);
            }

            // Re-enable foreign key checks in case of error
            if ($disableFKChecks) {
                try {
                    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                } catch (Exception $fkException) {
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
            if (str_contains($e->getMessage(), 'EntityManager is closed')) {
                return [
                    'success'    => false,
                    'message'    => 'Der EntityManager wurde geschlossen (Datenbankfehler). Bitte aktivieren Sie "Bestehende Daten löschen" um Konflikte zu vermeiden.',
                    'statistics' => $this->statistics,
                    'warnings'   => $this->warnings,
                    'failures'   => $this->failures,
                    'dry_run'    => $options['dry_run'],
                ];
            }

            throw $e;
        }
    }

    /**
     * Clear all existing data for the given entities.
     *
     * When $tenantScopeIds is non-empty (tenant-scoped restore), only rows belonging
     * to those tenant IDs are deleted, leaving all other tenants' data untouched.
     * The AUTO_INCREMENT reset is then skipped (global delete would have done that).
     *
     * @param array $entityNames     Entities to clear (should be in reverse dependency order)
     * @param array $skipEntities    Entities to skip
     * @param int[] $tenantScopeIds  When non-empty, only delete rows for these tenant IDs
     */
    private function clearExistingData(array $entityNames, array $skipEntities = [], array $tenantScopeIds = []): void
    {
        $this->logger->info('Clearing existing data before restore', [
            'scope_ids' => $tenantScopeIds,
        ]);

        $connection = $this->entityManager->getConnection();

        // Step 1: Collect and DELETE all ManyToMany pivot tables first (before DQL entity deletes).
        // DQL DELETE FROM Entity does not touch pivot tables — without this step orphan FK rows
        // remain in the pivot tables after a clear_before_restore restore, corrupting the dataset.
        // For tenant-scoped clears we pass scope information for filtering.
        $this->clearPivotTables($entityNames, $skipEntities, $connection, $tenantScopeIds);

        foreach ($entityNames as $entityName) {
            if (in_array($entityName, $skipEntities)) {
                continue;
            }

            $entityClass = 'App\\Entity\\' . $entityName;
            if (!class_exists($entityClass)) {
                continue;
            }

            try {
                // Get table name for this entity
                $classMetadata = $this->entityManager->getClassMetadata($entityClass);
                $tableName = $classMetadata->getTableName();

                // Tenant-scoped clear: use WHERE tenant_id IN (:scope) when possible
                if ($tenantScopeIds !== [] && $this->entityHasTenantAssociation($entityClass)) {
                    $qb = $this->entityManager->createQueryBuilder()
                        ->delete($entityClass, 'e')
                        ->where('e.tenant IN (:scope)')
                        ->setParameter('scope', $tenantScopeIds);
                    $deleted = $qb->getQuery()->execute();

                    $this->logger->info('Cleared tenant-scoped entity data', [
                        'entity'    => $entityName,
                        'deleted'   => $deleted,
                        'scope_ids' => $tenantScopeIds,
                    ]);
                    $this->statistics[$entityName . '_cleared'] = $deleted;
                    // Do NOT reset AUTO_INCREMENT for scoped deletes (other tenants still have rows)
                    continue;
                }

                // Use DQL DELETE for efficiency
                $query = $this->entityManager->createQuery(
                    sprintf('DELETE FROM %s e', $entityClass)
                );
                $deleted = $query->execute();

                // Reset AUTO_INCREMENT to 1 after clearing table — so that
                // restored entities can use their original IDs.
                //
                // DB-Dialect-aware: MySQL/MariaDB nutzen `ALTER TABLE ... AUTO_INCREMENT`,
                // PostgreSQL hätte `ALTER SEQUENCE ... RESTART`, SQLite hat keinen Bedarf
                // (AUTO_INCREMENT startet automatisch neu nach DELETE ohne ROWID-Gap).
                // Symfony-App ist aktuell MySQL-only — wir probieren MySQL-Syntax
                // und loggen Warning falls nicht unterstützt (non-critical).
                try {
                    $connection = $this->entityManager->getConnection();
                    $platform = $connection->getDatabasePlatform()::class;
                    $isMysql = stripos($platform, 'MySQL') !== false || stripos($platform, 'MariaDB') !== false;
                    if ($isMysql) {
                        $connection->executeStatement(
                            sprintf('ALTER TABLE %s AUTO_INCREMENT = 1', $tableName)
                        );
                        $this->logger->debug('Reset AUTO_INCREMENT for table', [
                            'entity' => $entityName,
                            'table' => $tableName,
                        ]);
                    } else {
                        $this->logger->debug('AUTO_INCREMENT reset skipped (non-MySQL platform)', [
                            'platform' => $platform,
                            'table' => $tableName,
                        ]);
                    }
                } catch (Exception $autoIncrementException) {
                    // Non-critical - log but continue
                    $this->logger->warning('Failed to reset AUTO_INCREMENT', [
                        'entity' => $entityName,
                        'table' => $tableName,
                        'error' => $autoIncrementException->getMessage(),
                    ]);
                }

                $this->logger->info('Cleared entity data', [
                    'entity' => $entityName,
                    'deleted' => $deleted,
                ]);

                $this->statistics[$entityName . '_cleared'] = $deleted;
            } catch (Exception $e) {
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
     * Delete all ManyToMany pivot table rows for the entities that are about to be cleared.
     *
     * Called as first step of clearExistingData() so that the subsequent DQL DELETE FROM Entity
     * does not leave orphan FK entries in pivot tables.
     *
     * Only owning-side associations are processed — each physical pivot table is owned by exactly
     * one side of the relationship, so we emit one DELETE per pivot table.
     *
     * When $tenantScopeIds is non-empty (tenant-scoped clear), we skip full-table DELETEs for pivot
     * tables and instead let the per-entity scoped DQL DELETE cascade naturally via FK — the pivot
     * rows that reference the deleted owner IDs will have already been dealt with by FK_CHECKS=0.
     * A full-table pivot delete would incorrectly remove cross-tenant pivot rows.
     *
     * @param array                         $entityNames    List of entity short names (e.g. "Asset")
     * @param array                         $skipEntities   Entity names to skip
     * @param \Doctrine\DBAL\Connection     $connection     DBAL connection (FK_CHECKS already disabled)
     * @param int[]                         $tenantScopeIds When non-empty, skip global pivot deletes
     */
    private function clearPivotTables(
        array $entityNames,
        array $skipEntities,
        \Doctrine\DBAL\Connection $connection,
        array $tenantScopeIds = []
    ): void {
        // For tenant-scoped clears, we do NOT wipe entire pivot tables (would remove other tenants' rows).
        // The per-entity DQL DELETE already removes owner-side rows; pivot cleanup via FK constraints.
        if ($tenantScopeIds !== []) {
            $this->logger->info('Skipping global pivot-table clear for tenant-scoped restore');
            return;
        }

        $clearedPivots = [];

        foreach ($entityNames as $entityName) {
            if (in_array($entityName, $skipEntities)) {
                continue;
            }

            $entityClass = 'App\\Entity\\' . $entityName;
            if (!class_exists($entityClass)) {
                continue;
            }

            try {
                $classMetadata = $this->entityManager->getClassMetadata($entityClass);
            } catch (Exception) {
                continue;
            }

            foreach ($classMetadata->getAssociationMappings() as $mapping) {
                if ($mapping['type'] !== ClassMetadata::MANY_TO_MANY) {
                    continue;
                }
                if (!$mapping['isOwningSide']) {
                    continue; // inverse side shares the same physical pivot — skip to avoid double-DELETE
                }

                $pivotTable = $mapping['joinTable']['name'] ?? null;
                if ($pivotTable === null || in_array($pivotTable, $clearedPivots)) {
                    continue; // already processed this pivot table
                }

                try {
                    $connection->executeStatement(sprintf('DELETE FROM `%s`', $pivotTable));
                    $clearedPivots[] = $pivotTable;
                    $this->logger->info('Cleared ManyToMany pivot table', ['pivot' => $pivotTable, 'owner' => $entityName]);
                } catch (Exception $e) {
                    $this->warnings[] = sprintf('Failed to clear pivot table %s: %s', $pivotTable, $e->getMessage());
                    $this->logger->warning('Failed to clear pivot table', [
                        'pivot' => $pivotTable,
                        'owner' => $entityName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info('Pivot table cleanup completed', ['cleared_pivot_count' => count($clearedPivots)]);
    }

    /**
     * Restore ManyToMany associations for a batch of entity data via direct DBAL inserts into
     * the pivot tables.
     *
     * This is called in a second pass — AFTER all entity rows have been flushed — so that all
     * referenced entity IDs already exist in the database when we write the pivot rows.
     *
     * Design decisions:
     * - Only owning-side associations are processed (inverse side shares the same physical pivot).
     * - In clear_before_restore mode original IDs are preserved, so backup IDs == DB IDs.
     * - In non-clear mode the entities were inserted with their original IDs as well (UPDATE path),
     *   so backup IDs still resolve correctly.
     * - INSERT IGNORE (ON DUPLICATE KEY) provides idempotency at no cost.
     * - Batch-inserts (VALUES-tuples) reduce round-trips for large collections.
     *
     * @param string $entityClass  Fully qualified entity class name
     * @param string $entityName   Short name (e.g. "Asset")
     * @param array  $entities     Array of serialized entity data from the backup
     */
    private function restoreManyToManyAssociations(string $entityClass, string $entityName, array $entities): void
    {
        try {
            $classMetadata = $this->entityManager->getClassMetadata($entityClass);
        } catch (Exception $e) {
            $this->logger->warning('Could not load metadata for ManyToMany restore', [
                'entity' => $entityName,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $connection = $this->entityManager->getConnection();
        $m2mStats = [];

        foreach ($classMetadata->getAssociationMappings() as $field => $mapping) {
            if ($mapping['type'] !== ClassMetadata::MANY_TO_MANY) {
                continue;
            }
            if (!$mapping['isOwningSide']) {
                continue; // inverse side does not own the pivot — skip
            }

            $pivotTable    = $mapping['joinTable']['name'] ?? null;
            $joinCols      = $mapping['joinTable']['joinColumns'] ?? [];   // owner FK columns
            $invJoinCols   = $mapping['joinTable']['inverseJoinColumns'] ?? []; // target FK columns

            if ($pivotTable === null || $joinCols === [] || $invJoinCols === []) {
                continue;
            }

            $ownerCol  = $joinCols[0]['name'];
            $targetCol = $invJoinCols[0]['name'];
            $idsKey    = $field . '_ids';

            // Collect all VALUE tuples for batch insert
            $tuples = [];

            foreach ($entities as $data) {
                $ownerId = $data['id'] ?? null;
                if ($ownerId === null) {
                    continue;
                }
                if (!isset($data[$idsKey]) || !is_array($data[$idsKey])) {
                    continue;
                }

                foreach ($data[$idsKey] as $targetIdData) {
                    // Backup stores IDs as ['id' => X] arrays
                    $targetId = is_array($targetIdData) ? ($targetIdData['id'] ?? null) : $targetIdData;
                    if ($targetId === null) {
                        continue;
                    }
                    $tuples[] = [$ownerId, $targetId];
                }
            }

            if ($tuples === []) {
                continue;
            }

            // INSERT IGNORE in batches of 500 to avoid oversized SQL statements
            $batchSize   = 500;
            $inserted    = 0;
            $totalTuples = count($tuples);

            for ($offset = 0; $offset < $totalTuples; $offset += $batchSize) {
                $batch      = array_slice($tuples, $offset, $batchSize);
                $valueParts = [];
                $params     = [];

                foreach ($batch as [$ownerId, $targetId]) {
                    $valueParts[] = '(?, ?)';
                    $params[]     = $ownerId;
                    $params[]     = $targetId;
                }

                $sql = sprintf(
                    'INSERT IGNORE INTO `%s` (`%s`, `%s`) VALUES %s',
                    $pivotTable,
                    $ownerCol,
                    $targetCol,
                    implode(', ', $valueParts)
                );

                try {
                    $inserted += $connection->executeStatement($sql, $params);
                } catch (Exception $e) {
                    $this->warnings[] = sprintf(
                        'ManyToMany restore failed for %s.%s (pivot=%s): %s',
                        $entityName,
                        $field,
                        $pivotTable,
                        $e->getMessage()
                    );
                    $this->logger->warning('ManyToMany pivot insert failed', [
                        'entity'   => $entityName,
                        'field'    => $field,
                        'pivot'    => $pivotTable,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $m2mStats[$field] = ['pivot' => $pivotTable, 'tuples' => $totalTuples, 'inserted' => $inserted];

            $this->logger->info('Restored ManyToMany association', [
                'entity'  => $entityName,
                'field'   => $field,
                'pivot'   => $pivotTable,
                'tuples'  => $totalTuples,
                'inserted' => $inserted,
            ]);
        }

        if ($m2mStats !== []) {
            // Merge stats into existing entity statistics
            if (isset($this->statistics[$entityName]) && is_array($this->statistics[$entityName])) {
                $this->statistics[$entityName]['m2m'] = $m2mStats;
            }
        }
    }

    /**
     * Validate entity data structure
     */
    private function validateEntityData(string $entityClass, string $entityName, array $entities): void
    {
        if ($entities === []) {
            return;
        }

        $classMetadata = $this->entityManager->getClassMetadata($entityClass);
        $requiredFields = [];

        // Check for required fields (not nullable)
        foreach ($classMetadata->getFieldNames() as $fieldName) {
            $mapping = $classMetadata->getFieldMapping($fieldName);
            // Doctrine 3.x returns FieldMapping object (ArrayAccess) or plain array
            // depending on version. Check both to stay forward-compatible with ORM 4.0.
            $nullable = is_array($mapping)
                ? ($mapping['nullable'] ?? false)
                : ($mapping->nullable ?? false);
            if (!$nullable && $fieldName !== 'id') {
                $requiredFields[] = $fieldName;
            }
        }

        // Validate first entity as sample
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

    /**
     * Restore a single entity type.
     *
     * When $options['best_effort'] is true, per-row failures are caught, recorded
     * in $this->failures, and the loop continues with the next row.
     * Batch savepoints (per 100 rows) protect already-flushed rows from being
     * rolled back by a single bad row; on error the batch savepoint is rolled
     * back and we switch to per-row savepoints to isolate the culprit.
     */
    private function restoreEntity(string $entityClass, string $entityName, array $entities, array $options): void
    {
        $this->logger->info('Restoring entity', [
            'entity' => $entityName,
            'count' => count($entities),
        ]);

        $bestEffort  = (bool) ($options['best_effort'] ?? false);
        $connection  = $this->entityManager->getConnection();

        $classMetadata = $this->entityManager->getClassMetadata($entityClass);
        $entityRepository = $this->entityManager->getRepository($entityClass);
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        // If clear_before_restore is enabled, temporarily change ID generator to NONE
        // This allows us to preserve original IDs from the backup
        $originalIdGenerator = null;
        $originalGeneratorType = null;
        if ($options['clear_before_restore']) {
            $originalIdGenerator = $classMetadata->idGenerator;
            $originalGeneratorType = $classMetadata->generatorType;
            $classMetadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
            $classMetadata->setIdGenerator(new AssignedGenerator());
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
        $uniqueFields = $this->getUniqueConstraintFields($entityName);

        // Best-effort batch savepoint configuration
        $batchSize     = 100; // savepoint per N rows
        $batchSpName   = null;
        $batchStart    = 0;
        $batchErrored  = false; // true when current batch had an error and we're in per-row mode

        foreach ($entities as $index => $data) {
            // Decrypt SystemSettings value on the fly when best_effort deferred decryption
            if ($bestEffort && $entityName === 'SystemSettings' && isset($data['__needs_decrypt__'])) {
                try {
                    $data = $this->decryptSystemSettingRow($data);
                } catch (\Throwable $decryptError) {
                    $this->logger->warning('Best-effort: decryption failed for SystemSetting row', [
                        'row_id' => $data['id'] ?? null,
                        'error'  => $decryptError->getMessage(),
                    ]);
                    $this->failures[] = [
                        'entity'        => $entityName,
                        'row_index'     => $index,
                        'row_id'        => $data['id'] ?? null,
                        'error_class'   => $decryptError::class,
                        'error_message' => $decryptError->getMessage(),
                        'original_data' => $data,
                    ];
                    $stats['errors']++;
                    continue;
                }
            }

            // --- Batch savepoint management (best_effort only) ---
            if ($bestEffort && !$batchErrored) {
                $posInBatch = $index - $batchStart;

                if ($posInBatch === 0) {
                    // Start a new batch savepoint
                    $batchSpName = 'sp_batch_' . preg_replace('/[^a-zA-Z0-9]/', '_', $entityName) . '_' . $index;
                    try {
                        $this->entityManager->flush(); // flush pending before savepoint
                        $connection->createSavepoint($batchSpName);
                    } catch (\Throwable) {
                        $batchSpName = null; // savepoints not supported
                    }
                }
            }

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
                    $existingEntity = $entityRepository->find($data['id']);
                    $foundById = ($existingEntity !== null);
                }

                // If not found by ID, check by unique constraint fields
                if ($existingEntity === null && $uniqueFields !== []) {
                    $criteria = [];
                    foreach ($uniqueFields as $uniqueField) {
                        if (isset($data[$uniqueField])) {
                            $criteria[$uniqueField] = $data[$uniqueField];
                        }
                    }
                    if ($criteria !== []) {
                        $existingEntity = $entityRepository->findOneBy($criteria);
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
                if ($existingEntity !== null && $foundById && $uniqueFields !== []) {
                    $criteria = [];
                    foreach ($uniqueFields as $uniqueField) {
                        if (isset($data[$uniqueField])) {
                            $criteria[$uniqueField] = $data[$uniqueField];
                        }
                    }
                    if ($criteria !== []) {
                        $potentialConflict = $entityRepository->findOneBy($criteria);
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
                    }
                    if ($options['existing_data_strategy'] === self::EXISTING_UPDATE) {
                        $entity = $existingEntity;
                    }
                    else {
                        // REPLACE: remove old and create new
                        $this->entityManager->remove($existingEntity);
                        $entity = new $entityClass();
                    }
                } else {
                    $entity = new $entityClass();
                }

                // Set field values
                foreach ($classMetadata->getFieldNames() as $fieldName) {
                    if ($fieldName === 'id' && $existingEntity !== null) {
                        continue; // Don't overwrite ID of existing entity
                    }

                    // For new entities with clear_before_restore, preserve the original ID
                    // This is critical for maintaining foreign key references
                    if ($fieldName === 'id' && $existingEntity === null && isset($data['id']) && $options['clear_before_restore']) {
                        // Set the ID using reflection to bypass Doctrine's ID generation
                        try {
                            $reflection = new ReflectionClass($entity);
                            if ($reflection->hasProperty('id')) {
                                $property = $reflection->getProperty('id');
                                $property->setValue($entity, $data['id']);
                                $this->logger->debug('Set entity ID from backup', [
                                    'entity' => $entityName,
                                    'id' => $data['id'],
                                ]);
                            }
                        } catch (Exception $e) {
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
                        if ($classMetadata->hasAssociation($assocName)) {
                            continue; // Will be handled in association restoration below
                        }
                    }

                    if (!array_key_exists($fieldName, $data)) {
                        // Handle missing field
                        if ($options['missing_field_strategy'] === self::STRATEGY_FAIL) {
                            throw new RuntimeException(sprintf('Missing field: %s', $fieldName));
                        }
                        // Handle missing field
                        if ($options['missing_field_strategy'] === self::STRATEGY_SKIP_FIELD) {
                            continue;
                        }
                        // USE_DEFAULT: continue with null or existing value
                    }

                    $value = $data[$fieldName] ?? null;

                    // Apply default values for missing fields in Risk entity (backward compatibility)
                    if ($entityName === 'Risk' && $value === null) {
                        switch ($fieldName) {
                            case 'category':
                                $value = 'operational'; // Default risk category
                                $this->logger->debug('Using default value for Risk.category', [
                                    'entity_index' => $index,
                                    'default' => $value,
                                ]);
                                break;
                            case 'involvesPersonalData':
                            case 'involvesSpecialCategoryData':
                            case 'requiresDPIA':
                                $value = false; // Default: no personal data involved
                                $this->logger->debug("Using default value for Risk.{$fieldName}", [
                                    'entity_index' => $index,
                                    'default' => $value,
                                ]);
                                break;
                        }
                    }

                    // Null-Schutz für nicht-nullable JSON-/Array-Felder (z.B.
                    // User::$completedTours, Entity-Settings-JSONs). Ohne diesen
                    // Check crasht property-access mit
                    // "Cannot assign null to property ... of type array".
                    $type = $classMetadata->getTypeOfField($fieldName);
                    if ($value === null && in_array($type, ['json', 'simple_array', 'array'], true)) {
                        try {
                            $mapping = $classMetadata->getFieldMapping($fieldName);
                            $isNullable = is_array($mapping)
                                ? ($mapping['nullable'] ?? false)
                                : ($mapping->nullable ?? false);
                            if (!$isNullable) {
                                $value = [];
                            }
                        } catch (Exception) {
                            // Fallback: bei unbekannter Metadata default auf [] setzen.
                            $value = [];
                        }
                    }

                    // Convert ISO 8601 strings back to DateTime/DateTimeImmutable
                    if (in_array($type, ['datetime', 'datetime_immutable', 'date', 'date_immutable', 'time', 'time_immutable'])) {
                        try {
                            // Check if type expects immutable or mutable
                            $expectsImmutable = str_contains((string) $type, 'immutable');

                            if (is_string($value)) {
                                // Convert string to appropriate DateTime type
                                $value = $expectsImmutable ? new DateTimeImmutable($value) : new DateTime($value);
                            } elseif ($value instanceof DateTimeImmutable && !$expectsImmutable) {
                                // Convert DateTimeImmutable to DateTime for mutable types
                                $value = DateTime::createFromImmutable($value);
                            } elseif ($value instanceof DateTime && $expectsImmutable) {
                                // Convert DateTime to DateTimeImmutable for immutable types
                                $value = DateTimeImmutable::createFromMutable($value);
                            }
                            // If value is already the correct type, leave it as is
                        } catch (Exception $dateException) {
                            $this->logger->warning('Failed to parse date/time value', [
                                'entity' => $entityName,
                                'field' => $fieldName,
                                'value' => is_object($value) ? $value::class : $value,
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
                            $reflection = new ReflectionClass($entity);
                            if ($reflection->hasProperty($fieldName)) {
                                $property = $reflection->getProperty($fieldName);
                                $property->setValue($entity, $value);
                            }
                        }
                    } catch (Exception $e) {
                        // Log but don't fail - try to continue with other fields
                        $this->logger->warning('Failed to set property', [
                            'entity' => $entityName,
                            'field' => $fieldName,
                            'value_type' => get_debug_type($value),
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

                // Special handling for User entities: Set password if admin_password is provided
                if ($entityName === 'User' && isset($options['admin_password']) && $options['admin_password'] !== '') {
                    $adminPassword = $options['admin_password'];

                    $this->logger->info('Processing User entity password', [
                        'user_email' => $data['email'] ?? 'unknown',
                        'user_id' => $data['id'] ?? 'unknown',
                        'admin_password_length' => strlen($adminPassword),
                        'entity_class' => $entity::class,
                    ]);

                    // Hash the password using Symfony's UserPasswordHasher
                    try {
                        $hashedPassword = $this->userPasswordHasher->hashPassword($entity, $adminPassword);

                        $this->logger->debug('Password hashed successfully', [
                            'user_email' => $data['email'] ?? 'unknown',
                            'hashed_length' => strlen($hashedPassword),
                        ]);

                        // Set the hashed password
                        $reflection = new ReflectionClass($entity);
                        if ($reflection->hasProperty('password')) {
                            $property = $reflection->getProperty('password');
                            $property->setValue($entity, $hashedPassword);

                            $this->logger->info('Password set for restored user', [
                                'user_email' => $data['email'] ?? 'unknown',
                                'user_id' => $data['id'] ?? 'unknown',
                            ]);

                            // Add warning to notify admin
                            $this->warnings[] = sprintf(
                                'User "%s" restored with setup password. User should change password after first login.',
                                $data['email'] ?? 'ID: ' . ($data['id'] ?? 'unknown')
                            );
                        } else {
                            $this->logger->error('User entity has no password property', [
                                'user_email' => $data['email'] ?? 'unknown',
                            ]);
                        }
                    } catch (Exception $e) {
                        $this->logger->error('Failed to hash/set password for restored user', [
                            'user_email' => $data['email'] ?? 'unknown',
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        $this->warnings[] = sprintf(
                            'WARNING: Could not set password for user "%s": %s',
                            $data['email'] ?? 'ID: ' . ($data['id'] ?? 'unknown'),
                            $e->getMessage()
                        );
                    }
                }

                // Restore associations (ManyToOne, OneToOne)
                foreach ($classMetadata->getAssociationNames() as $assocName) {
                    if ($classMetadata->isSingleValuedAssociation($assocName)) {
                        $assocIdKey = $assocName . '_id';
                        if (isset($data[$assocIdKey])) {
                            $targetClass = $classMetadata->getAssociationTargetClass($assocName);
                            $assocId = $data[$assocIdKey];

                            // Handle array format from backup (e.g., ['id' => 5])
                            if (is_array($assocId) && isset($assocId['id'])) {
                                $assocId = $assocId['id'];
                            }

                            if ($assocId !== null) {
                                try {
                                    $relatedEntity = $this->entityManager->getReference($targetClass, $assocId);

                                    // Try PropertyAccessor first (works for most entities)
                                    if ($propertyAccessor->isWritable($entity, $assocName)) {
                                        $propertyAccessor->setValue($entity, $assocName, $relatedEntity);
                                    } else {
                                        // Fallback: Try to find the setter method by convention
                                        // Some entities use shortened setter names (e.g., setFramework instead of setComplianceFramework)
                                        $reflection = new ReflectionClass($entity);

                                        // Build possible setter names
                                        $setterCandidates = [
                                            'set' . ucfirst($assocName), // Standard: setComplianceFramework
                                            'set' . ucfirst(preg_replace('/^.*([A-Z][a-z]+)$/', '$1', $assocName)), // Shortened: setFramework
                                        ];

                                        $setterFound = false;
                                        foreach ($setterCandidates as $setterName) {
                                            if ($reflection->hasMethod($setterName)) {
                                                $method = $reflection->getMethod($setterName);
                                                $method->invoke($entity, $relatedEntity);
                                                $setterFound = true;
                                                break;
                                            }
                                        }

                                        if (!$setterFound) {
                                            throw new RuntimeException(sprintf(
                                                'No setter found for association "%s". Tried: %s',
                                                $assocName,
                                                implode(', ', $setterCandidates)
                                            ));
                                        }
                                    }
                                } catch (Exception $e) {
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
                    // Collections (ManyToMany) are handled in the second pass via restoreManyToManyAssociations().
                    // OneToMany is always managed by the owning Many side (ManyToOne), so no action needed here.
                }

                // Persist entity
                $this->entityManager->persist($entity);

                if ($existingEntity !== null) {
                    $stats['updated']++;
                } else {
                    $stats['created']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;

                if ($bestEffort) {
                    // Record failure and continue with the next row
                    $this->failures[] = [
                        'entity'        => $entityName,
                        'row_index'     => $index,
                        'row_id'        => $data['id'] ?? null,
                        'error_class'   => $e::class,
                        'error_message' => $e->getMessage(),
                        'original_data' => $data,
                    ];
                    $this->logger->warning('Best-effort: row restore failed, skipping', [
                        'entity'    => $entityName,
                        'index'     => $index,
                        'row_id'    => $data['id'] ?? null,
                        'error'     => $e->getMessage(),
                    ]);

                    // On batch error: rollback the batch savepoint, then switch to per-row mode
                    if ($batchSpName !== null && !$batchErrored) {
                        try {
                            $this->entityManager->clear(); // detach pending entities
                            $connection->rollbackSavepoint($batchSpName);
                            $this->logger->info('Best-effort: rolled back batch savepoint', [
                                'savepoint' => $batchSpName,
                                'entity'    => $entityName,
                            ]);
                        } catch (\Throwable) {
                            // Ignore savepoint rollback errors
                        }
                        $batchSpName  = null;
                        $batchErrored = true; // switch to per-row mode for the rest of this entity-type
                    }

                    // If EntityManager was closed, try to reopen via registry
                    if (!$this->entityManager->isOpen()) {
                        if ($this->registry !== null) {
                            try {
                                $this->registry->resetManager();
                                $this->logger->info('Best-effort: EntityManager reset after row failure', [
                                    'entity' => $entityName,
                                    'index'  => $index,
                                ]);
                            } catch (\Throwable $resetError) {
                                $this->logger->warning('Best-effort: could not reset EntityManager, aborting entity-type', [
                                    'entity' => $entityName,
                                    'error'  => $resetError->getMessage(),
                                ]);
                                break;
                            }
                        } else {
                            $this->warnings[] = 'EntityManager closed due to database error. Restore aborted.';
                            break;
                        }
                    }

                    continue; // next row
                }

                // Strict mode: record warning and abort entity-type on closed EM
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

            // --- Release batch savepoint after every $batchSize rows (best_effort) ---
            if ($bestEffort && !$batchErrored && $batchSpName !== null) {
                $posInBatch = ($index - $batchStart) + 1;
                if ($posInBatch >= $batchSize) {
                    // Flush and release this batch's savepoint
                    try {
                        $this->entityManager->flush();
                        $connection->releaseSavepoint($batchSpName);
                    } catch (\Throwable) {
                        // Non-critical — savepoints may not be supported
                    }
                    $batchSpName  = null;
                    $batchStart   = $index + 1;
                    $batchErrored = false;
                }
            }
        }

        // Flush all entities of this type at once
        if ($this->entityManager->isOpen()) {
            try {
                $this->logger->debug('Flushing entities to database', [
                    'entity' => $entityName,
                    'count' => $stats['created'] + $stats['updated'],
                ]);
                $this->entityManager->flush();

                // Release any open batch savepoint after the final flush
                if ($bestEffort && $batchSpName !== null) {
                    try {
                        $connection->releaseSavepoint($batchSpName);
                    } catch (\Throwable) {
                        // Non-critical
                    }
                }

                $this->logger->info('Successfully flushed entities', [
                    'entity' => $entityName,
                    'created' => $stats['created'],
                    'updated' => $stats['updated'],
                ]);
            } catch (\Throwable $e) {
                $stats['errors']++;

                if ($bestEffort) {
                    // Record the flush failure but do not throw
                    $this->failures[] = [
                        'entity'        => $entityName,
                        'row_index'     => null,
                        'row_id'        => null,
                        'error_class'   => $e::class,
                        'error_message' => 'Flush error: ' . $e->getMessage(),
                        'original_data' => [],
                    ];
                    $this->logger->warning('Best-effort: flush error for entity-type', [
                        'entity' => $entityName,
                        'error'  => $e->getMessage(),
                    ]);

                    // Rollback open batch savepoint on flush error
                    if ($batchSpName !== null) {
                        try {
                            $connection->rollbackSavepoint($batchSpName);
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }

                    if (!$this->entityManager->isOpen() && $this->registry !== null) {
                        try {
                            $this->registry->resetManager();
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }
                } else {
                    $this->warnings[] = sprintf(
                        'Error flushing %s entities (database constraint error): %s',
                        $entityName,
                        $e->getMessage()
                    );
                    $this->logger->error('Error flushing entities', [
                        'entity' => $entityName,
                        'error' => $e->getMessage(),
                        'error_class' => $e::class,
                        'trace' => $e->getTraceAsString(),
                        'created_count' => $stats['created'],
                        'updated_count' => $stats['updated'],
                    ]);

                    // Check if EntityManager is closed
                    if (!$this->entityManager->isOpen()) {
                        $this->warnings[] = sprintf(
                            'EntityManager closed after %s flush error. Remaining entities will be skipped.',
                            $entityName
                        );
                        $this->logger->critical('EntityManager closed after flush error', [
                            'entity' => $entityName,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } else {
            $this->logger->warning('EntityManager already closed, cannot flush', [
                'entity' => $entityName,
            ]);
        }

        // Restore original ID generator if it was changed
        if ($originalIdGenerator instanceof AbstractIdGenerator && $originalGeneratorType !== null) {
            $classMetadata->setIdGeneratorType($originalGeneratorType);
            $classMetadata->setIdGenerator($originalIdGenerator);
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
     */
    private function getUniqueConstraintFields(string $entityName): array
    {
        // Map of entity names to their unique constraint fields
        $uniqueFieldsMap = [
            'Role' => ['name'],
            'User' => ['email'],
            'Tenant' => ['code'],  // Tenant uses 'code' (UniqueEntity), NOT 'slug'
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
     */
    private function orderEntitiesByDependency(array $entityNames): array
    {
        // Priority order: lower number = restored first
        // Entities without foreign keys first, then entities that depend on them
        // IMPORTANT: If entity A has ManyToOne to entity B, then B must have lower priority than A
        $priorityOrder = [
            // Base entities (no dependencies except tenant)
            'Tenant' => 1,
            'Role' => 2,
            'Permission' => 3,
            'User' => 4,
            'Person' => 5,
            'Location' => 6,
            'Supplier' => 7,
            'SystemSettings' => 8,

            // Configuration entities (Phase 8 / QW-5) — FK: Tenant, User
            'RiskApprovalConfig' => 9,
            'IncidentSlaConfig' => 9,
            'SupplierCriticalityLevel' => 9,
            'KpiThresholdConfig' => 9,
            'Tag' => 9,             // FK: Tenant only
            'EntityTag' => 9,       // FK: Tag, User — must come after Tag

            // Framework/Control entities (depend on base)
            'ComplianceFramework' => 10,
            'Control' => 11,

            // Independent entities (depend only on Tenant)
            'Asset' => 15,
            'InterestedParty' => 16,

            // Entities that depend on frameworks/controls
            'ComplianceRequirement' => 20,
            'ComplianceRequirementFulfillment' => 21,

            // Risk depends on Asset, so must come after Asset
            'Risk' => 25,
            'RiskAppetite' => 26,
            'RiskTreatmentPlan' => 27,
            'Incident' => 28,
            'Vulnerability' => 29,
            'Patch' => 30,
            'ThreatIntelligence' => 31,

            // BCM entities
            'BusinessProcess' => 35,
            'BusinessContinuityPlan' => 36,
            'BCExercise' => 37,
            'CrisisTeam' => 38,

            // GDPR/Privacy entities (CRITICAL - were missing!)
            'ProcessingActivity' => 40,
            'DataProtectionImpactAssessment' => 41,
            'DataBreach' => 42,
            'Consent' => 43,
            'DataSubjectRequest' => 44,  // FK: ProcessingActivity, Tenant, User

            // Documents & Training
            'Document' => 45,
            'Training' => 46,

            // Audit & Reviews — AuditFinding depends on InternalAudit
            'InternalAudit' => 50,
            'AuditChecklist' => 51,
            'AuditFinding' => 52,        // FK: Tenant, InternalAudit, Control, User
            'CorrectiveAction' => 53,    // FK: Tenant, AuditFinding, User
            'AuditFreeze' => 54,         // FK: Tenant, User (tamper-evident)
            'ManagementReview' => 55,

            // DORA — ManyToMany on AuditFinding (already restored at 52)
            'ThreatLedPenetrationTest' => 56,  // FK: Tenant; M2M: AuditFinding

            // Context & Objectives
            'ISMSContext' => 58,
            'ISMSObjective' => 59,
            'CorporateGovernance' => 60,

            // Operations
            'ChangeRequest' => 62,
            'CryptographicOperation' => 63,
            'PhysicalAccessLog' => 64,
            'FourEyesApprovalRequest' => 65,  // FK: Tenant, User (requester/approver/reviewer)

            // Mapping entities (depend on requirements and controls)
            'ComplianceMapping' => 67,
            'MappingGapItem' => 68,

            // Workflows
            'Workflow' => 70,
            'WorkflowStep' => 71,
            'WorkflowInstance' => 72,

            // Reports & Snapshots
            'ScheduledReport' => 75,
            'CustomReport' => 76,
            'AppliedBaseline' => 77,    // FK: Tenant, User
            'KpiSnapshot' => 78,        // FK: Tenant

            // User Preferences
            'DashboardLayout' => 80,
            'MfaToken' => 81,
            'ScheduledTask' => 82,

            // Logs and audit trails (last)
            'AuditLog' => 90,
            'UserSession' => 91,
        ];

        usort($entityNames, function ($a, $b) use ($priorityOrder): int {
            $priorityA = $priorityOrder[$a] ?? 50; // Default priority for unknown entities
            $priorityB = $priorityOrder[$b] ?? 50;

            return $priorityA <=> $priorityB;
        });

        return $entityNames;
    }

    // ------------------------------------------------------------------
    // Tenant-scope helpers (C2)
    // ------------------------------------------------------------------

    /**
     * Resolve the list of tenant IDs that belong to the given scope.
     * Returns an empty array when $tenantScope is null (= global restore).
     *
     * @return int[]
     */
    private function resolveTenantScopeIds(?Tenant $tenantScope): array
    {
        if ($tenantScope === null) {
            return [];
        }

        $ids = [$tenantScope->getId()];
        foreach ($tenantScope->getAllSubsidiaries() as $subsidiary) {
            $ids[] = $subsidiary->getId();
        }

        return array_values(array_filter($ids, fn($id): bool => $id !== null));
    }

    /**
     * Filter an array of serialized entity data to only include entries
     * whose tenant_id (stored as tenant_id key or inside tenant_id array) is
     * present in $scopeIds.
     *
     * When $scopeIds is empty (global restore) the original array is returned unchanged.
     *
     * The backup format stores associations as `<assocName>_id` keys.
     * For the `tenant` association that becomes `tenant_id`.
     *
     * @param array $entities  Array of serialized entity arrays from the backup
     * @param int[] $scopeIds  Tenant IDs that are allowed; empty = all
     * @return array Filtered entities
     */
    private function filterEntitiesByScope(array $entities, array $scopeIds): array
    {
        if ($scopeIds === []) {
            return $entities;
        }

        return array_values(array_filter($entities, function (array $data) use ($scopeIds): bool {
            $tenantIdData = $data['tenant_id'] ?? null;

            if ($tenantIdData === null) {
                // Entity has no tenant field in the backup — include it (global entity like Role)
                return true;
            }

            // tenant_id is stored as ['id' => X] (association format from serializeEntities)
            $tenantId = is_array($tenantIdData) ? ($tenantIdData['id'] ?? null) : (int) $tenantIdData;

            return $tenantId !== null && in_array($tenantId, $scopeIds, true);
        }));
    }

    /**
     * Check whether a Doctrine entity class has a single-valued `tenant` association.
     * Used by clearExistingData() to decide between scoped and global DELETE.
     */
    private function entityHasTenantAssociation(string $entityClass): bool
    {
        try {
            $metadata = $this->entityManager->getClassMetadata($entityClass);
            foreach ($metadata->getAssociationNames() as $name) {
                if ($name === 'tenant' && $metadata->isSingleValuedAssociation($name)) {
                    return true;
                }
            }
        } catch (Exception) {
            // Entity not registered with Doctrine — treat as no tenant field
        }

        return false;
    }

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Set password for the first admin user after restore
     *
     * @param string $password Plain text password
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
                $hashedPassword = $this->userPasswordHasher->hashPassword($adminUser, $password);
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
        } catch (Exception $e) {
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
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    // ------------------------------------------------------------------
    // Integrity & encryption helpers (Feature 1 + 2)
    // ------------------------------------------------------------------

    /**
     * Verify the SHA256 integrity seal of the backup data section.
     *
     * - If `metadata.sha256` is present: recompute hash over `data` and throw on mismatch.
     * - If `metadata.sha256` is absent (legacy backup): log a warning and return the warning
     *   string so the caller can re-apply it after the next `validateBackup()` reset.
     *
     * @return string|null Warning message for legacy backups without a hash, null otherwise.
     * @throws RuntimeException When the hash does not match.
     */
    private function verifyIntegrity(array $backup): ?string
    {
        $storedHash = $backup['metadata']['sha256'] ?? null;

        if ($storedHash === null) {
            $warning = 'Legacy backup restored without SHA256 integrity verification — hash absent in metadata.';
            $this->logger->warning($warning);
            return $warning;
        }

        $actualHash = hash('sha256', (string) json_encode($backup['data'] ?? []));

        if (!hash_equals($storedHash, $actualHash)) {
            throw new RuntimeException(sprintf(
                'Backup integrity check failed: sha256 mismatch (expected %s, got %s)',
                $storedHash,
                $actualHash
            ));
        }

        $this->logger->info('Backup integrity check passed', ['sha256' => $storedHash]);
        return null;
    }

    /**
     * Get the accumulated row-level failures from the last best-effort restore.
     *
     * @return array<int, array{entity: string, row_index: int|null, row_id: mixed, error_class: string, error_message: string, original_data: array}>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * Mark SystemSettings rows for deferred per-row decryption in best_effort mode.
     * Rows that need decryption get a special '__needs_decrypt__' flag.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function markSystemSettingsForDecryption(array $rows): array
    {
        if ($this->backupEncryption === null) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $value = $row['value'] ?? null;
            if ($this->backupEncryption->isEncrypted($value)) {
                $row['__needs_decrypt__'] = true;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Decrypt a single SystemSettings row in best_effort deferred mode.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @throws RuntimeException When decryption fails (e.g. wrong APP_SECRET).
     */
    private function decryptSystemSettingRow(array $row): array
    {
        if ($this->backupEncryption === null) {
            unset($row['__needs_decrypt__']);
            return $row;
        }

        $value = $row['value'] ?? null;
        if ($this->backupEncryption->isEncrypted($value)) {
            $row['value'] = $this->backupEncryption->decryptValue($value);
        }
        unset($row['__needs_decrypt__']);

        return $row;
    }

    /**
     * Decrypt sensitive SystemSettings values that were encrypted during backup.
     *
     * Rows with non-encrypted `value` fields are returned unchanged.
     *
     * @param array<int, array<string, mixed>> $rows Serialised SystemSettings rows from backup.
     * @return array<int, array<string, mixed>> Rows with decrypted values.
     * @throws RuntimeException When decryption fails (e.g. wrong APP_SECRET).
     */
    private function decryptSystemSettingsValues(array $rows): array
    {
        if ($this->backupEncryption === null) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $value = $row['value'] ?? null;
            if (!$this->backupEncryption->isEncrypted($value)) {
                continue;
            }

            // decryptValue() throws RuntimeException with a clear message on failure.
            $row['value'] = $this->backupEncryption->decryptValue($value);
        }
        unset($row);

        return $rows;
    }
}

