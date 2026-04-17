<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FulfillmentInheritanceLog>
 */
class FulfillmentInheritanceLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FulfillmentInheritanceLog::class);
    }

    public function countPendingReview(Tenant $tenant, ?ComplianceFramework $framework = null): int
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.tenant = :tenant')
            ->andWhere('l.reviewStatus IN (:pending)')
            ->setParameter('tenant', $tenant)
            ->setParameter('pending', [
                FulfillmentInheritanceLog::STATUS_PENDING_REVIEW,
                FulfillmentInheritanceLog::STATUS_SOURCE_UPDATED,
            ]);

        if ($framework !== null) {
            $qb->innerJoin('l.fulfillment', 'f')
                ->innerJoin('f.complianceRequirement', 'r')
                ->andWhere('r.complianceFramework = :framework')
                ->setParameter('framework', $framework);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return FulfillmentInheritanceLog[]
     */
    public function findForQueue(Tenant $tenant, ComplianceFramework $framework, ?string $statusFilter = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->innerJoin('l.fulfillment', 'f')
            ->innerJoin('f.complianceRequirement', 'r')
            ->innerJoin('l.derivedFromMapping', 'm')
            ->where('l.tenant = :tenant')
            ->andWhere('r.complianceFramework = :framework')
            ->setParameter('tenant', $tenant)
            ->setParameter('framework', $framework)
            ->orderBy('m.confidence', 'DESC')
            ->addOrderBy('l.suggestedPercentage', 'DESC');

        if ($statusFilter === 'pending') {
            $qb->andWhere('l.reviewStatus IN (:statuses)')
                ->setParameter('statuses', [
                    FulfillmentInheritanceLog::STATUS_PENDING_REVIEW,
                    FulfillmentInheritanceLog::STATUS_SOURCE_UPDATED,
                ]);
        } elseif ($statusFilter === 'source_updated') {
            $qb->andWhere('l.reviewStatus = :status')
                ->setParameter('status', FulfillmentInheritanceLog::STATUS_SOURCE_UPDATED);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return FulfillmentInheritanceLog[]
     */
    public function findByFulfillment(ComplianceRequirementFulfillment $fulfillment): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.fulfillment = :fulfillment')
            ->setParameter('fulfillment', $fulfillment)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Logs for fulfillments derived from a specific source-fulfillment.
     * Used by NotifyDerivedFulfillmentsListener when source changes.
     *
     * @return FulfillmentInheritanceLog[]
     */
    public function findDerivedFromSource(ComplianceRequirementFulfillment $sourceFulfillment): array
    {
        return $this->createQueryBuilder('l')
            ->innerJoin('l.derivedFromMapping', 'm')
            ->innerJoin('m.sourceRequirement', 'sr')
            ->innerJoin(
                ComplianceRequirementFulfillment::class,
                'sf',
                'WITH',
                'sf.complianceRequirement = sr AND sf.tenant = l.tenant'
            )
            ->where('sf = :source')
            ->andWhere('l.reviewStatus NOT IN (:terminal)')
            ->setParameter('source', $sourceFulfillment)
            ->setParameter('terminal', [FulfillmentInheritanceLog::STATUS_REJECTED])
            ->getQuery()
            ->getResult();
    }
}
