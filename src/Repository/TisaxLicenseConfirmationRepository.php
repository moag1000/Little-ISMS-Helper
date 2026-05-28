<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\TisaxLicenseConfirmation;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TisaxLicenseConfirmation>
 */
class TisaxLicenseConfirmationRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly int $ttlHours = 24,
    ) {
        parent::__construct($registry, TisaxLicenseConfirmation::class);
    }

    /**
     * Find confirmations that still carry a non-empty ip_address and were
     * confirmed before the given cutoff.  Used by the IP-retention purge command.
     *
     * @return list<TisaxLicenseConfirmation>
     */
    public function findWithIpAddressOlderThan(DateTimeImmutable $cutoff): array
    {
        /** @var list<TisaxLicenseConfirmation> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.confirmedAt < :cutoff')
            ->andWhere("c.ipAddress != ''")
            ->setParameter('cutoff', $cutoff)
            ->orderBy('c.confirmedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find the most recent valid (within TTL hours) confirmation for a given
     * user + tenant combination. Returns null when no valid confirmation
     * exists (forces user back to Step 0).
     *
     * TTL is configurable via the `app.tisax.license_ttl_hours` container parameter.
     */
    public function findValidConfirmation(User $user, Tenant $tenant): ?TisaxLicenseConfirmation
    {
        $cutoff = new DateTimeImmutable(sprintf('-%d hours', $this->ttlHours));

        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.confirmedAt >= :cutoff')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('c.confirmedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
