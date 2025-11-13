<?php

namespace App\Repository;

use App\Entity\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Role Repository
 *
 * Repository for querying Role entities with system/custom role differentiation.
 *
 * @extends ServiceEntityRepository<Role>
 *
 * @method Role|null find($id, $lockMode = null, $lockVersion = null)
 * @method Role|null findOneBy(array $criteria, array $orderBy = null)
 * @method Role[]    findAll()
 * @method Role[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    /**
     * Find all custom (non-system) roles that can be modified.
     *
     * @return Role[] Array of custom roles sorted by name
     */
    public function findCustomRoles(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.isSystemRole = :isSystem')
            ->setParameter('isSystem', false)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find protected system roles (ROLE_USER, ROLE_ADMIN).
     *
     * @return Role[] Array of system roles sorted by name
     */
    public function findSystemRoles(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.isSystemRole = :isSystem')
            ->setParameter('isSystem', true)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find role by name.
     *
     * @param string $name Role name (e.g., 'ISO Manager', 'Auditor')
     * @return Role|null Role entity or null if not found
     */
    public function findByName(string $name): ?Role
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Find role with eager-loaded permissions for permission management.
     *
     * @param int $id Role identifier
     * @return Role|null Role entity with permissions or null if not found
     */
    public function findWithPermissions(int $id): ?Role
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.permissions', 'p')
            ->addSelect('p')
            ->andWhere('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all roles with eager-loaded users and permissions for role overview.
     *
     * @return Role[] Array of Role entities with users and permissions loaded
     */
    public function getRolesWithUserCount(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.users', 'u')
            ->leftJoin('r.permissions', 'p')
            ->addSelect('u')
            ->addSelect('p')
            ->groupBy('r.id')
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
