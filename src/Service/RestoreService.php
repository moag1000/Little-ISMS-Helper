<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\BackupEncryptionService;
use App\Service\Restore\RestoreDataPurger;
use App\Service\Restore\RestoreEntityWriter;
use App\Service\Restore\RestoreOptions;
use App\Service\Restore\RestoreSecretsHandler;
use App\Service\Restore\RestoreValidator;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Doctrine\ORM\Events;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Facade for backup restore operations.
 *
 * Delegates all heavy lifting to four collaborators:
 *  - RestoreValidator      — backup validation + integrity check
 *  - RestoreDataPurger     — clearing existing data + tenant-scope filtering
 *  - RestoreEntityWriter   — entity row hydration + ManyToMany second-pass
 *  - RestoreSecretsHandler — decryption + password hashing
 *
 * The public API is identical to the original monolithic class so that
 * zero call-sites require changes.
 */
class RestoreService
{
    // Strategy constants are sourced from RestoreOptions so collaborators
    // can depend on them without an upward dependency on the facade.
    public const string STRATEGY_SKIP_FIELD = RestoreOptions::STRATEGY_SKIP_FIELD;
    public const string STRATEGY_USE_DEFAULT = RestoreOptions::STRATEGY_USE_DEFAULT;
    public const string STRATEGY_FAIL = RestoreOptions::STRATEGY_FAIL;

