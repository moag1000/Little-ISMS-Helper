<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\ThreatLedPenetrationTest;
use App\Enum\ThreatLedPenetrationTestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThreatLedPenetrationTest>
 */
class ThreatLedPenetrationTestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThreatLedPenetrationTest::class);
    }

    /** @return ThreatLedPenetrationTest[] */
    public function findActiveForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.status NOT IN (:closed)')
            ->setParameter('tenant', $tenant)
            ->setParameter('closed', [ThreatLedPenetrationTestStatus::Closed->value, ThreatLedPenetrationTestStatus::Cancelled->value])
            ->orderBy('t.plannedStartDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
