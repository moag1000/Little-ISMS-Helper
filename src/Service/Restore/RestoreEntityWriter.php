<?php

declare(strict_types=1);

namespace App\Service\Restore;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Id\AbstractIdGenerator;
use ReflectionClass;
use DateTimeImmutable;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

/**
 * Handles writing entity rows back during a restore operation.
 *
 * Responsibilities:
 *  - restoreEntity        — per-entity-type loop with create/update/skip/best-effort
 *  - restoreManyToManyAssociations — second-pass pivot-table inserts
 *  - getUniqueConstraintFields     — entity-specific unique-field map
 *  - orderEntitiesByDependency     — FK-order sort
 */
class RestoreEntityWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get unique constraint fields for an entity.
     */
    public function getUniqueConstraintFields(string $entityName): array
    {
        $uniqueFieldsMap = [
            'Role' => ['name'],
            'User' => ['email'],
            'Tenant' => ['code'],
            'Permission' => ['name'],
            'ComplianceFramework' => ['code'],
            'Control' => ['controlId'],
            'ComplianceRequirement' => ['requirementId'],
        ];

        return $uniqueFieldsMap[$entityName] ?? [];
    }

    /**
     * Order entities by dependency (FK order — lower priority number restored first).
     */
    public function orderEntitiesByDependency(array $entityNames): array
    {
        $priorityOrder = [
            'Tenant' => 1,
            'Role' => 2,
            'Permission' => 3,
            'User' => 4,
            'Person' => 5,
            'Location' => 6,
            'Supplier' => 7,
            'SystemSettings' => 8,
            'RiskApprovalConfig' => 9,
            'IncidentSlaConfig' => 9,
            'SupplierCriticalityLevel' => 9,
            'KpiThresholdConfig' => 9,
            'Tag' => 9,
            'EntityTag' => 9,
            'ComplianceFramework' => 10,
            'Control' => 11,
            'Asset' => 15,
            'InterestedParty' => 16,
            'ComplianceRequirement' => 20,
            'ComplianceRequirementFulfillment' => 21,
            'Risk' => 25,
            'RiskAppetite' => 26,
            'RiskTreatmentPlan' => 27,
            'Incident' => 28,
            'Vulnerability' => 29,
            'Patch' => 30,
            'ThreatIntelligence' => 31,
            'RiskIncidentLink' => 30,
            'BusinessProcess' => 35,
            'BusinessContinuityPlan' => 36,
            'BCExercise' => 37,
            'CrisisTeam' => 38,
            'Bsi2004ExerciseLog' => 38,
            'ProcessingActivity' => 40,
            'DataProtectionImpactAssessment' => 41,
            'DataBreach' => 42,
            'Consent' => 43,
            'DataSubjectRequest' => 44,
            'PrototypeProtectionAssessment' => 40,
            'Document' => 45,
            'Training' => 46,
            'DocumentVersion' => 46,
            'DocumentSection' => 46,
            'DocumentControlLink' => 46,
            'PolicyAcknowledgement' => 46,
            'TrainingParticipation' => 47,
            'InternalAudit' => 50,
            'AuditChecklist' => 51,
            'AuditFinding' => 52,
            'CorrectiveAction' => 53,
            'AuditFreeze' => 54,
            'ManagementReview' => 55,
            'ThreatLedPenetrationTest' => 56,
            'ISMSContext' => 58,
            'ISMSObjective' => 59,
            'CorporateGovernance' => 60,
            'ChangeRequest' => 62,
            'CryptographicOperation' => 63,
            'PhysicalAccessLog' => 64,
            'FourEyesApprovalRequest' => 65,
            'EvidenceReverificationTask' => 65,
            'ComplianceMapping' => 67,
            'MappingGapItem' => 68,
            'FulfillmentInheritanceLog' => 69,
            'Workflow' => 70,
            'WorkflowStep' => 71,
            'WorkflowInstance' => 72,
            'ScheduledReport' => 75,
            'CustomReport' => 76,
            'AppliedBaseline' => 77,
            'KpiSnapshot' => 78,
            'SoaSnapshot' => 79,
            'DashboardLayout' => 80,
            'MfaToken' => 81,
            'ScheduledTask' => 82,
            'PushSubscription' => 82,
            // Tenant overrides (after Tenant + User)
            'TenantBranding' => 8,
            'TenantPolicySetting' => 8,
            'TenantPolicySettingChangeAttempt' => 8,
            'LifecycleConfig' => 8,
            // SSO
            'IdentityProvider' => 9,
            'IdentityProviderRoleMapping' => 10,
            'IdentityProviderUserMapping' => 10,
            'SsoUserApproval' => 10,
            // Policies
            'PolicyTemplate' => 9,
            'AuthorityTemplate' => 9,
            // Late / cross-cutting
            'Comment' => 85,
            'WizardRun' => 85,
            'WizardSession' => 85,
            'BulkImportBatch' => 85,
            'BulkImportRow' => 86,
            'ImportSession' => 85,
            'ImportRowEvent' => 86,
            'SampleDataImport' => 85,
            'AlvaHintDismissal' => 85,
            'AlvaHintRenderCount' => 85,
            'GuidedTourStepOverride' => 85,
            'AuditLog' => 90,
            'UserSession' => 91,
        ];

        usort($entityNames, static function ($a, $b) use ($priorityOrder): int {
            return ($priorityOrder[$a] ?? 50) <=> ($priorityOrder[$b] ?? 50);
        });

        return $entityNames;
    }

    /**
     * Restore a single entity type.
     *
     * When $options['best_effort'] is true, per-row failures are caught, recorded
     * in $failures, and the loop continues with the next row.
     * Batch savepoints (per 100 rows) protect already-flushed rows from being
     * rolled back by a single bad row.
     *
     * @param array                       $failures        Reference to the facade's failures array (mutated)
     * @param array                       $statistics      Reference to the facade's statistics array (mutated)
     * @param array                       $warnings        Reference to the facade's warnings array (mutated)
     * @param \Doctrine\Persistence\ManagerRegistry|null $registry Optional manager registry for EM reset
     * @param RestoreSecretsHandler|null  $secretsHandler  When set, handles admin_password for User rows
     */
    public function restoreEntity(
        string $entityClass,
        string $entityName,
        array $entities,
        array $options,
        array &$failures,
        array &$statistics,
        array &$warnings,
        ?\Doctrine\Persistence\ManagerRegistry $registry = null,
        ?RestoreSecretsHandler $secretsHandler = null,
    ): void {
        $this->logger->info('Restoring entity', [
            'entity' => $entityName,
            'count' => count($entities),
        ]);

        $bestEffort  = (bool) ($options['best_effort'] ?? false);
        $connection  = $this->entityManager->getConnection();

        $classMetadata    = $this->entityManager->getClassMetadata($entityClass);
        $entityRepository = $this->entityManager->getRepository($entityClass);
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        // Swap ID generator to ASSIGNED so backup IDs are preserved on full-clear restores
        [$originalIdGenerator, $originalGeneratorType] = $this->applyIdGeneratorSwap($classMetadata, $options, $entityName);

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        $uniqueFields = $this->getUniqueConstraintFields($entityName);

        $batchSize    = 100;
        $batchSpName  = null;
        $batchStart   = 0;
        $batchErrored = false;

        foreach ($entities as $index => $data) {
            // Decrypt SystemSettings value on the fly when best_effort deferred decryption
            if ($bestEffort && $entityName === 'SystemSettings' && isset($data['__needs_decrypt__'])) {
                if ($secretsHandler !== null) {
                    try {
                        $data = $secretsHandler->decryptSystemSettingRow($data);
                    } catch (\Throwable $decryptError) {
                        $this->logger->warning('Best-effort: decryption failed for SystemSetting row', [
                            'row_id' => $data['id'] ?? null,
                            'error'  => $decryptError->getMessage(),
                        ]);
                        $failures[] = [
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
            }

            // --- Batch savepoint management (best_effort only) ---
            if ($bestEffort && !$batchErrored) {
                $posInBatch = $index - $batchStart;
                if ($posInBatch === 0) {
                    $batchSpName = 'sp_batch_' . preg_replace('/[^a-zA-Z0-9]/', '_', $entityName) . '_' . $index;
                    try {
                        $this->entityManager->flush();
                        $connection->createSavepoint($batchSpName);
                    } catch (\Throwable) {
                        $batchSpName = null;
                    }
                }
            }

            try {
                if (!$this->entityManager->isOpen()) {
                    $warnings[] = sprintf(
                        'EntityManager closed, skipping remaining %s entities from index %d',
                        $entityName,
                        $index
                    );
                    break;
                }

                // Resolve existing entity + conflict check
                [$existingEntity, $foundById, $conflictingEntity] = $this->resolveExistingEntity(
                    $entityRepository, $entityName, $uniqueFields, $data
                );

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

                if ($conflictingEntity !== null) {
                    $warnings[] = sprintf(
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
                    if ($options['existing_data_strategy'] === RestoreOptions::EXISTING_SKIP) {
                        $stats['skipped']++;
                        continue;
                    }
                    if ($options['existing_data_strategy'] === RestoreOptions::EXISTING_UPDATE) {
                        $entity = $existingEntity;
                    } else {
                        $this->entityManager->remove($existingEntity);
                        $entity = new $entityClass();
                    }
                } else {
                    $entity = new $entityClass();
                }

                // Hydrate scalar fields
                $this->hydrateScalarFields(
                    $entity, $data, $classMetadata, $propertyAccessor,
                    $entityName, $existingEntity, $options, $warnings
                );

                // Special handling for User entities: Set password if admin_password is provided
                if ($entityName === 'User' && !empty($options['admin_password']) && $secretsHandler !== null) {
                    $secretsHandler->applyAdminPasswordToUser($entity, $data, $options['admin_password'], $warnings);
                } elseif ($entityName === 'User' && !empty($options['admin_password']) && $secretsHandler === null) {
                    $this->logger->warning('admin_password provided but no RestoreSecretsHandler injected — password not set', [
                        'user_email' => $data['email'] ?? 'unknown',
                    ]);
                }

                // Restore ManyToOne / OneToOne associations
                $this->resolveAssociations(
                    $entity, $data, $classMetadata, $propertyAccessor, $entityName
                );

                $this->entityManager->persist($entity);

                if ($existingEntity !== null) {
                    $stats['updated']++;
                } else {
                    $stats['created']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;

                if ($bestEffort) {
                    $failures[] = [
                        'entity'        => $entityName,
                        'row_index'     => $index,
                        'row_id'        => $data['id'] ?? null,
                        'error_class'   => $e::class,
                        'error_message' => $e->getMessage(),
                        'original_data' => $data,
                    ];
                    $this->logger->warning('Best-effort: row restore failed, skipping', [
                        'entity' => $entityName, 'index' => $index,
                        'row_id' => $data['id'] ?? null, 'error' => $e->getMessage(),
                    ]);

                    if ($batchSpName !== null && !$batchErrored) {
                        $this->restoreSavepoint($batchSpName, $connection, $entityName);
                        $batchSpName  = null;
                        $batchErrored = true;
                    }

                    if (!$this->entityManager->isOpen()) {
                        $shouldBreak = $this->safeResetManager($registry, $entityName, $index, $warnings);
                        if ($shouldBreak) {
                            break;
                        }
                    }

                    continue;
                }

                $warnings[] = sprintf(
                    'Error restoring %s entity at index %d: %s',
                    $entityName, $index, $e->getMessage()
                );
                $this->logger->error('Error restoring entity', [
                    'entity' => $entityName, 'index' => $index, 'error' => $e->getMessage(),
                ]);

                if (!$this->entityManager->isOpen()) {
                    $warnings[] = 'EntityManager closed due to database error. Restore aborted.';
                    break;
                }
            }

            // Release batch savepoint every $batchSize rows (best_effort)
            if ($bestEffort && !$batchErrored && $batchSpName !== null) {
                $posInBatch = ($index - $batchStart) + 1;
                if ($posInBatch >= $batchSize) {
                    try {
                        $this->entityManager->flush();
                        $connection->releaseSavepoint($batchSpName);
                    } catch (\Throwable) {
                    }
                    $batchSpName  = null;
                    $batchStart   = $index + 1;
                    $batchErrored = false;
                }
            }
        }

        // Final flush for this entity type
        if ($this->entityManager->isOpen()) {
            try {
                $this->logger->debug('Flushing entities to database', [
                    'entity' => $entityName, 'count' => $stats['created'] + $stats['updated'],
                ]);
                $this->entityManager->flush();

                if ($bestEffort && $batchSpName !== null) {
                    try {
                        $connection->releaseSavepoint($batchSpName);
                    } catch (\Throwable) {
                    }
                }

                $this->logger->info('Successfully flushed entities', [
                    'entity' => $entityName, 'created' => $stats['created'], 'updated' => $stats['updated'],
                ]);
            } catch (\Throwable $e) {
                $stats['errors']++;

                if ($bestEffort) {
                    $failures[] = [
                        'entity'        => $entityName,
                        'row_index'     => null,
                        'row_id'        => null,
                        'error_class'   => $e::class,
                        'error_message' => 'Flush error: ' . $e->getMessage(),
                        'original_data' => [],
                    ];
                    $this->logger->warning('Best-effort: flush error for entity-type', [
                        'entity' => $entityName, 'error' => $e->getMessage(),
                    ]);

                    if ($batchSpName !== null) {
                        try {
                            $connection->rollbackSavepoint($batchSpName);
                        } catch (\Throwable) {
                        }
                    }

                    if (!$this->entityManager->isOpen() && $registry !== null) {
                        try {
                            $registry->resetManager();
                        } catch (\Throwable) {
                        }
                    }
                } else {
                    $warnings[] = sprintf(
                        'Error flushing %s entities (database constraint error): %s',
                        $entityName, $e->getMessage()
                    );
                    $this->logger->error('Error flushing entities', [
                        'entity' => $entityName, 'error' => $e->getMessage(),
                        'error_class' => $e::class, 'trace' => $e->getTraceAsString(),
                        'created_count' => $stats['created'], 'updated_count' => $stats['updated'],
                    ]);

                    if (!$this->entityManager->isOpen()) {
                        $warnings[] = sprintf(
                            'EntityManager closed after %s flush error. Remaining entities will be skipped.',
                            $entityName
                        );
                        $this->logger->critical('EntityManager closed after flush error', [
                            'entity' => $entityName, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } else {
            $this->logger->warning('EntityManager already closed, cannot flush', ['entity' => $entityName]);
        }

        // Restore original ID generator if changed
        if ($originalIdGenerator instanceof AbstractIdGenerator && $originalGeneratorType !== null) {
            $classMetadata->setIdGeneratorType($originalGeneratorType);
            $classMetadata->setIdGenerator($originalIdGenerator);
            $this->logger->debug('Restored original ID generator for entity', ['entity' => $entityName]);
        }

        $statistics[$entityName] = $stats;

        $this->logger->info('Entity restore completed', [
            'entity' => $entityName, 'statistics' => $stats,
        ]);
    }

    /**
     * Restore ManyToMany associations via direct DBAL pivot-table inserts.
     * Called in second pass after all entity rows have been flushed.
     *
     * @param array $statistics Reference to the facade's statistics array (mutated in-place)
     * @param array $warnings   Reference to the facade's warnings array (mutated in-place)
     */
    public function restoreManyToManyAssociations(
        string $entityClass,
        string $entityName,
        array $entities,
        array &$statistics,
        array &$warnings,
    ): void {
        try {
            $classMetadata = $this->entityManager->getClassMetadata($entityClass);
        } catch (Exception $e) {
            $this->logger->warning('Could not load metadata for ManyToMany restore', [
                'entity' => $entityName, 'error' => $e->getMessage(),
            ]);
            return;
        }

        $connection = $this->entityManager->getConnection();
        $m2mStats = [];

        foreach ($classMetadata->getAssociationMappings() as $field => $mapping) {
            $type      = is_array($mapping) ? ($mapping['type'] ?? null)          : ($mapping->type          ?? null);
            $isOwning  = is_array($mapping) ? ($mapping['isOwningSide'] ?? false) : ($mapping->isOwningSide  ?? false);
            $joinTable = is_array($mapping) ? ($mapping['joinTable'] ?? null)     : ($mapping->joinTable     ?? null);

            if ($type !== ClassMetadata::MANY_TO_MANY || !$isOwning) {
                continue;
            }

            $pivotTable  = is_array($joinTable) ? ($joinTable['name'] ?? null)              : ($joinTable->name          ?? null);
            $joinCols    = is_array($joinTable) ? ($joinTable['joinColumns'] ?? [])         : ($joinTable->joinColumns    ?? []);
            $invJoinCols = is_array($joinTable) ? ($joinTable['inverseJoinColumns'] ?? [])  : ($joinTable->inverseJoinColumns ?? []);

            if ($pivotTable === null || $joinCols === [] || $invJoinCols === []) {
                continue;
            }

            $ownerCol  = $joinCols[0]['name'];
            $targetCol = $invJoinCols[0]['name'];
            $idsKey    = $field . '_ids';

            $tuples = [];
            foreach ($entities as $data) {
                $ownerId = $data['id'] ?? null;
                if ($ownerId === null || !isset($data[$idsKey]) || !is_array($data[$idsKey])) {
                    continue;
                }
                foreach ($data[$idsKey] as $targetIdData) {
                    $targetId = is_array($targetIdData) ? ($targetIdData['id'] ?? null) : $targetIdData;
                    if ($targetId !== null) {
                        $tuples[] = [$ownerId, $targetId];
                    }
                }
            }

            if ($tuples === []) {
                continue;
            }

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
                    $warnings[] = sprintf(
                        'ManyToMany restore failed for %s.%s (pivot=%s): %s',
                        $entityName, $field, $pivotTable, $e->getMessage()
                    );
                    $this->logger->warning('ManyToMany pivot insert failed', [
                        'entity' => $entityName, 'field' => $field,
                        'pivot' => $pivotTable, 'error' => $e->getMessage(),
                    ]);
                }
            }

            $m2mStats[$field] = ['pivot' => $pivotTable, 'tuples' => $totalTuples, 'inserted' => $inserted];

            $this->logger->info('Restored ManyToMany association', [
                'entity' => $entityName, 'field' => $field, 'pivot' => $pivotTable,
                'tuples' => $totalTuples, 'inserted' => $inserted,
            ]);
        }

        if ($m2mStats !== [] && isset($statistics[$entityName]) && is_array($statistics[$entityName])) {
            $statistics[$entityName]['m2m'] = $m2mStats;
        }
    }

    // =========================================================================
    // Private helpers — extracted from restoreEntity() for readability
    // =========================================================================

    /**
     * Swap ClassMetadata ID generator to AssignedGenerator for clear-before-restore runs.
     *
     * Returns [$originalIdGenerator, $originalGeneratorType] so the caller can
     * restore them after the loop. Both values are null when no swap was performed.
     *
     * @return array{0: AbstractIdGenerator|null, 1: int|null}
     */
    private function applyIdGeneratorSwap(
        ClassMetadata $meta,
        array $options,
        string $entityName,
    ): array {
        if (!$options['clear_before_restore']) {
            return [null, null];
        }

        $originalIdGenerator   = $meta->idGenerator;
        $originalGeneratorType = $meta->generatorType;
        $meta->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
        $meta->setIdGenerator(new AssignedGenerator());
        $this->logger->debug('Changed ID generator to ASSIGNED for entity', ['entity' => $entityName]);

        return [$originalIdGenerator, $originalGeneratorType];
    }

    /**
     * Resolve which DB entity (if any) the incoming backup row maps to.
     *
     * Returns [$existingEntity, $foundById, $conflictingEntity].
     * $conflictingEntity is non-null when a unique-field conflict was detected.
     *
     * @return array{0: object|null, 1: bool, 2: object|null}
     */
    private function resolveExistingEntity(
        object $entityRepository,
        string $entityName,
        array $uniqueFields,
        array $data,
    ): array {
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

        // Check for unique constraint conflicts when found by ID
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

        return [$existingEntity, $foundById, $conflictingEntity];
    }

    /**
     * Set all scalar (non-association) field values on an entity from backup data.
     *
     * Handles: ID injection for clear-before-restore, FK _id field skipping,
     * missing-field strategies, Risk backward-compat defaults, JSON null-protection,
     * and DateTime string coercion.
     */
    private function hydrateScalarFields(
        object $entity,
        array $data,
        ClassMetadata $meta,
        PropertyAccessor $propertyAccessor,
        string $entityName,
        ?object $existingEntity,
        array $options,
        array &$warnings,
    ): void {
        foreach ($meta->getFieldNames() as $fieldName) {
            if ($fieldName === 'id' && $existingEntity !== null) {
                continue;
            }

            if ($fieldName === 'id' && $existingEntity === null && isset($data['id']) && $options['clear_before_restore']) {
                try {
                    $reflection = new ReflectionClass($entity);
                    if ($reflection->hasProperty('id')) {
                        $reflection->getProperty('id')->setValue($entity, $data['id']);
                        $this->logger->debug('Set entity ID from backup', ['entity' => $entityName, 'id' => $data['id']]);
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Failed to set entity ID from backup', [
                        'entity' => $entityName, 'id' => $data['id'], 'error' => $e->getMessage(),
                    ]);
                }
                continue;
            }

            // Skip FK _id fields managed via associations
            if (str_ends_with($fieldName, '_id') && $fieldName !== 'id') {
                $assocName = substr($fieldName, 0, -3);
                if ($meta->hasAssociation($assocName)) {
                    continue;
                }
            }

            if (!array_key_exists($fieldName, $data)) {
                if ($options['missing_field_strategy'] === RestoreOptions::STRATEGY_FAIL) {
                    throw new \App\Exception\Io\IoException(sprintf('Missing field: %s', $fieldName));
                }
                if ($options['missing_field_strategy'] === RestoreOptions::STRATEGY_SKIP_FIELD) {
                    continue;
                }
            }

            $value = $data[$fieldName] ?? null;

            // Default values for Risk entity backward compatibility
            if ($entityName === 'Risk' && $value === null) {
                $value = match ($fieldName) {
                    'category' => 'operational',
                    'involvesPersonalData', 'involvesSpecialCategoryData', 'requiresDPIA' => false,
                    default => null,
                };
                if ($value !== null) {
                    $this->logger->debug("Using default value for Risk.{$fieldName}", [
                        'entity_index' => null, 'default' => $value,
                    ]);
                }
            }

            $type = $meta->getTypeOfField($fieldName);

            // Null protection for non-nullable JSON/array fields
            if ($value === null && in_array($type, ['json', 'simple_array', 'array'], true)) {
                try {
                    $mapping = $meta->getFieldMapping($fieldName);
                    $isNullable = is_array($mapping) ? ($mapping['nullable'] ?? false) : ($mapping->nullable ?? false);
                    if (!$isNullable) {
                        $value = [];
                    }
                } catch (Exception) {
                    $value = [];
                }
            }

            // Null protection for non-nullable SCALAR fields (string/int/bool/etc.).
            // A backup may carry null for a column that maps to a typed, non-nullable
            // PHP property — e.g. Tenant::$status (string, default 'draft') or the
            // @ORM\Version Tenant::$lockVersion (int, default 0). Assigning null to
            // those throws a TypeError that aborts the whole row, and because Tenant
            // is restored first, losing it cascades into "Tenant id(N) not found" for
            // every dependent entity. Leave the property's declared default instead.
            if ($value === null && !in_array($type, ['json', 'simple_array', 'array'], true)) {
                try {
                    $mapping = $meta->getFieldMapping($fieldName);
                    $isNullable = is_array($mapping) ? ($mapping['nullable'] ?? false) : ($mapping->nullable ?? false);
                } catch (Exception) {
                    $isNullable = false;
                }
                if (!$isNullable) {
                    continue; // keep the entity's PHP default; do not assign null
                }
            }

            // Coerce a backing scalar back into its BackedEnum.
            // Doctrine `enumType:` columns hydrate the entity property as an enum
            // instance, so BackupService serialises the enum object — but once the
            // backup is written to disk as JSON the enum collapses to its backing
            // scalar ("identified", 1, …). On restore that scalar must be turned
            // back into the enum, otherwise PropertyAccessor::setValue() throws a
            // TypeError (which is an \Error, NOT an \Exception, so the catch below
            // would miss it and the whole row would be dropped / the restore rolled
            // back). In-memory round-trips never hit this — only file restores do.
            if ($value !== null && (is_string($value) || is_int($value))) {
                $enumClass = $this->resolveEnumType($meta, $fieldName);
                if ($enumClass !== null) {
                    $coerced = $enumClass::tryFrom($value);
                    if ($coerced === null) {
                        // Unknown legacy enum value (enum case removed since backup) —
                        // keep the property's PHP default rather than crash the row.
                        $warnings[] = sprintf(
                            'Warning: %s.%s had unknown enum value "%s"; kept default.',
                            $entityName,
                            $fieldName,
                            (string) $value,
                        );
                        continue;
                    }
                    $value = $coerced;
                }
            }

            // Convert ISO 8601 strings back to DateTime/DateTimeImmutable
            if (in_array($type, ['datetime', 'datetime_immutable', 'date', 'date_immutable', 'time', 'time_immutable'])) {
                try {
                    $expectsImmutable = str_contains((string) $type, 'immutable');
                    if (is_string($value)) {
                        $value = $expectsImmutable ? new DateTimeImmutable($value) : new DateTime($value);
                    } elseif ($value instanceof DateTimeImmutable && !$expectsImmutable) {
                        $value = DateTime::createFromImmutable($value);
                    } elseif ($value instanceof DateTime && $expectsImmutable) {
                        $value = DateTimeImmutable::createFromMutable($value);
                    }
                } catch (Exception $dateException) {
                    $this->logger->warning('Failed to parse date/time value', [
                        'entity' => $entityName, 'field' => $fieldName,
                        'value' => is_object($value) ? $value::class : $value,
                        'error' => $dateException->getMessage(),
                    ]);
                }
            }

            try {
                if ($propertyAccessor->isWritable($entity, $fieldName)) {
                    $propertyAccessor->setValue($entity, $fieldName, $value);
                } else {
                    $reflection = new ReflectionClass($entity);
                    if ($reflection->hasProperty($fieldName)) {
                        $reflection->getProperty($fieldName)->setValue($entity, $value);
                    }
                }
            } catch (\Throwable $e) {
                // \Throwable (not just \Exception) so a TypeError from a property
                // type-mismatch degrades to a skipped field + warning instead of
                // aborting the entire row / restore.
                $this->logger->warning('Failed to set property', [
                    'entity' => $entityName, 'field' => $fieldName,
                    'value_type' => get_debug_type($value), 'error' => $e->getMessage(),
                ]);
                $warnings[] = sprintf('Warning: Could not set %s.%s: %s', $entityName, $fieldName, $e->getMessage());
            }
        }
    }

    /**
     * Return the BackedEnum class declared via `enumType:` for a field, or null.
     *
     * Handles both the ORM 3.x FieldMapping object and the legacy array shape.
     *
     * @return class-string<\BackedEnum>|null
     */
    private function resolveEnumType(ClassMetadata $meta, string $fieldName): ?string
    {
        try {
            // Doctrine ORM 3.x: getFieldMapping() returns a FieldMapping whose
            // ->enumType is class-string<\BackedEnum>|null (null for non-enum fields).
            return $meta->getFieldMapping($fieldName)->enumType;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Restore ManyToOne and OneToOne associations from backup data.
     *
     * ManyToMany is handled in the second-pass via restoreManyToManyAssociations().
     */
    private function resolveAssociations(
        object $entity,
        array $data,
        ClassMetadata $meta,
        PropertyAccessor $propertyAccessor,
        string $entityName,
    ): void {
        foreach ($meta->getAssociationNames() as $assocName) {
            if (!$meta->isSingleValuedAssociation($assocName)) {
                // ManyToMany: handled in second pass via restoreManyToManyAssociations()
                continue;
            }

            $assocIdKey = $assocName . '_id';
            if (!isset($data[$assocIdKey])) {
                continue;
            }

            $targetClass = $meta->getAssociationTargetClass($assocName);
            $assocId = $data[$assocIdKey];

            if (is_array($assocId) && isset($assocId['id'])) {
                $assocId = $assocId['id'];
            }

            if ($assocId === null) {
                continue;
            }

            try {
                $relatedEntity = $this->entityManager->getReference($targetClass, $assocId);

                if ($propertyAccessor->isWritable($entity, $assocName)) {
                    $propertyAccessor->setValue($entity, $assocName, $relatedEntity);
                } else {
                    $reflection = new ReflectionClass($entity);
                    $setterCandidates = [
                        'set' . ucfirst($assocName),
                        'set' . ucfirst(preg_replace('/^.*([A-Z][a-z]+)$/', '$1', $assocName)),
                    ];
                    $setterFound = false;
                    foreach ($setterCandidates as $setterName) {
                        if ($reflection->hasMethod($setterName)) {
                            $reflection->getMethod($setterName)->invoke($entity, $relatedEntity);
                            $setterFound = true;
                            break;
                        }
                    }
                    if (!$setterFound) {
                        throw new \App\Exception\Io\IoException(sprintf(
                            'No setter found for association "%s". Tried: %s',
                            $assocName,
                            implode(', ', $setterCandidates)
                        ));
                    }
                }
            } catch (Exception $e) {
                $this->logger->warning('Failed to restore association', [
                    'entity' => $entityName, 'association' => $assocName,
                    'target_id' => $assocId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Roll back a batch savepoint after a row error (best-effort mode).
     */
    private function restoreSavepoint(
        string $savepoint,
        \Doctrine\DBAL\Connection $connection,
        string $entityName,
    ): void {
        try {
            $this->entityManager->clear();
            $connection->rollbackSavepoint($savepoint);
            $this->logger->info('Best-effort: rolled back batch savepoint', [
                'savepoint' => $savepoint, 'entity' => $entityName,
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Attempt to reset the EntityManager after a fatal row / entity-type failure.
     *
     * Returns true when the caller should abort (no registry available, or reset failed).
     * Returns false when the reset succeeded and processing may continue.
     *
     * Called from:
     *  - restoreEntity() — per-row best-effort error path (private call-site)
     *  - RestoreService::restoreFromBackup() — entity-type error path (public call-site)
     */
    public function safeResetManager(
        ?\Doctrine\Persistence\ManagerRegistry $registry,
        string $context,
        int|string|null $index,
        array &$warnings,
    ): bool {
        if ($registry === null) {
            $warnings[] = 'EntityManager closed due to database error. Restore aborted.';
            return true;
        }

        try {
            $registry->resetManager();
            $this->logger->info('Best-effort: EntityManager reset after row failure', [
                'entity' => $context, 'index' => $index,
            ]);
            return false;
        } catch (\Throwable $resetError) {
            $this->logger->warning('Best-effort: could not reset EntityManager, aborting entity-type', [
                'entity' => $context, 'error' => $resetError->getMessage(),
            ]);
            return true;
        }
    }
}
