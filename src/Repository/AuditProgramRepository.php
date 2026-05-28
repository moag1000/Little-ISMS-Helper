<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditProgram;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * AuditProgram Repository
 *
 * @extends ServiceEntityRepository<AuditProgram>
 *
 * @method AuditProgram|null find($id, $lockMode = null, $lockVersion = null)
 * @method AuditProgram|null findOneBy(array $criteria, array $orderBy = null)
 * @method AuditProgram[]    findAll()
 * @method AuditProgram[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AuditProgramRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditProgram::class);
    }

    /**
     * Find all programs for a tenant, ordered by startDate DESC, name ASC.
     *
     * @return AuditProgram[]
     */
    public function findAllByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ap')
            ->where('ap.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('ap.startDate', 'DESC')
            ->addOrderBy('ap.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active programs for a tenant.
     *
     * @return AuditProgram[]
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ap')
            ->where('ap.tenant = :tenant')
            ->andWhere('ap.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'active')
            ->orderBy('ap.startDate', 'DESC')
            ->addOrderBy('ap.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find programs by status and tenant.
     *
     * @return AuditProgram[]
     */
    public function findByStatusAndTenant(string $status, Tenant $tenant): array
    {
        return $this->createQueryBuilder('ap')
            ->where('ap.tenant = :tenant')
            ->andWhere('ap.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', $status)
            ->orderBy('ap.startDate', 'DESC')
            ->addOrderBy('ap.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
