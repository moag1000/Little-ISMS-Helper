<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorrectiveAction>
 */
class CorrectiveActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorrectiveAction::class);
    }

    /** @return CorrectiveAction[] */
    public function findOverdue(Tenant $tenant): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.plannedCompletionDate IS NOT NULL')
            ->andWhere('a.plannedCompletionDate < :now')
            ->andWhere('a.status IN (:active)')
            ->setParameter('tenant', $tenant)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('active', [CorrectiveAction::STATUS_PLANNED, CorrectiveAction::STATUS_IN_PROGRESS])
            ->getQuery()
            ->getResult();
    }
}
