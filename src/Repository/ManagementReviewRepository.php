<?php

namespace App\Repository;

use App\Entity\ManagementReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ManagementReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManagementReview::class);
    }

    public function findLatest(int $limit = 5): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.reviewDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
