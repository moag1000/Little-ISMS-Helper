<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReuseTrendSnapshot;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReuseTrendSnapshot>
 */
class ReuseTrendSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReuseTrendSnapshot::class);
    }

    public function findByTenantAndDay(Tenant $tenant, DateTimeImmutable $day): ?ReuseTrendSnapshot
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'capturedDay' => $day->setTime(0, 0),
        ]);
    }

    /**
     * @return list<ReuseTrendSnapshot>
     */
    public function findRecentForTenant(Tenant $tenant, int $months = 12): array
    {
        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d months', $months));

        $result = $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :t')
            ->andWhere('s.capturedAt >= :cutoff')
            ->setParameter('t', $tenant)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('s.capturedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values($result) : [];
    }
}
