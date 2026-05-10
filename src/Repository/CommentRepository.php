<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Audit V3 C7 — Comment repository.
 *
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * @return array<int, Comment>
     */
    public function findThread(Tenant $tenant, string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.entityType = :type')
            ->andWhere('c.entityId = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('type', $entityType)
            ->setParameter('id', $entityId)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
