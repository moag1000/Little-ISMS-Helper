<?php

namespace App\Repository;

use App\Entity\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Permission>
 */
class PermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    /**
     * Find permissions by category
     */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.category = :category')
            ->setParameter('category', $category)
            ->orderBy('p.action', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all permissions grouped by category
     */
    public function findAllGroupedByCategory(): array
    {
        $permissions = $this->createQueryBuilder('p')
            ->orderBy('p.category', 'ASC')
            ->addOrderBy('p.action', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($permissions as $permission) {
            $category = $permission->getCategory();
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $permission;
        }

        return $grouped;
    }

    /**
     * Find permission by name
     */
    public function findByName(string $name): ?Permission
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Get all unique categories
     */
    public function getCategories(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.category')
            ->orderBy('p.category', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($result, 'category');
    }

    /**
     * Get all unique actions
     */
    public function getActions(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.action')
            ->orderBy('p.action', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($result, 'action');
    }
}
