<?php

namespace App\Repository;

use DateTimeImmutable;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplianceRequirementFulfillment>
 *
 * @method ComplianceRequirementFulfillment|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComplianceRequirementFulfillment|null findOneBy(array $criteria, array $orderBy = null)
 * @method ComplianceRequirementFulfillment[]    findAll()
 * @method ComplianceRequirementFulfillment[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ComplianceRequirementFulfillmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceRequirementFulfillment::class);
    }

    /**
     * Find or create fulfillment record for tenant and requirement
     */
    public function findOrCreateForTenantAndRequirement(
        Tenant $tenant,
        ComplianceRequirement $complianceRequirement
    ): ComplianceRequirementFulfillment {
        $fulfillment = $this->findOneBy([
            'tenant' => $tenant,
            'requirement' => $complianceRequirement,
        ]);

        if (!$fulfillment) {
            $fulfillment = new ComplianceRequirementFulfillment();
            $fulfillment->setTenant($tenant);
            $fulfillment->setRequirement($complianceRequirement);
        }

        return $fulfillment;
    }

    /**
     * Get all fulfillments for a tenant (own only, no inheritance)
     *
     * @return ComplianceRequirementFulfillment[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant]);
    }

    /**
     * Get all fulfillments for a tenant including inherited from parent
     * Used for corporate hierarchies with hierarchical governance model
     *
     * @param Tenant $tenant Current tenant
     * @param Tenant|null $parent Parent tenant (optional)
     * @return ComplianceRequirementFulfillment[]
     */
    public function findByTenantIncludingParent(Tenant $tenant, ?Tenant $parent = null): array
    {
        if (!$parent instanceof Tenant) {
            return $this->findByTenant($tenant);
        }

        return $this->createQueryBuilder('f')
            ->where('f.tenant = :tenant OR f.tenant = :parent')
            ->setParameter('tenant', $tenant)
            ->setParameter('parent', $parent)
            ->orderBy('f.tenant', 'DESC') // Own fulfillments first
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all fulfillments for a framework and tenant
     *
     * @return ComplianceRequirementFulfillment[]
     */
    public function findByFrameworkAndTenant(ComplianceFramework $complianceFramework, Tenant $tenant): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.requirement', 'r')
            ->where('r.framework = :framework')
            ->andWhere('f.tenant = :tenant')
            ->setParameter('framework', $complianceFramework)
            ->setParameter('tenant', $tenant)
            ->orderBy('r.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate average fulfillment percentage for a framework and tenant
     *
     * @return float Average percentage (0-100)
     */
    public function getAverageFulfillmentPercentage(ComplianceFramework $complianceFramework, Tenant $tenant): float
    {
        $result = $this->createQueryBuilder('f')
            ->select('AVG(CASE WHEN f.applicable = true THEN f.fulfillmentPercentage ELSE 100 END) as avg_percentage')
            ->join('f.requirement', 'r')
            ->where('r.framework = :framework')
            ->andWhere('f.tenant = :tenant')
            ->setParameter('framework', $complianceFramework)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $result, 2);
    }

    /**
     * Get fulfillments that are overdue for review
     *
     * @return ComplianceRequirementFulfillment[]
     */
    public function findOverdueForReview(Tenant $tenant): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.tenant = :tenant')
            ->andWhere('f.nextReviewDate < :now')
            ->setParameter('tenant', $tenant)
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('f.nextReviewDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get fulfillments by status
     *
     * @return ComplianceRequirementFulfillment[]
     */
    public function findByStatus(Tenant $tenant, string $status): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.tenant = :tenant')
            ->andWhere('f.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', $status)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get compliance statistics for a tenant
     *
     * @return array{total: int, applicable: int, not_applicable: int, fully_implemented: int, in_progress: int, not_started: int, avg_fulfillment: float}
     */
    public function getComplianceStats(Tenant $tenant): array
    {
        $queryBuilder = $this->createQueryBuilder('f');

        $result = $queryBuilder
            ->select([
                'COUNT(f.id) as total',
                'SUM(CASE WHEN f.applicable = true THEN 1 ELSE 0 END) as applicable',
                'SUM(CASE WHEN f.applicable = false THEN 1 ELSE 0 END) as not_applicable',
                'SUM(CASE WHEN f.applicable = true AND f.fulfillmentPercentage = 100 THEN 1 ELSE 0 END) as fully_implemented',
                'SUM(CASE WHEN f.status = :in_progress THEN 1 ELSE 0 END) as in_progress',
                'SUM(CASE WHEN f.status = :not_started THEN 1 ELSE 0 END) as not_started',
                'AVG(CASE WHEN f.applicable = true THEN f.fulfillmentPercentage ELSE 100 END) as avg_fulfillment',
            ])
            ->where('f.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->setParameter('in_progress', 'in_progress')
            ->setParameter('not_started', 'not_started')
            ->getQuery()
            ->getSingleResult();

        return [
            'total' => (int) $result['total'],
            'applicable' => (int) $result['applicable'],
            'not_applicable' => (int) $result['not_applicable'],
            'fully_implemented' => (int) $result['fully_implemented'],
            'in_progress' => (int) $result['in_progress'],
            'not_started' => (int) $result['not_started'],
            'avg_fulfillment' => round((float) $result['avg_fulfillment'], 2),
        ];
    }
}
