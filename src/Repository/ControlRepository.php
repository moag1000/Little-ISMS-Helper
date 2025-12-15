<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\Control;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Control Repository
 *
 * Repository for querying ISO 27001 Control entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Control>
 *
 * @method Control|null find($id, $lockMode = null, $lockVersion = null)
 * @method Control|null findOneBy(array $criteria, array $orderBy = null)
 * @method Control[]    findAll()
 * @method Control[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ControlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Control::class);
    }

    /**
     * Find all controls ordered by ISO 27001 control ID in natural order.
     *
     * Sorts controls by the numeric parts of controlId (e.g., 5.1, 5.2, ..., 5.10, 5.37)
     * instead of lexicographic sort (5.1, 5.10, 5.11, ..., 5.2).
     *
     * Uses LENGTH + ASC for correct natural sorting (5.1 < 5.2 < 5.10).
     *
     * @return Control[] Array of Control entities in ISO 27001 natural order
     */
    public function findAllInIsoOrder(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('LENGTH(c.controlId)', 'ASC')
            ->addOrderBy('c.controlId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find controls by category ordered by ISO 27001 control ID in natural order.
     *
     * @param string $category The category to filter by
     * @return Control[] Array of Control entities in ISO 27001 natural order
     */
    public function findByCategoryInIsoOrder(string $category): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.category = :category')
            ->setParameter('category', $category)
            ->orderBy('LENGTH(c.controlId)', 'ASC')
            ->addOrderBy('c.controlId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all applicable ISO 27001 controls ordered by control ID.
     *
     * @return Control[] Array of applicable Control entities
     */
    public function findApplicableControls(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.applicable = :applicable')
            ->setParameter('applicable', true)
            ->orderBy('c.controlId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count controls grouped by ISO 27001 Annex A category.
     *
     * @return array<array{category: string, total: int, applicable: int}> Array with total and applicable counts per category
     */
    public function countByCategory(): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.category, COUNT(c.id) as total, SUM(CASE WHEN c.applicable = true THEN 1 ELSE 0 END) as applicable')
            ->groupBy('c.category')
            ->orderBy('c.category', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get control implementation statistics grouped by status.
     *
     * @return array{total: int, implemented: int, in_progress: int, not_started: int, not_applicable: int} Control statistics
     */
    public function getImplementationStats(): array
    {
        $rawStats = $this->createQueryBuilder('c')
            ->select('c.implementationStatus, COUNT(c.id) as count')
            ->where('c.applicable = :applicable')
            ->setParameter('applicable', true)
            ->groupBy('c.implementationStatus')
            ->getQuery()
            ->getResult();

        // Transform to template-friendly format
        $stats = [
            'total' => 0,
            'implemented' => 0,
            'in_progress' => 0,
            'not_started' => 0,
            'not_applicable' => 0,
        ];

        foreach ($rawStats as $rawStat) {
            $status = $rawStat['implementationStatus'] ?? 'not_started';
            $count = (int) $rawStat['count'];
            $stats['total'] += $count;

            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
        }

        // Add not applicable controls
        $notApplicableCount = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.applicable = :applicable')
            ->setParameter('applicable', false)
            ->getQuery()
            ->getSingleScalarResult();

        $stats['not_applicable'] = (int) $notApplicableCount;

        return $stats;
    }

    /**
     * Find all controls for a tenant (own controls only)
     *
     * @param Tenant $tenant The tenant to find controls for
     * @return Control[] Array of Control entities
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('LENGTH(c.controlId)', 'ASC')
            ->addOrderBy('c.controlId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find controls by tenant including all ancestors (for hierarchical governance)
     * This allows viewing inherited controls from parent companies, grandparents, etc.
     *
     * @param Tenant $tenant The tenant to find controls for
     * @param Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return Control[] Array of Control entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent(Tenant $tenant, Tenant|null $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $queryBuilder = $this->createQueryBuilder('c')
            ->where('c.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include controls from all ancestors in the hierarchy
        if ($ancestors !== []) {
            $queryBuilder->orWhere('c.tenant IN (:ancestors)')
               ->setParameter('ancestors', $ancestors);
        }

        return $queryBuilder
            ->orderBy('LENGTH(c.controlId)', 'ASC')
            ->addOrderBy('c.controlId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a specific control by controlId and tenant
     *
     * @param string $controlId The ISO 27001 control ID (e.g., "5.1", "8.3")
     * @param Tenant $tenant The tenant
     * @return Control|null The control or null if not found
     */
    public function findByControlIdAndTenant(string $controlId, Tenant $tenant): ?Control
    {
        return $this->createQueryBuilder('c')
            ->where('c.controlId = :controlId')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('controlId', $controlId)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get implementation statistics for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{total: int, implemented: int, in_progress: int, not_started: int, not_applicable: int} Control statistics
     */
    public function getImplementationStatsByTenant(Tenant $tenant): array
    {
        $rawStats = $this->createQueryBuilder('c')
            ->select('c.implementationStatus, COUNT(c.id) as count')
            ->where('c.tenant = :tenant')
            ->andWhere('c.applicable = :applicable')
            ->setParameter('tenant', $tenant)
            ->setParameter('applicable', true)
            ->groupBy('c.implementationStatus')
            ->getQuery()
            ->getResult();

        $stats = [
            'total' => 0,
            'implemented' => 0,
            'in_progress' => 0,
            'not_started' => 0,
            'not_applicable' => 0,
        ];

        foreach ($rawStats as $rawStat) {
            $status = $rawStat['implementationStatus'] ?? 'not_started';
            $count = (int) $rawStat['count'];
            $stats['total'] += $count;

            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
        }

        $notApplicableCount = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.tenant = :tenant')
            ->andWhere('c.applicable = :applicable')
            ->setParameter('tenant', $tenant)
            ->setParameter('applicable', false)
            ->getQuery()
            ->getSingleScalarResult();

        $stats['not_applicable'] = (int) $notApplicableCount;

        return $stats;
    }

    /**
     * Find controls by tenant including all subsidiaries (for corporate parent view)
     * This allows viewing aggregated controls from all subsidiary companies
     *
     * @param Tenant $tenant The tenant to find controls for
     * @return Control[] Array of Control entities (own + from all subsidiaries)
     */
    public function findByTenantIncludingSubsidiaries(Tenant $tenant): array
    {
        // Get all subsidiaries recursively
        $subsidiaries = $tenant->getAllSubsidiaries();

        $queryBuilder = $this->createQueryBuilder('c')
            ->where('c.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include controls from all subsidiaries in the hierarchy
        if ($subsidiaries !== []) {
            $queryBuilder->orWhere('c.tenant IN (:subsidiaries)')
               ->setParameter('subsidiaries', $subsidiaries);
        }

        return $queryBuilder
            ->orderBy('LENGTH(c.controlId)', 'ASC')
            ->addOrderBy('c.controlId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find controls by their control IDs (e.g., '5.1', '5.2', 'A.5.1')
     *
     * @param array $controlIds Array of control ID strings
     * @return Control[] Array of matching Control entities
     */
    public function findByControlIds(array $controlIds): array
    {
        if (empty($controlIds)) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.controlId IN (:controlIds)')
            ->setParameter('controlIds', $controlIds)
            ->orderBy('LENGTH(c.controlId)', 'ASC')
            ->addOrderBy('c.controlId', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
