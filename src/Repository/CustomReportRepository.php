<?php

namespace App\Repository;

use App\Entity\CustomReport;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Custom Report Repository
 *
 * Phase 7C: Repository for custom report configurations
 *
 * @extends ServiceEntityRepository<CustomReport>
 *
 * @method CustomReport|null find($id, $lockMode = null, $lockVersion = null)
 * @method CustomReport|null findOneBy(array $criteria, array $orderBy = null)
 * @method CustomReport[]    findAll()
 * @method CustomReport[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CustomReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomReport::class);
    }

    public function save(CustomReport $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CustomReport $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find reports accessible by a user (owned or shared with)
     *
     * @return CustomReport[]
     */
    public function findAccessibleByUser(User $user, int $tenantId): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.tenantId = :tenantId')
            ->andWhere('r.isTemplate = :notTemplate')
            ->andWhere('r.owner = :user OR (r.isShared = :shared AND JSON_CONTAINS(r.sharedWith, :userId) = 1)')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('notTemplate', false)
            ->setParameter('user', $user)
            ->setParameter('shared', true)
            ->setParameter('userId', (string) $user->getId())
            ->orderBy('r.updatedAt', 'DESC')
            ->addOrderBy('r.createdAt', 'DESC');

        // Fallback for databases that don't support JSON_CONTAINS
        try {
            return $qb->getQuery()->getResult();
        } catch (\Exception $e) {
            // Simple fallback: just get owned reports
            return $this->findBy(
                ['owner' => $user, 'tenantId' => $tenantId, 'isTemplate' => false],
                ['updatedAt' => 'DESC', 'createdAt' => 'DESC']
            );
        }
    }

    /**
     * Find reports owned by a user
     *
     * @return CustomReport[]
     */
    public function findOwnedByUser(User $user, int $tenantId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.owner = :user')
            ->andWhere('r.tenantId = :tenantId')
            ->andWhere('r.isTemplate = :notTemplate')
            ->setParameter('user', $user)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('notTemplate', false)
            ->orderBy('r.updatedAt', 'DESC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find favorite reports for a user
     *
     * @return CustomReport[]
     */
    public function findFavoritesByUser(User $user, int $tenantId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.owner = :user')
            ->andWhere('r.tenantId = :tenantId')
            ->andWhere('r.isFavorite = :favorite')
            ->andWhere('r.isTemplate = :notTemplate')
            ->setParameter('user', $user)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('favorite', true)
            ->setParameter('notTemplate', false)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find available templates (global and shared)
     *
     * @return CustomReport[]
     */
    public function findAvailableTemplates(int $tenantId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenantId = :tenantId')
            ->andWhere('r.isTemplate = :isTemplate')
            ->andWhere('r.isShared = :isShared')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isTemplate', true)
            ->setParameter('isShared', true)
            ->orderBy('r.usageCount', 'DESC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find templates by category
     *
     * @return CustomReport[]
     */
    public function findTemplatesByCategory(string $category, int $tenantId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenantId = :tenantId')
            ->andWhere('r.isTemplate = :isTemplate')
            ->andWhere('r.isShared = :isShared')
            ->andWhere('r.category = :category')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isTemplate', true)
            ->setParameter('isShared', true)
            ->setParameter('category', $category)
            ->orderBy('r.usageCount', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find reports by category for a user
     *
     * @return CustomReport[]
     */
    public function findByCategory(string $category, User $user, int $tenantId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.owner = :user')
            ->andWhere('r.tenantId = :tenantId')
            ->andWhere('r.category = :category')
            ->andWhere('r.isTemplate = :notTemplate')
            ->setParameter('user', $user)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('category', $category)
            ->setParameter('notTemplate', false)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find most used reports (for suggestions)
     *
     * @return CustomReport[]
     */
    public function findMostUsed(int $tenantId, int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenantId = :tenantId')
            ->andWhere('r.isTemplate = :isTemplate')
            ->andWhere('r.isShared = :isShared')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('isTemplate', true)
            ->setParameter('isShared', true)
            ->orderBy('r.usageCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recently used reports by user
     *
     * @return CustomReport[]
     */
    public function findRecentlyUsed(User $user, int $tenantId, int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.owner = :user')
            ->andWhere('r.tenantId = :tenantId')
            ->andWhere('r.lastUsedAt IS NOT NULL')
            ->andWhere('r.isTemplate = :notTemplate')
            ->setParameter('user', $user)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('notTemplate', false)
            ->orderBy('r.lastUsedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count reports by user
     */
    public function countByUser(User $user, int $tenantId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.owner = :user')
            ->andWhere('r.tenantId = :tenantId')
            ->andWhere('r.isTemplate = :notTemplate')
            ->setParameter('user', $user)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('notTemplate', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Search reports by name or description
     *
     * @return CustomReport[]
     */
    public function search(string $query, User $user, int $tenantId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.owner = :user')
            ->andWhere('r.tenantId = :tenantId')
            ->andWhere('r.isTemplate = :notTemplate')
            ->andWhere('r.name LIKE :query OR r.description LIKE :query')
            ->setParameter('user', $user)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('notTemplate', false)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
