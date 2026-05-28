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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TisaxLicenseConfirmation::class);
    }

    /**
     * Find the most recent valid (< 24 h) confirmation for a given
     * user + tenant combination. Returns null when no valid confirmation
     * exists (forces user back to Step 0).
     */
    public function findValidConfirmation(User $user, Tenant $tenant): ?TisaxLicenseConfirmation
    {
        $cutoff = new DateTimeImmutable('-24 hours');

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
