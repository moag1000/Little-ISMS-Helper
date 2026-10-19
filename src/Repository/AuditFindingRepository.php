<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditFinding;
use App\Entity\Tenant;
use App\Enum\AuditFindingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditFinding>
 */
class AuditFindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditFinding::class);
    }

    /**
     * Findings that are not done ({@see AuditFindingStatus::isDone()}).
     *
     * @return AuditFinding[]
     */
    public function findOpenByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.status NOT IN (:done)')
            ->setParameter('tenant', $tenant)
            ->setParameter('done', AuditFindingStatus::doneValues())
            ->orderBy('f.severity', 'ASC')
            ->addOrderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open findings (not done, {@see AuditFindingStatus::isDone()}) that carry NO linked
     * ComplianceRequirements. Shared single source of truth for
     * NonconformityAutoTaskTipRule (the Alva hint) and the finding index
     * `focus=nc_unreferenced` filter, so the hint deep-links to EXACTLY the
     * findings it counts.
     *
     * @return AuditFinding[]
     */
    public function findOpenWithoutRequirements(Tenant $tenant): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.linkedRequirements', 'lr')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.status NOT IN (:done)')
            ->andWhere('lr.id IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('done', AuditFindingStatus::doneValues())
            ->orderBy('f.severity', 'ASC')
            ->addOrderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return AuditFinding[] */
    public function findOverdue(Tenant $tenant): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.dueDate IS NOT NULL')
            ->andWhere('f.dueDate < :now')
            ->andWhere('f.status NOT IN (:done)')
            ->setParameter('tenant', $tenant)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('done', AuditFindingStatus::doneValues())
            ->getQuery()
            ->getResult();
    }
}
