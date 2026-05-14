<?php

declare(strict_types=1);

namespace App\Repository\Authority;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * F29 — Repository for Nis2RegistrationProfile.
 *
 * @extends ServiceEntityRepository<Nis2RegistrationProfile>
 */
class Nis2RegistrationProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Nis2RegistrationProfile::class);
    }

    /**
     * Returns the NIS-2 registration profile for the given tenant, or null
     * if no profile exists yet.
     */
    public function findForTenant(Tenant $tenant): ?Nis2RegistrationProfile
    {
        return $this->createQueryBuilder('p')
            ->where('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns all profiles whose nextDueAt falls within the next $days days.
     * Used by the cron reminder command to send due-soon warnings.
     *
     * @return Nis2RegistrationProfile[]
     */
    public function findDueWithin(int $days = 30): array
    {
        $now = new DateTimeImmutable();
        $threshold = new DateTimeImmutable(sprintf('+%d days', $days));

        return $this->createQueryBuilder('p')
            ->where('p.nextDueAt > :now')
            ->andWhere('p.nextDueAt <= :threshold')
            ->setParameter('now', $now)
            ->setParameter('threshold', $threshold)
            ->orderBy('p.nextDueAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all profiles where nextDueAt is in the past (overdue).
     * Used by the cron reminder command to send critical overdue alerts.
     *
     * @return Nis2RegistrationProfile[]
     */
    public function findOverdue(): array
    {
        $now = new DateTimeImmutable();

        return $this->createQueryBuilder('p')
            ->where('p.nextDueAt < :now')
            ->setParameter('now', $now)
            ->orderBy('p.nextDueAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
