<?php

namespace App\Repository;

use App\Entity\Incident;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Incident Repository
 *
 * Repository for querying security Incident entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Incident>
 *
 * @method Incident|null find($id, $lockMode = null, $lockVersion = null)
 * @method Incident|null findOneBy(array $criteria, array $orderBy = null)
 * @method Incident[]    findAll()
 * @method Incident[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    /**
     * Generate next incident number for a tenant.
     * Format: INC-YYYY-NNNN (e.g., INC-2025-0001)
     */
    public function getNextIncidentNumber(\App\Entity\Tenant $tenant): string
    {
        $year = date('Y');
        $prefix = "INC-{$year}-";

        $result = $this->createQueryBuilder('i')
            ->select('MAX(i.incidentNumber) as maxNumber')
            ->where('i.tenant = :tenant')
            ->andWhere('i.incidentNumber LIKE :prefix')
            ->setParameter('tenant', $tenant)
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->getSingleScalarResult();

        if ($result) {
            // Extract number part and increment
            $lastNumber = (int) substr($result, -4);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Find all open/active security incidents ordered by severity and detection date.
     *
     * @return Incident[] Array of open Incident entities
     */
    public function findOpenIncidents(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status IN (:statuses)')
            ->setParameter('statuses', ['open', 'investigating', 'in_progress'])
            ->orderBy('i.severity', 'DESC')
            ->addOrderBy('i.detectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count incidents grouped by category.
     *
     * @return array<array{category: string, count: int}> Array of counts per category
     */
    public function countByCategory(): array
    {
        return $this->createQueryBuilder('i')
            ->select('i.category, COUNT(i.id) as count')
            ->groupBy('i.category')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count incidents grouped by severity level.
     *
     * @return array<array{severity: string, count: int}> Array of counts per severity
     */
    public function countBySeverity(): array
    {
        return $this->createQueryBuilder('i')
            ->select('i.severity, COUNT(i.id) as count')
            ->groupBy('i.severity')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all incidents for a tenant (own incidents only)
     *
     * @param \App\Entity\Tenant $tenant The tenant to find incidents for
     * @return Incident[] Array of Incident entities
     */
    public function findByTenant($tenant): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('i.detectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find incidents by tenant including all ancestors (for hierarchical governance)
     * This allows viewing inherited incidents from parent companies, grandparents, etc.
     *
     * @param \App\Entity\Tenant $tenant The tenant to find incidents for
     * @param \App\Entity\Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return Incident[] Array of Incident entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent($tenant, $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $qb = $this->createQueryBuilder('i')
            ->where('i.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include incidents from all ancestors in the hierarchy
        if (!empty($ancestors)) {
            $qb->orWhere('i.tenant IN (:ancestors)')
               ->setParameter('ancestors', $ancestors);
        }

        return $qb
            ->orderBy('i.detectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find incidents by tenant including all subsidiaries (for corporate parent view)
     * This allows viewing aggregated incidents from all subsidiary companies
     *
     * @param \App\Entity\Tenant $tenant The tenant to find incidents for
     * @return Incident[] Array of Incident entities (own + from all subsidiaries)
     */
    public function findByTenantIncludingSubsidiaries($tenant): array
    {
        // Get all subsidiaries recursively
        $subsidiaries = $tenant->getAllSubsidiaries();

        $qb = $this->createQueryBuilder('i')
            ->where('i.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include incidents from all subsidiaries in the hierarchy
        if (!empty($subsidiaries)) {
            $qb->orWhere('i.tenant IN (:subsidiaries)')
               ->setParameter('subsidiaries', $subsidiaries);
        }

        return $qb
            ->orderBy('i.detectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