    public const string EXISTING_SKIP = RestoreOptions::EXISTING_SKIP;
    public const string EXISTING_UPDATE = RestoreOptions::EXISTING_UPDATE;
    public const string EXISTING_REPLACE = RestoreOptions::EXISTING_REPLACE;

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
        private readonly ?RestoreValidator $validator = null,
        private readonly ?RestoreDataPurger $purger = null,
        private readonly ?RestoreEntityWriter $entityWriter = null,
        private readonly ?RestoreSecretsHandler $secretsHandler = null,
    ) {
    }

    // ------------------------------------------------------------------
    // Lazy collaborator accessors
    // (null-safe fallback keeps legacy test setUp() without DI working)
    // ------------------------------------------------------------------

    private function getValidator(): RestoreValidator
    {
        return $this->validator ?? new RestoreValidator($this->entityManager, $this->logger);
    }

    private function getPurger(): RestoreDataPurger
    {
        return $this->purger ?? new RestoreDataPurger($this->entityManager, $this->logger);
    }

    private function getEntityWriter(): RestoreEntityWriter
    {
        return $this->entityWriter ?? new RestoreEntityWriter($this->entityManager, $this->logger);
    }

    private function getSecretsHandler(): RestoreSecretsHandler
    {
        return $this->secretsHandler ?? new RestoreSecretsHandler(
            $this->entityManager,
            $this->logger,
            $this->userPasswordHasher,
            $this->backupEncryption,
        );
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Validate backup data.
     *
     * Checks:
     *  - metadata section present
     *  - format version supported (major-version check)
     *  - schema_version warning if present and different from current
     *  - data section present and valid per entity
     *  - (when $callerScope is given) backup's tenant_scope intersects with caller-scope tree
     *
     * Legacy backups (version missing) are assumed to be format 1.0 and accepted
     * with a warning so old JSON-only backups remain restoreable.
     *
     * @param array       $backup      Backup data to validate
     * @param Tenant|null $callerScope When set, rejects cross-tenant restore attempts
     *                                 (ROLE_ADMIN restoring a backup of a foreign tenant).
     *                                 SUPER_ADMIN passes null and bypasses the check.
     * @return array Validation result with 'valid' boolean and 'errors' array
     *
     * @throws AccessDeniedException When $callerScope is set and the backup's
     *                               recorded tenant_scope has no overlap with
     *                               the caller's accessible tree.
     */
    public function validateBackup(array $backup, ?Tenant $callerScope = null): array
    {
        $validator = $this->getValidator();
        $purger    = $this->getPurger();

        $result = $validator->validateBackup(
            $backup,
            $callerScope,
            fn(?Tenant $t): array => $purger->resolveTenantScopeIds($t),
            function (string $class, string $name, array $rows) use ($validator): void {
                $validator->validateEntityData($class, $name, $rows);
            },
        );

        $this->validationErrors = $validator->getValidationErrors();
        $this->warnings         = $validator->getWarnings();

        return $result;
    }

    /**
     * Get preview of restore operation.
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
                'sample' => array_slice($entities, 0, 3),
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

        $purger         = $this->getPurger();
        $entityWriter   = $this->getEntityWriter();
        $secretsHandler = $this->getSecretsHandler();

        // --- SHA256 integrity check ---
        // verifyIntegrity() may return a legacy-backup warning. We collect it here so it
        // survives the validateBackup() call that follows (which resets $this->warnings).
        $bestEffort = (bool) ($options['best_effort'] ?? false);
        try {
            $integrityWarning = $this->getValidator()->verifyIntegrity($backup);
        } catch (\App\Exception\Io\IoException $integrityException) {
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

        $targetScopeIds = $purger->resolveTenantScopeIds($targetTenantScope);

        // Collect warnings that must survive the validateBackup() reset
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

        $options = array_merge([
            'missing_field_strategy' => self::STRATEGY_USE_DEFAULT,
            'existing_data_strategy' => self::EXISTING_UPDATE,
            'skip_entities'          => [],
            'dry_run'                => false,
            'clear_before_restore'   => false,
            'best_effort'            => false,
        ], $options);

        // Validate first (resets $this->warnings internally).
        // We do NOT pass $targetTenantScope — strict cross-tenant rejection is for
        // AdminBackupController. restoreFromBackup() uses the legacy soft-warn behaviour.
        $validation = $this->validateBackup($backup);
        if (!$validation['valid']) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException(
                'Invalid backup: ' . implode(', ', $validation['errors']),
                'backup'
            );
        }

        foreach ($prependWarnings as $warning) {
            $this->warnings[] = $warning;
        }

        $this->logger->info('Starting restore', [
            'version' => $backup['metadata']['version'],
            'options' => $options,
        ]);

        try {
            $connection      = $this->entityManager->getConnection();
            $disableFKChecks = false;

            try {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
                $disableFKChecks = true;
                $this->logger->info('Disabled foreign key checks for restore');
            } catch (Exception $e) {
                $this->logger->warning('Could not disable foreign key checks', ['error' => $e->getMessage()]);
            }

            $eventManager      = $this->entityManager->getEventManager();
            $originalListeners = [];
            $eventsToDisable   = [Events::prePersist, Events::preUpdate, Events::postPersist, Events::postUpdate];

            foreach ($eventsToDisable as $eventName) {
                try {
                    $listeners = $eventManager->getListeners($eventName);
                    $originalListeners[$eventName] = $listeners;
                    foreach ($listeners as $listener) {
                        $eventManager->removeEventListener($eventName, $listener);
                    }
                } catch (Exception) {
                    $originalListeners[$eventName] = [];
                    $this->logger->debug('No listeners for event', ['event' => $eventName]);
                }
            }
            $this->logger->info('Disabled Doctrine lifecycle event listeners for restore');

            // Decrypt sensitive SystemSettings values before restore.
            if ($this->backupEncryption !== null && isset($backup['data']['SystemSettings'])) {
                if ($options['best_effort']) {
                    $backup['data']['SystemSettings'] = $secretsHandler->markSystemSettingsForDecryption(
                        $backup['data']['SystemSettings']
                    );
                } else {
                    $backup['data']['SystemSettings'] = $secretsHandler->decryptSystemSettingsValues(
                        $backup['data']['SystemSettings']
                    );
                }
            }

            $orderedEntities = $entityWriter->orderEntitiesByDependency(array_keys($backup['data']));

            // If clear_before_restore is set, delete all existing data first (in reverse order).
            // IMPORTANT: Do NOT start transaction before clearExistingData — ALTER TABLE causes
            // implicit COMMIT in MySQL. Start transaction AFTER clearExistingData.
            if ($options['clear_before_restore']) {
                $purger->clearExistingData(
                    array_reverse($orderedEntities),
                    $options['skip_entities'],
                    $targetScopeIds,
                    $this->statistics,
                    $this->warnings,
                );
                $this->entityManager->clear();

                if ($options['dry_run']) {
                    $this->warnings[] = 'Dry-run: Bestehende Daten wurden gelöscht (wird am Ende zurückgesetzt)';
                }
            }

            $this->entityManager->beginTransaction();
            $this->logger->info('Started transaction for entity restoration');

            if ($options['best_effort']) {
                try {
                    $connection->setNestTransactionsWithSavepoints(true);
                } catch (\Throwable) {
                    $this->logger->warning('Best-effort: driver does not support nested savepoints — row isolation disabled');
                }
            }

            // First pass: restore all scalar fields and single-valued associations
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

                $entities = $purger->filterEntitiesByScope($allEntities, $targetScopeIds);

                if ($targetScopeIds !== [] && count($entities) < count($allEntities)) {
                    $this->logger->info('Tenant-scoped restore: filtered entities', [
                        'entity'   => $orderedEntity,
                        'total'    => count($allEntities),
                        'in_scope' => count($entities),
                        'skipped'  => count($allEntities) - count($entities),
                    ]);
                }

                if ($options['best_effort']) {
                    $spName           = 'sp_' . preg_replace('/[^a-zA-Z0-9]/', '_', $orderedEntity);
                    $savepointCreated = false;
                    try {
                        $connection->createSavepoint($spName);
                        $savepointCreated = true;
                    } catch (\Throwable) {
                    }

                    try {
                        $entityWriter->restoreEntity(
                            $entityClass, $orderedEntity, $entities, $options,
                            $this->failures, $this->statistics, $this->warnings,
                            $this->registry, $secretsHandler,
                        );
                        if ($savepointCreated) {
                            try {
                                $connection->releaseSavepoint($spName);
                            } catch (\Throwable) {
                            }
                        }
                    } catch (\Throwable $entityTypeError) {
                        $this->logger->warning('Best-effort: entity-type restore failed, rolling back savepoint', [
                            'entity' => $orderedEntity, 'error' => $entityTypeError->getMessage(),
                        ]);

                        if ($savepointCreated) {
                            try {
                                $connection->rollbackSavepoint($spName);
                            } catch (\Throwable) {
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

                        if (!$this->entityManager->isOpen()) {
                            // Delegate to the shared recovery helper on RestoreEntityWriter.
                            // We ignore the return value here — entity-type loop continues
                            // to the next entity regardless (failures already recorded above).
                            $entityWriter->safeResetManager($this->registry, $orderedEntity, null, $this->warnings);
                        }
                    }
                } else {
                    $entityWriter->restoreEntity(
                        $entityClass, $orderedEntity, $entities, $options,
                        $this->failures, $this->statistics, $this->warnings,
                        $this->registry, $secretsHandler,
                    );
                }
            }

            // Second pass: restore ManyToMany associations via direct DBAL pivot-table inserts.
            // Must run AFTER all entity rows are flushed so all referenced IDs exist in DB.
            if (!$options['dry_run'] && $this->entityManager->isOpen()) {
                $this->logger->info('Starting ManyToMany second-pass restore');
                foreach ($orderedEntities as $orderedEntity) {
                    if (in_array($orderedEntity, $options['skip_entities'])) {
                        continue;
                    }
                    $allEntities = $backup['data'][$orderedEntity] ?? [];
                    $entityClass = 'App\\Entity\\' . $orderedEntity;
                    if (!class_exists($entityClass) || $allEntities === []) {
                        continue;
                    }
                    $entities = $purger->filterEntitiesByScope($allEntities, $targetScopeIds);
                    if ($entities === []) {
                        continue;
                    }
                    $entityWriter->restoreManyToManyAssociations(
                        $entityClass, $orderedEntity, $entities,
                        $this->statistics, $this->warnings,
                    );
                }
                $this->logger->info('ManyToMany second-pass restore completed');
            }

            if ($options['dry_run']) {
                $this->entityManager->rollback();
                $this->logger->info('Dry run completed, rolling back');

                if ($disableFKChecks) {
                    try {
                        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                        $this->logger->info('Re-enabled foreign key checks after dry-run');
                    } catch (Exception $fkException) {
                        $this->logger->error('Failed to re-enable foreign key checks', ['error' => $fkException->getMessage()]);
                    }
                }

                foreach ($originalListeners as $eventName => $listeners) {
                    foreach ($listeners as $listener) {
                        $eventManager->addEventListener($eventName, $listener);
                    }
                }
                $this->logger->info('Re-enabled Doctrine lifecycle event listeners after dry-run');
            } else {
                if (!$this->entityManager->isOpen()) {
                    $this->warnings[] = 'EntityManager was closed during restore. Some changes may not have been persisted.';
                    $this->logger->warning('EntityManager closed before final commit');

                    if ($disableFKChecks) {
                        try {
                            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                        } catch (Exception $fkException) {
                            $this->logger->error('Failed to re-enable foreign key checks', ['error' => $fkException->getMessage()]);
                        }
                    }

                    foreach ($originalListeners as $eventName => $listeners) {
                        foreach ($listeners as $listener) {
                            $eventManager->addEventListener($eventName, $listener);
                        }
                    }

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

                if ($disableFKChecks) {
                    try {
                        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                        $this->logger->info('Re-enabled foreign key checks');
                    } catch (Exception $fkException) {
                        $this->logger->error('Failed to re-enable foreign key checks', ['error' => $fkException->getMessage()]);
                    }
                }

                foreach ($originalListeners as $eventName => $listeners) {
                    foreach ($listeners as $listener) {
                        $eventManager->addEventListener($eventName, $listener);
                    }
                }
                $this->logger->info('Re-enabled Doctrine lifecycle event listeners');

                $totalRestored = 0;
                foreach ($this->statistics as $statistic) {
                    if (is_array($statistic) && isset($statistic['created'])) {
                        $totalRestored += $statistic['created'] + ($statistic['updated'] ?? 0);
                    }
                }
                $this->auditLogger->logImport(
                    'Backup',
                    $totalRestored,
                    sprintf(
                        'Restored backup from %s. Statistics: %s',
                        $backup['metadata']['created_at'] ?? 'unknown',
                        json_encode($this->statistics)
                    )
                );
            }

            if (isset($this->statistics['User']) && $this->statistics['User']['created'] > 0 && !$options['dry_run']) {
                if (!empty($options['admin_password'])) {
                    $secretsHandler->setAdminPassword($options['admin_password'], $this->warnings);
                } else {
                    $this->warnings[] = 'WICHTIG: Benutzer-Passwörter werden aus Sicherheitsgründen nicht im Backup gespeichert. Alle wiederhergestellten Benutzer müssen ihr Passwort zurücksetzen oder neu setzen lassen. Nutzen Sie: php bin/console app:setup-permissions --admin-email=EMAIL --admin-password=PASSWORT';
                }
            }

            // The entity writer records per-row errors into statistics WITHOUT
            // aborting, so previously a root-entity failure (e.g. Tenant — which
            // every other entity FKs to) still returned success=true and left a
            // half-populated database. In strict mode any error must therefore
            // mark the restore as failed; best_effort callers opt into skipping
            // rows, so they keep success=true but can inspect errors_total.
            $errorsTotal = 0;
            foreach ($this->statistics as $statistic) {
                if (is_array($statistic)) {
                    $errorsTotal += $statistic['errors'] ?? 0;
                }
            }
            $success = $options['best_effort'] || $errorsTotal === 0;

            return [
                'success'      => $success,
                'errors_total' => $errorsTotal,
                'statistics'   => $this->statistics,
                'warnings'     => $this->warnings,
                'failures'     => $this->failures,
                'dry_run'      => $options['dry_run'],
            ];
        } catch (Exception $e) {
            try {
                if ($this->entityManager->isOpen() && $this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
            } catch (Exception $rollbackException) {
                $this->logger->error('Rollback failed', ['error' => $rollbackException->getMessage()]);
            }

            if ($disableFKChecks) {
                try {
                    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                } catch (Exception $fkException) {
                    $this->logger->error('Failed to re-enable foreign key checks after error', ['error' => $fkException->getMessage()]);
                }
            }

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

    // ------------------------------------------------------------------
    // Public accessor methods
    // ------------------------------------------------------------------

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
     * Get statistics
     */
    public function getStatistics(): array
    {
        return $this->statistics;
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
}
