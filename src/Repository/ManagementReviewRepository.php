<?php

namespace App\Repository;

use App\Entity\ManagementReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Management Review Repository
 *
 * Repository for querying ManagementReview entities (ISO 27001 Clause 9.3).
 *
 * @extends ServiceEntityRepository<ManagementReview>
 *
 * @method ManagementReview|null find($id, $lockMode = null, $lockVersion = null)
 * @method ManagementReview|null findOneBy(array $criteria, array $orderBy = null)
 * @method ManagementReview[]    findAll()
 * @method ManagementReview[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ManagementReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManagementReview::class);
    }

    /**
     * Find most recent management reviews for dashboard.
     *
     * @param int $limit Maximum number of reviews to return (default: 5)
     * @return ManagementReview[] Array of management reviews sorted by review date (newest first)
     */
    public function findLatest(int $limit = 5): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.reviewDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
