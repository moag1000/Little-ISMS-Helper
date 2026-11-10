<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\Risk;
use App\Entity\User;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Risk\RiskMatrixThresholds;

/**
 * Risk Repository
 *
 * Repository for querying Risk entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Risk>
 *
 * @method Risk|null find($id, $lockMode = null, $lockVersion = null)
 * @method Risk|null findOneBy(array $criteria, array $orderBy = null)
 * @method Risk[]    findAll()
 * @method Risk[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RiskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Risk::class);
    }

    /**
     * Find risks with high risk scores (probability × impact >= threshold).
     *
     * @param int|null $threshold Minimum risk score to consider as high risk (default: the organisation's band „hoch“)
     * @return Risk[] Array of Risk entities sorted by severity
     */
    public function findHighRisks(Tenant $tenant, ?int $threshold = null): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('(r.probability * r.impact) >= :threshold')
            ->setParameter('tenant', $tenant)
            ->setParameter('threshold', $threshold ?? RiskMatrixThresholds::highMin($tenant))
            ->orderBy('r.probability', \SortDirection::Descending)
            ->addOrderBy('r.impact', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count risks grouped by treatment strategy.
     *
     * @return array<array{treatmentStrategy: string, count: int}> Array of counts per strategy
     */
    public function countByTreatmentStrategy(Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.treatmentStrategy, COUNT(r.id) as count')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('r.treatmentStrategy')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all risks for a tenant (own risks only)
     *
     * @param Tenant $tenant The tenant to find risks for
     * @return Risk[] Array of Risk entities
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Risks for a tenant that have no accountable owner assigned (ISO 27001
     * A.5.4 / Cl. 6.1.2). Shared single source of truth for RiskOhneOwnerRule
     * (the Alva hint) and the risk index `focus=no_owner` filter, so the hint
     * deep-links to EXACTLY the risks it counts.
     *
     * @return Risk[]
     */
    public function findWithoutOwner(Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.riskOwner IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Number of risks per asset for the given asset ids, one GROUP BY (list rows, S31). Assets without risks
     * are reported with 0.
     *
     * @param list<int|null> $assetIds
     * @return array<int, int> asset id => count
     */
    public function countByAssetIds(array $assetIds): array
    {
        $assetIds = array_values(array_filter($assetIds, 'is_int'));
        if ($assetIds === []) {
            return [];
        }
        $counts = array_fill_keys($assetIds, 0);
        foreach ($this->createQueryBuilder('r')
            ->select('IDENTITY(r.asset) AS asset', 'COUNT(r.id) AS n')
            ->where('r.asset IN (:ids)')
            ->setParameter('ids', $assetIds)
            ->groupBy('r.asset')
            ->getQuery()
            ->getScalarResult() as $row) {
            $counts[(int) $row['asset']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Risks of the user's organisation the user owns — as login (riskOwner) or
     * as the person record linked to the login (riskOwnerPerson). A risk owner is
     * whoever owns a risk, not only who holds ROLE_FUNCTION_OWNER (ISO 27001 6.1.2 c).
     */
    public function countOwnedBy(User $user): int
    {
        if ($user->getId() === null || $user->getTenant() === null) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.id)')
            ->leftJoin('r.riskOwnerPerson', 'op')
            ->where('r.tenant = :tenant')
            ->andWhere('r.riskOwner = :user OR op.linkedUser = :user')
            ->setParameter('tenant', $user->getTenant())
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find risks by tenant including all ancestors (for hierarchical governance)
     * This allows viewing inherited risks from parent companies, grandparents, etc.
     *
     * @param Tenant $tenant The tenant to find risks for
     * @param Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return Risk[] Array of Risk entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent(Tenant $tenant, Tenant|null $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $queryBuilder = $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include risks from all ancestors in the hierarchy
        if ($ancestors !== []) {
            $queryBuilder->orWhere('r.tenant IN (:ancestors)')
               ->setParameter('ancestors', $ancestors);
        }

        return $queryBuilder
            ->orderBy('r.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get risk statistics for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{total: int, high: int, medium: int, low: int} Risk statistics
     */
    public function getRiskStatsByTenant(Tenant $tenant): array
    {
        $risks = $this->findByTenant($tenant);

        $stats = [
            'total' => count($risks),
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        foreach ($risks as $risk) {
            $riskScore = ($risk->getProbability() ?? 0) * ($risk->getImpact() ?? 0);

            if ($riskScore >= RiskMatrixThresholds::highMin($tenant)) {
                $stats['high']++;
            } elseif ($riskScore >= RiskMatrixThresholds::mediumMin($tenant)) {
                $stats['medium']++;
            } else {
                $stats['low']++;
            }
        }

        return $stats;
    }

    /**
     * Find high risks for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @param int|null $threshold Minimum risk score threshold (default: the organisation's band „hoch“)
     * @return Risk[] Array of high-risk entities
     */
    public function findHighRisksByTenant(Tenant $tenant, ?int $threshold = null): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('(r.probability * r.impact) >= :threshold')
            ->setParameter('tenant', $tenant)
            ->setParameter('threshold', $threshold ?? RiskMatrixThresholds::highMin($tenant))
            ->orderBy('r.probability', \SortDirection::Descending)
            ->addOrderBy('r.impact', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find risks by tenant including all subsidiaries (for corporate parent view)
     * This allows viewing aggregated risks from all subsidiary companies
     *
     * @param Tenant $tenant The tenant to find risks for
     * @return Risk[] Array of Risk entities (own + from all subsidiaries)
     */
    public function findByTenantIncludingSubsidiaries(Tenant $tenant): array
    {
        // Get all subsidiaries recursively
        $subsidiaries = $tenant->getAllSubsidiaries();

        $queryBuilder = $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include risks from all subsidiaries in the hierarchy
        if ($subsidiaries !== []) {
            $queryBuilder->orWhere('r.tenant IN (:subsidiaries)')
               ->setParameter('subsidiaries', $subsidiaries);
        }

        return $queryBuilder
            ->orderBy('r.createdAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find risks due for periodic review
     *
     * ISO 27001:2022 Clause 6.1.3.d - Risks should be reviewed periodically
     *
     * @param DateTimeInterface $asOf Date to check against (default: today)
     * @return Risk[] Array of risks with nextReviewDate <= asOf that require
     *                periodic review ({@see \App\Enum\RiskStatus::requiresPeriodicReview()})
     */
    public function findDueForReview(?Tenant $tenant = null, DateTimeInterface $asOf = new DateTime()): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.reviewDate IS NOT NULL')
            ->andWhere('r.reviewDate <= :asOf')
            ->andWhere('r.status IN (:reviewable)')
            ->setParameter('asOf', $asOf)
            ->setParameter('reviewable', \App\Enum\RiskStatus::periodicReviewValues());

        if ($tenant !== null) {
            $qb->andWhere('r.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        return $qb->orderBy('r.reviewDate', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find risks whose formal acceptance is about to expire (or has already
     * expired) — for ISB My-Day surfacing.
     *
     * Filter: treatmentStrategy = 'accept' AND status != 'closed' AND
     * acceptanceExpiryDate IS NOT NULL AND acceptanceExpiryDate <= asOf+window.
     *
     * @return Risk[] Sorted by acceptanceExpiryDate ASC (most urgent first)
     */
    public function findAcceptanceExpiring(
        Tenant $tenant,
        int $withinDays = 30,
        ?DateTimeInterface $asOf = null,
    ): array {
        $asOf ??= new DateTime('today');
        $deadline = DateTimeImmutable::createFromInterface($asOf)->modify(sprintf('+%d days', max(0, $withinDays)));

        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.treatmentStrategy = :accept')
            ->andWhere('r.status != :closed')
            ->andWhere('r.acceptanceExpiryDate IS NOT NULL')
            ->andWhere('r.acceptanceExpiryDate <= :deadline')
            ->setParameter('tenant', $tenant)
            ->setParameter('accept', \App\Enum\TreatmentStrategy::Accept)
            ->setParameter('closed', \App\Enum\RiskStatus::Closed)
            ->setParameter('deadline', $deadline)
            ->orderBy('r.acceptanceExpiryDate', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find risks eligible for planning-action conversion for a tenant.
     *
     * Used by RiskTreatmentAdapter::findConvertible() to avoid loading the full
     * tenant risk set and then filtering in PHP.
     *
     * Convertible means:
     *   - treatmentStrategy = Accept (risk has been formally accepted)
     *   - acceptanceExpiryDate IS NOT NULL (acceptance has an expiry SLA)
     *   - status NOT IN [Closed, Monitored] (not terminal per isCompleted())
     *
     * @return Risk[] Sorted by acceptanceExpiryDate ASC (most urgent first)
     */
    public function findConvertibleForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.treatmentStrategy = :accept')
            ->andWhere('r.acceptanceExpiryDate IS NOT NULL')
            ->andWhere('r.status NOT IN (:terminal)')
            ->setParameter('tenant', $tenant)
            ->setParameter('accept', \App\Enum\TreatmentStrategy::Accept)
            ->setParameter('terminal', [\App\Enum\RiskStatus::Closed, \App\Enum\RiskStatus::Monitored])
            ->orderBy('r.acceptanceExpiryDate', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tenant-less (orphaned) risks — tenant_id IS NULL.
     *
     * TenantFilter is disabled during the query; otherwise Doctrine combines
     * "tenant IS NULL" with "tenant_id = :current", producing zero results.
     * Caller-side authorization required: only Admins/SuperAdmins may see orphans.
     *
     * @return Risk[]
     */
    public function findOrphaned(): array
    {
        return $this->withoutTenantFilter(
            fn() => $this->createQueryBuilder('r')
                ->where('r.tenant IS NULL')
                ->orderBy('r.createdAt', \SortDirection::Descending)
                ->getQuery()
                ->getResult()
        );
    }

    /**
     * Find every risk in the system, regardless of tenant scope.
     *
     * Bypasses TenantFilter — for admin/super-admin tools that need a
     * cross-tenant overview. Caller MUST enforce role-based authorization.
     *
     * @return Risk[]
     */
    public function findAllAcrossTenants(): array
    {
        return $this->withoutTenantFilter(
            fn() => $this->createQueryBuilder('r')
                ->orderBy('r.createdAt', \SortDirection::Descending)
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
            $filters->suspend('tenant_filter');
        }
        try {
            return $fn();
        } finally {
            if ($wasEnabled) {
                $filters->restore('tenant_filter');
            }
        }
    }
}
