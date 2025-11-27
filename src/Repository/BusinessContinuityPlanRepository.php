<?php

namespace App\Repository;

use DateTime;
use App\Entity\BusinessContinuityPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BusinessContinuityPlan>
 */
class BusinessContinuityPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BusinessContinuityPlan::class);
    }

    /**
     * Find plans with overdue tests
     */
    public function findOverdueTests(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.nextTestDate < :now OR (p.lastTested IS NULL AND p.status = :active)')
            ->setParameter('now', new DateTime())
            ->setParameter('active', 'active')
            ->orderBy('p.nextTestDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find plans with overdue reviews
     */
    public function findOverdueReviews(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.nextReviewDate < :now')
            ->setParameter('now', new DateTime())
            ->orderBy('p.nextReviewDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active plans
     */
    public function findActivePlans(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :active')
            ->setParameter('active', 'active')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get BC plan statistics
     */
    public function getStatistics(): array
    {
        $total = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $active = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :active')
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $overdueTests = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.nextTestDate < :now OR (p.lastTested IS NULL AND p.status = :active)')
            ->setParameter('now', new DateTime())
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        $overdueReviews = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.nextReviewDate < :now')
            ->setParameter('now', new DateTime())
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'active' => $active,
            'overdue_tests' => $overdueTests,
            'overdue_reviews' => $overdueReviews
        ];
    }
}
