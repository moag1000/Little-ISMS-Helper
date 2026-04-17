<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntityTag;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntityTag>
 */
class EntityTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntityTag::class);
    }

    /**
     * Currently active tag-links for a given entity.
     *
     * @return EntityTag[]
     */
    public function findActiveFor(string $entityClass, int $entityId): array
    {
        return $this->createQueryBuilder('et')
            ->andWhere('et.entityClass = :c')
            ->andWhere('et.entityId = :i')
            ->andWhere('et.taggedUntil IS NULL')
            ->setParameter('c', $entityClass)
            ->setParameter('i', $entityId)
            ->leftJoin('et.tag', 't')
            ->addSelect('t')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Full history (active + removed) for a given entity, newest first.
     *
     * @return EntityTag[]
     */
    public function findHistoryFor(string $entityClass, int $entityId): array
    {
        return $this->createQueryBuilder('et')
            ->andWhere('et.entityClass = :c')
            ->andWhere('et.entityId = :i')
            ->setParameter('c', $entityClass)
            ->setParameter('i', $entityId)
            ->leftJoin('et.tag', 't')
            ->addSelect('t')
            ->orderBy('et.taggedFrom', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveOne(Tag $tag, string $entityClass, int $entityId): ?EntityTag
    {
        return $this->createQueryBuilder('et')
            ->andWhere('et.tag = :t')
            ->andWhere('et.entityClass = :c')
            ->andWhere('et.entityId = :i')
            ->andWhere('et.taggedUntil IS NULL')
            ->setParameter('t', $tag)
            ->setParameter('c', $entityClass)
            ->setParameter('i', $entityId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Return all entity IDs in `$entityClass` currently carrying `$tag`.
     *
     * @return int[]
     */
    public function findEntityIdsWithTag(Tag $tag, string $entityClass): array
    {
        $rows = $this->createQueryBuilder('et')
            ->select('et.entityId')
            ->andWhere('et.tag = :t')
            ->andWhere('et.entityClass = :c')
            ->andWhere('et.taggedUntil IS NULL')
            ->setParameter('t', $tag)
            ->setParameter('c', $entityClass)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn(array $r): int => (int) $r['entityId'], $rows);
    }
}
