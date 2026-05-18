<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Enum\ManagementReviewStatus;
use DateTimeImmutable;
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

    /**
     * Audit V4 V4-LB-1 Round-2 — Upcoming management reviews within a
     * window (default 90 days). ISO 27001 Clause 9.3 mandates planned
     * intervals; surfacing the next-review-due-date to top management
     * keeps the review cadence honest.
     *
     * `status = 'planned'` AND `reviewDate BETWEEN today AND today+window`.
     * Tenant-scoped.
     *
     * @return ManagementReview[]
     */
    public function findUpcomingByTenant(Tenant $tenant, int $windowDays = 90): array
    {
        $today = new DateTimeImmutable('today');
        $deadline = $today->modify("+{$windowDays} days");

        return $this->createQueryBuilder('m')
            ->where('m.tenant = :tenant')
            ->andWhere('m.status = :status')
            ->andWhere('m.reviewDate IS NOT NULL')
            ->andWhere('m.reviewDate BETWEEN :today AND :deadline')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', ManagementReviewStatus::Planned->value)
            ->setParameter('today', $today)
            ->setParameter('deadline', $deadline)
            ->orderBy('m.reviewDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
