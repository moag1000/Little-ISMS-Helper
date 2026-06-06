<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Team>
 */
class TeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Team::class);
    }

    /**
     * Active teams for a tenant, name-sorted.
     *
     * @return list<Team>
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        /** @var list<Team> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.isActive = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * All teams for a tenant (active + inactive), name-sorted.
     *
     * @return list<Team>
     */
    public function findByTenant(Tenant $tenant): array
    {
        /** @var list<Team> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
