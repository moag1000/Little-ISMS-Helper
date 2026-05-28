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
     * Find all active (approved or active status) programs for a tenant.
     *
     * @return AuditProgram[]
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ap')
            ->where('ap.tenant = :tenant')
            ->andWhere('ap.status IN (:statuses)')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['approved', 'active'])
            ->orderBy('ap.year', 'DESC')
            ->addOrderBy('ap.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find programs by year and tenant.
     *
     * @return AuditProgram[]
     */
    public function findByYearAndTenant(int $year, Tenant $tenant): array
    {
        return $this->createQueryBuilder('ap')
            ->where('ap.tenant = :tenant')
            ->andWhere('ap.year = :year')
            ->setParameter('tenant', $tenant)
            ->setParameter('year', $year)
            ->orderBy('ap.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all programs for a tenant, ordered by year DESC, name ASC.
     *
     * @return AuditProgram[]
     */
    public function findAllByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ap')
            ->where('ap.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('ap.year', 'DESC')
            ->addOrderBy('ap.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
