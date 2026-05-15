<?php

declare(strict_types=1);

namespace App\Repository\Authority;

use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * F30 — Repository for DoraRegisterOfInformation.
 *
 * @extends ServiceEntityRepository<DoraRegisterOfInformation>
 */
class DoraRegisterOfInformationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DoraRegisterOfInformation::class);
    }

    /**
     * Returns the most recent RoI record for the given tenant, or null if none exists.
     */
    public function findLatestForTenant(Tenant $tenant): ?DoraRegisterOfInformation
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.reportingDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns the RoI record for the current calendar year for the given tenant, or null.
     */
    public function findCurrentYearForTenant(Tenant $tenant): ?DoraRegisterOfInformation
    {
        $now = new DateTimeImmutable();
        $yearStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
        $yearEnd = $yearStart->modify('+1 year');

        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.reportingDate >= :year_start')
            ->andWhere('r.reportingDate < :year_end')
            ->setParameter('tenant', $tenant)
            ->setParameter('year_start', $yearStart)
            ->setParameter('year_end', $yearEnd)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns all RoI records for the given tenant, ordered by reporting date desc.
     *
     * @return DoraRegisterOfInformation[]
     */
    public function findAllForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.reportingDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
