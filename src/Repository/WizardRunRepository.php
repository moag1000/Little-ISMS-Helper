<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\WizardRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WizardRun>
 *
 * @method WizardRun|null find($id, $lockMode = null, $lockVersion = null)
 * @method WizardRun|null findOneBy(array $criteria, array $orderBy = null)
 * @method WizardRun[]    findAll()
 * @method WizardRun[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WizardRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WizardRun::class);
    }

    /**
     * Open (in_progress) runs for a tenant, newest first.
     *
     * @return WizardRun[]
     */
    public function findOpenForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'in_progress')
            ->orderBy('r.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sandbox runs older than `$cutoff` — for the auto-purge job
     * (architecture §6.4 says sandbox runs are purged after 7 days).
     *
     * @return WizardRun[]
     */
    public function findSandboxOlderThan(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.startedAt < :cutoff')
            ->setParameter('status', 'sandbox')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
