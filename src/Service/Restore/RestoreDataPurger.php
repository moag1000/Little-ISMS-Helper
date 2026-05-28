<?php

declare(strict_types=1);

namespace App\Service\Restore;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Handles clearing existing data before a restore operation and tenant-scope filtering.
 *
 * Responsibilities:
 *  - clearExistingData — DQL DELETE per entity + AUTO_INCREMENT reset
 *  - clearPivotTables  — ManyToMany pivot-table cleanup
 *  - resolveTenantScopeIds — build int[] of IDs from Tenant tree
 *  - filterEntitiesByScope — remove rows outside the restore scope
 *  - entityHasTenantAssociation — helper for scoped vs. global DELETE
 */
class RestoreDataPurger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the list of tenant IDs that belong to the given scope.
     * Returns an empty array when $tenantScope is null (= global restore).
     *
     * @return int[]
     */
    public function resolveTenantScopeIds(?Tenant $tenantScope): array
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
     * whose tenant_id is present in $scopeIds.
     *
     * When $scopeIds is empty (global restore) the original array is returned unchanged.
     *
     * @param array $entities  Array of serialized entity arrays from the backup
     * @param int[] $scopeIds  Tenant IDs that are allowed; empty = all
     * @return array Filtered entities
     */
    public function filterEntitiesByScope(array $entities, array $scopeIds): array
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
     * Clear all existing data for the given entities.
     *
     * When $tenantScopeIds is non-empty (tenant-scoped restore), only rows belonging
     * to those tenant IDs are deleted, leaving all other tenants' data untouched.
     * The AUTO_INCREMENT reset is then skipped.
     *
     * @param array    $entityNames     Entities to clear (should be in reverse dependency order)
     * @param array    $skipEntities    Entities to skip
     * @param int[]    $tenantScopeIds  When non-empty, only delete rows for these tenant IDs
     * @param array    $statistics      Reference to the facade's statistics array (mutated in-place)
     * @param array    $warnings        Reference to the facade's warnings array (mutated in-place)
     */
    public function clearExistingData(
        array $entityNames,
        array $skipEntities,
        array $tenantScopeIds,
        array &$statistics,
        array &$warnings,
    ): void {
        $this->logger->info('Clearing existing data before restore', [
            'scope_ids' => $tenantScopeIds,
        ]);

        $connection = $this->entityManager->getConnection();

        // Step 1: Collect and DELETE all ManyToMany pivot tables first.
        $this->clearPivotTables($entityNames, $skipEntities, $connection, $tenantScopeIds, $warnings);

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
                    $statistics[$entityName . '_cleared'] = $deleted;
                    // Do NOT reset AUTO_INCREMENT for scoped deletes (other tenants still have rows)
                    continue;
                }

                // Use DQL DELETE for efficiency
                $query = $this->entityManager->createQuery(
                    sprintf('DELETE FROM %s e', $entityClass)
                );
                $deleted = $query->execute();

                // Reset AUTO_INCREMENT to 1 after clearing table
                try {
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

                $statistics[$entityName . '_cleared'] = $deleted;
            } catch (Exception $e) {
                $warnings[] = sprintf(
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
     * Only owning-side associations are processed.
     *
     * When $tenantScopeIds is non-empty (tenant-scoped clear), we skip full-table DELETEs for pivot
     * tables and instead let the per-entity scoped DQL DELETE cascade naturally via FK.
     *
     * @param array                         $entityNames    List of entity short names
     * @param array                         $skipEntities   Entity names to skip
     * @param \Doctrine\DBAL\Connection     $connection     DBAL connection (FK_CHECKS already disabled)
     * @param int[]                         $tenantScopeIds When non-empty, skip global pivot deletes
     * @param array                         $warnings       Reference to warnings array (mutated in-place)
     */
    private function clearPivotTables(
        array $entityNames,
        array $skipEntities,
        \Doctrine\DBAL\Connection $connection,
        array $tenantScopeIds,
        array &$warnings,
    ): void {
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
                // Doctrine 3.x → 4.0: AssociationMapping ArrayAccess deprecated.
                $type        = is_array($mapping) ? ($mapping['type'] ?? null)        : ($mapping->type        ?? null);
                $isOwning    = is_array($mapping) ? ($mapping['isOwningSide'] ?? false) : ($mapping->isOwningSide ?? false);
                $joinTable   = is_array($mapping) ? ($mapping['joinTable'] ?? null)   : ($mapping->joinTable   ?? null);

                if ($type !== \Doctrine\ORM\Mapping\ClassMetadata::MANY_TO_MANY) {
                    continue;
                }
                if (!$isOwning) {
                    continue;
                }

                $pivotTable = is_array($joinTable) ? ($joinTable['name'] ?? null) : ($joinTable->name ?? null);
                if ($pivotTable === null || in_array($pivotTable, $clearedPivots)) {
                    continue;
                }

                try {
                    $connection->executeStatement(sprintf('DELETE FROM `%s`', $pivotTable));
                    $clearedPivots[] = $pivotTable;
                    $this->logger->info('Cleared ManyToMany pivot table', ['pivot' => $pivotTable, 'owner' => $entityName]);
                } catch (Exception $e) {
                    $warnings[] = sprintf('Failed to clear pivot table %s: %s', $pivotTable, $e->getMessage());
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
}
