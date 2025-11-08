<?php

namespace App\Repository;

use App\Entity\BCExercise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BCExercise>
 */
class BCExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BCExercise::class);
    }

    /**
     * Find upcoming exercises
     */
    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.exerciseDate >= :now')
            ->andWhere('e.status IN (:statuses)')
            ->setParameter('now', new \DateTime())
            ->setParameter('statuses', ['planned', 'in_progress'])
            ->orderBy('e.exerciseDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find exercises with incomplete reports
     */
    public function findIncompleteReports(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :completed')
            ->andWhere('e.reportCompleted = :false')
            ->setParameter('completed', 'completed')
            ->setParameter('false', false)
            ->orderBy('e.exerciseDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get exercise statistics
     */
    public function getStatistics(): array
    {
        $total = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.status = :completed')
            ->setParameter('completed', 'completed')
            ->getQuery()
            ->getSingleScalarResult();

        $avgSuccessRating = $this->createQueryBuilder('e')
            ->select('AVG(e.successRating)')
            ->where('e.status = :completed')
            ->andWhere('e.successRating IS NOT NULL')
            ->setParameter('completed', 'completed')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_exercises' => $total,
            'avg_success_rating' => round($avgSuccessRating, 2)
        ];
    }
}
