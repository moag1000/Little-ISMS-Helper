<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditFinding;
use App\Entity\Tenant;
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

    /** @return AuditFinding[] */
    public function findOpenByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.status NOT IN (:closed)')
            ->setParameter('tenant', $tenant)
            ->setParameter('closed', [AuditFinding::STATUS_CLOSED, AuditFinding::STATUS_VERIFIED])
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
            ->andWhere('f.status NOT IN (:closed)')
            ->setParameter('tenant', $tenant)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('closed', [AuditFinding::STATUS_CLOSED, AuditFinding::STATUS_VERIFIED, AuditFinding::STATUS_RESOLVED])
            ->getQuery()
            ->getResult();
    }
}
