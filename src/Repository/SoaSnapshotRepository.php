<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SoaSnapshot;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SoaSnapshot Repository
 *
 * Frozen point-in-time SoA records keyed by (tenant, asOfDate).
 *
 * @extends ServiceEntityRepository<SoaSnapshot>
 *
 * @method SoaSnapshot|null find($id, $lockMode = null, $lockVersion = null)
 * @method SoaSnapshot|null findOneBy(array $criteria, array $orderBy = null)
 * @method SoaSnapshot[]    findAll()
 * @method SoaSnapshot[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SoaSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SoaSnapshot::class);
    }

    /**
     * @return SoaSnapshot[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.asOfDate', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lookup an existing snapshot for a tenant + as-of-date pair.
     * Used by the cert-bundle exporter to skip re-creation when a
     * snapshot for the requested cut-off already exists. Returns
     * the most recently created one when several snapshots share
     * the same as-of-date (e.g. dry-run + final).
     */
    public function findByTenantAndDate(Tenant $tenant, DateTimeImmutable $asOfDate): ?SoaSnapshot
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenant')
            ->andWhere('s.asOfDate = :asOfDate')
            ->setParameter('tenant', $tenant)
            ->setParameter('asOfDate', $asOfDate->setTime(0, 0, 0))
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
