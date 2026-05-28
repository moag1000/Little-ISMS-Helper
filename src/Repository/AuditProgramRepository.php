<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditProgram;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditProgram>
 */
class AuditProgramRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditProgram::class);
    }

    /** @return AuditProgram[] */
    public function findAllByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ap')
            ->andWhere('ap.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('ap.startDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return AuditProgram[] */
    public function findActiveByTenant(Tenant $tenant): array
    {
        return $this->findByStatusAndTenant(AuditProgram::STATUS_ACTIVE, $tenant);
    }

    /** @return AuditProgram[] */
    public function findByStatusAndTenant(string $status, Tenant $tenant): array
    {
        return $this->createQueryBuilder('ap')
            ->andWhere('ap.tenant = :tenant')
            ->andWhere('ap.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', $status)
            ->orderBy('ap.startDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
