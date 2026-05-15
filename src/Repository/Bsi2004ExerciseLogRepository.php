<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BCExercise;
use App\Entity\Bsi2004ExerciseLog;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bsi2004ExerciseLog>
 */
class Bsi2004ExerciseLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bsi2004ExerciseLog::class);
    }

    public function findByExercise(BCExercise $exercise): ?Bsi2004ExerciseLog
    {
        return $this->findOneBy(['bcExercise' => $exercise]);
    }

    /**
     * @return array<int, Bsi2004ExerciseLog>
     */
    public function findRecentForTenant(Tenant $tenant, int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns all logs for a tenant that have at least one overdue improvement action.
     * Filtering of individual overdue items is done in-memory via entity method.
     *
     * @return array<int, Bsi2004ExerciseLog>
     */
    public function findImprovementActionsOverdue(Tenant $tenant): array
    {
        /** @var array<int, Bsi2004ExerciseLog> $logs */
        $logs = $this->createQueryBuilder('l')
            ->andWhere('l.tenant = :tenant')
            ->andWhere('l.improvementActions IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $logs,
            static fn (Bsi2004ExerciseLog $log): bool => $log->hasOverdueImprovementActions()
        ));
    }

    /**
     * @return array<int, Bsi2004ExerciseLog>
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
