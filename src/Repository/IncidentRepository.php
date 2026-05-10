<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
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
    public function getNextIncidentNumber(Tenant $tenant): string
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
    public function findOpenIncidents(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.tenant = :tenant')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', [
                \App\Enum\IncidentStatus::Reported,
                \App\Enum\IncidentStatus::InInvestigation,
                \App\Enum\IncidentStatus::InResolution,
            ])
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
    public function countByCategory(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->select('i.category, COUNT(i.id) as count')
            ->where('i.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('i.category')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count incidents grouped by severity level.
     *
     * @return array<array{severity: string, count: int}> Array of counts per severity
     */
    public function countBySeverity(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->select('i.severity, COUNT(i.id) as count')
            ->where('i.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('i.severity')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all incidents for a tenant (own incidents only)
     *
     * @param Tenant $tenant The tenant to find incidents for
     * @return Incident[] Array of Incident entities
     */
    public function findByTenant(Tenant $tenant): array
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
     * @param Tenant $tenant The tenant to find incidents for
     * @param Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return Incident[] Array of Incident entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent(Tenant $tenant, Tenant|null $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $queryBuilder = $this->createQueryBuilder('i')
            ->where('i.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include incidents from all ancestors in the hierarchy
        if ($ancestors !== []) {
            $queryBuilder->orWhere('i.tenant IN (:ancestors)')
               ->setParameter('ancestors', $ancestors);
        }

        return $queryBuilder
            ->orderBy('i.detectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find incidents by tenant including all subsidiaries (for corporate parent view)
     * This allows viewing aggregated incidents from all subsidiary companies
     *
     * @param Tenant $tenant The tenant to find incidents for
     * @return Incident[] Array of Incident entities (own + from all subsidiaries)
     */
    public function findByTenantIncludingSubsidiaries(Tenant $tenant): array
    {
        // Get all subsidiaries recursively
        $subsidiaries = $tenant->getAllSubsidiaries();

        $queryBuilder = $this->createQueryBuilder('i')
            ->where('i.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include incidents from all subsidiaries in the hierarchy
        if ($subsidiaries !== []) {
            $queryBuilder->orWhere('i.tenant IN (:subsidiaries)')
               ->setParameter('subsidiaries', $subsidiaries);
        }

        return $queryBuilder
            ->orderBy('i.detectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Audit V4 V4-LB-1 Round-2 — Open incidents currently assigned to a
     * specific user (ISO 27035-1 §6.3 monitoring of incident-handling).
     *
     * `status NOT IN (resolved, closed)` AND (`assignedTo` matches user
     * email/identifier OR user owns Reporter-FK). String-match keeps the
     * legacy free-text `assignedTo` column usable until all incidents are
     * migrated to typed FKs (Phase 8 Owner-Pattern A).
     *
     * @return Incident[]
     */
    public function findOpenAssignedToUser(\App\Entity\User $user, Tenant $tenant): array
    {
        $needle = $user->getUserIdentifier();
        $needleEmail = method_exists($user, 'getEmail') ? (string) $user->getEmail() : '';

        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenant')
            ->andWhere('i.status IN (:openStatuses)')
            ->andWhere('(i.assignedTo IS NOT NULL AND (LOWER(i.assignedTo) LIKE :needle OR LOWER(i.assignedTo) LIKE :email)) OR i.reportedByUser = :user')
            ->setParameter('tenant', $tenant)
            ->setParameter('openStatuses', [
                \App\Enum\IncidentStatus::Reported,
                \App\Enum\IncidentStatus::InInvestigation,
                \App\Enum\IncidentStatus::InResolution,
            ])
            ->setParameter('needle', '%' . strtolower($needle) . '%')
            ->setParameter('email', '%' . strtolower($needleEmail) . '%')
            ->setParameter('user', $user)
            ->orderBy('i.severity', 'DESC')
            ->addOrderBy('i.detectedAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find tenant-less (orphaned) incidents — tenant_id IS NULL.
     *
     * TenantFilter is disabled during the query; otherwise Doctrine combines
     * "tenant IS NULL" with "tenant_id = :current", producing zero results.
     * Caller-side authorization required: only Admins/SuperAdmins may see orphans.
     *
     * @return Incident[]
     */
    public function findOrphaned(): array
    {
        return $this->withoutTenantFilter(
            fn() => $this->createQueryBuilder('i')
                ->where('i.tenant IS NULL')
                ->orderBy('i.detectedAt', 'DESC')
                ->getQuery()
                ->getResult()
        );
    }

    /**
     * Find every incident in the system, regardless of tenant scope.
     *
     * Bypasses TenantFilter — for admin/super-admin tools that need a
     * cross-tenant overview. Caller MUST enforce role-based authorization.
     *
     * @return Incident[]
     */
    public function findAllAcrossTenants(): array
    {
        return $this->withoutTenantFilter(
            fn() => $this->createQueryBuilder('i')
                ->orderBy('i.detectedAt', 'DESC')
                ->getQuery()
                ->getResult()
        );
    }

    /**
     * Run a callback with the Doctrine TenantFilter temporarily disabled.
     */
    private function withoutTenantFilter(callable $fn): mixed
    {
        $em = $this->getEntityManager();
        $filters = $em->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }
        try {
            return $fn();
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }
    }
}
