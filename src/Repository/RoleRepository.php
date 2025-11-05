<?php

namespace App\Repository;

use App\Entity\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    /**
     * Find all non-system roles
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
     * Find all system roles
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
     * Find role by name
     */
    public function findByName(string $name): ?Role
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Get role with permissions
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
     * Get roles with user count
     */
    public function getRolesWithUserCount(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'COUNT(u.id) as userCount')
            ->leftJoin('r.users', 'u')
            ->groupBy('r.id')
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
