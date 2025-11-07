<?php

namespace App\Repository;

use App\Entity\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Permission Repository
 *
 * Repository for querying Permission entities with category-based organization.
 *
 * @extends ServiceEntityRepository<Permission>
 *
 * @method Permission|null find($id, $lockMode = null, $lockVersion = null)
 * @method Permission|null findOneBy(array $criteria, array $orderBy = null)
 * @method Permission[]    findAll()
 * @method Permission[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    /**
     * Find all permissions for a specific category.
     *
     * @param string $category Permission category (e.g., 'user', 'risk', 'asset', 'control')
     * @return Permission[] Array of permissions sorted by action
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
     * Find all permissions organized by category for role management UI.
     *
     * @return array<string, Permission[]> Associative array with categories as keys
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
     * Find permission by name (e.g., 'user.view', 'risk.edit').
     *
     * @param string $name Permission name in format 'category.action'
     * @return Permission|null Permission entity or null if not found
     */
    public function findByName(string $name): ?Permission
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Get all unique permission categories.
     *
     * @return string[] Array of category names sorted alphabetically
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
     * Get all unique permission actions.
     *
     * @return string[] Array of action names (e.g., 'view', 'create', 'edit', 'delete') sorted alphabetically
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
