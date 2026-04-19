<?php

namespace App\Repository;

use App\Entity\PortfolioSnapshot;
use App\Entity\Tenant;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortfolioSnapshot>
 *
 * @method PortfolioSnapshot|null find($id, $lockMode = null, $lockVersion = null)
 * @method PortfolioSnapshot|null findOneBy(array $criteria, array $orderBy = null)
 * @method PortfolioSnapshot[]    findAll()
 * @method PortfolioSnapshot[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PortfolioSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortfolioSnapshot::class);
    }

    /**
     * Has any snapshot row been written for this tenant on $date?
     */
    public function existsForDate(Tenant $tenant, DateTimeInterface $date): bool
    {
        $day = $date instanceof DateTimeImmutable
            ? $date
            : DateTimeImmutable::createFromInterface($date);

        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('s.snapshotDate = :day')
            ->setParameter('tenant', $tenant)
            ->setParameter('day', $day->setTime(0, 0))
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Closest snapshot on or before $date for a specific (tenant, framework, category) cell.
     * Used to compute trend delta for the portfolio report.
     */
    public function findClosestCellOnOrBefore(
        Tenant $tenant,
        DateTimeInterface $date,
        string $frameworkCode,
        string $nistCsfCategory,
    ): ?PortfolioSnapshot {
        $day = $date instanceof DateTimeImmutable
            ? $date
            : DateTimeImmutable::createFromInterface($date);

        $result = $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.snapshotDate <= :day')
            ->andWhere('s.frameworkCode = :framework')
            ->andWhere('s.nistCsfCategory = :category')
            ->setParameter('tenant', $tenant)
            ->setParameter('day', $day->setTime(0, 0))
            ->setParameter('framework', $frameworkCode)
            ->setParameter('category', $nistCsfCategory)
            ->orderBy('s.snapshotDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Return all snapshots for a tenant on a given day, keyed by "FRAMEWORK||CATEGORY".
     * Useful for rendering historical matrices without N+1 queries.
     *
     * @return array<string, PortfolioSnapshot>
     */
    public function findByTenantAndDate(Tenant $tenant, DateTimeInterface $date): array
    {
        $day = $date instanceof DateTimeImmutable
            ? $date
            : DateTimeImmutable::createFromInterface($date);

        /** @var PortfolioSnapshot[] $rows */
        $rows = $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.snapshotDate = :day')
            ->setParameter('tenant', $tenant)
            ->setParameter('day', $day->setTime(0, 0))
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->getFrameworkCode() . '||' . $row->getNistCsfCategory()] = $row;
        }
        return $out;
    }
}
