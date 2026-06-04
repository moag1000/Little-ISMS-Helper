<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AnswerLibraryEntry;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * F44 — Answer Library repository.
 *
 * All finders are tenant-scoped — no unguarded findAll() is exposed.
 *
 * @extends ServiceEntityRepository<AnswerLibraryEntry>
 */
class AnswerLibraryEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnswerLibraryEntry::class);
    }

    /**
     * Return all entries for a tenant, ordered by most-used first.
     *
     * @return AnswerLibraryEntry[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.useCount', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Return entries for a tenant filtered by category, ordered by most-used.
     *
     * @return AnswerLibraryEntry[]
     */
    public function findByTenantAndCategory(Tenant $tenant, string $category): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.category = :category')
            ->setParameter('tenant', $tenant)
            ->setParameter('category', $category)
            ->orderBy('a.useCount', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Full-text keyword search across question + answer text, scoped to tenant.
     * Optional category filter narrows the result set further.
     *
     * @return AnswerLibraryEntry[]
     */
    public function searchByKeyword(
        Tenant  $tenant,
        string  $keyword,
        ?string $category = null,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('(a.question LIKE :kw OR a.answer LIKE :kw)')
            ->setParameter('tenant', $tenant)
            ->setParameter('kw', '%' . $keyword . '%')
            ->orderBy('a.useCount', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC');

        if ($category !== null && $category !== '') {
            $qb->andWhere('a.category = :category')
               ->setParameter('category', $category);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Return the N most-used entries for a tenant (for ROI widget / quick-pick).
     *
     * @return AnswerLibraryEntry[]
     */
    public function findTopByTenant(Tenant $tenant, int $limit = 5): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.useCount > 0')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.useCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total entries for a tenant (used by KPI cards).
     */
    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count total reuse events (sum of useCount) for a tenant.
     */
    public function sumUseCountByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.useCount), 0)')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
