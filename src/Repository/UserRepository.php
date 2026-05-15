<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * User Repository
 *
 * Repository for querying User entities with support for Azure SSO integration.
 * Implements PasswordUpgraderInterface for automatic password rehashing.
 *
 * Features:
 * - Azure OAuth/SAML user synchronization
 * - User search by name, email, role
 * - Active user filtering
 * - User statistics and analytics
 * - Recently active user tracking
 *
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Find all users with their customRoles eagerly loaded via LEFT JOIN.
     *
     * Replaces bare findAll() on the admin user list, eliminating the N+1
     * pattern where each user.customRoles access triggered an individual
     * SELECT (one per user). Before: 1 + N queries. After: 2 queries.
     *
     * @return User[]
     */
    public function findAllWithRoles(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.customRoles', 'cr')
            ->addSelect('cr')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find user by Azure Object ID
     */
    public function findByAzureObjectId(string $azureObjectId): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.azureObjectId = :azureObjectId')
            ->setParameter('azureObjectId', $azureObjectId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find or create user from Azure data
     */
    public function findOrCreateFromAzure(array $azureData): User
    {
        $user = null;

        // Try to find by Azure Object ID first
        if (isset($azureData['id'])) {
            $user = $this->findByAzureObjectId($azureData['id']);
        }

        // Try to find by email if not found
        if (!$user && isset($azureData['email'])) {
            $user = $this->findOneBy(['email' => $azureData['email']]);
        }

        // Create new user if not found
        if (!$user) {
            $user = new User();
            $user->setIsVerified(true); // Azure users are pre-verified
        }

        return $user;
    }

    /**
     * Find active users
     */
    public function findActiveUsers(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find users by role
     */
    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"' . $role . '"%')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active users carrying the given role string within a tenant.
     *
     * Used by Policy-Wizard step partials (DPO-picker, BCM-Officer-picker,
     * approver-picker) so the dropdowns are scoped to the run's tenant.
     *
     * @param string                          $role   Symfony role string (e.g. 'ROLE_DPO')
     * @param \App\Entity\Tenant|int|null     $tenant Tenant entity, id or null for global
     *
     * @return list<User>
     */
    public function findByRoleInTenant(string $role, mixed $tenant = null): array
    {
        $tenantId = null;
        if ($tenant instanceof \App\Entity\Tenant) {
            $tenantId = $tenant->getId();
        } elseif (is_int($tenant)) {
            $tenantId = $tenant;
        }

        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->andWhere('u.isActive = :active')
            ->setParameter('role', '%"' . $role . '"%')
            ->setParameter('active', true)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC');

        if ($tenantId !== null) {
            $qb->andWhere('u.tenant = :tenantId')
               ->setParameter('tenantId', $tenantId);
        }

        /** @var list<User> $rows */
        $rows = $qb->getQuery()->getResult();
        return $rows;
    }

    /**
     * Find active users in a tenant who can serve as policy approvers.
     *
     * Approver pool = users carrying any of: ROLE_GROUP_CISO, ROLE_DPO,
     * ROLE_ADMIN. Mirrors LifecycleStep approver-per-template selection.
     *
     * @param \App\Entity\Tenant|int|null $tenant Tenant entity, id or null for global
     *
     * @return list<User>
     */
    public function findApproversInTenant(mixed $tenant = null): array
    {
        $approverRoles = ['ROLE_GROUP_CISO', 'ROLE_DPO', 'ROLE_ADMIN'];

        $tenantId = null;
        if ($tenant instanceof \App\Entity\Tenant) {
            $tenantId = $tenant->getId();
        } elseif (is_int($tenant)) {
            $tenantId = $tenant;
        }

        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.isActive = :active')
            ->setParameter('active', true);

        $orParts = [];
        foreach ($approverRoles as $idx => $r) {
            $param = 'role' . $idx;
            $orParts[] = sprintf('u.roles LIKE :%s', $param);
            $qb->setParameter($param, '%"' . $r . '"%');
        }
        $qb->andWhere(implode(' OR ', $orParts));

        if ($tenantId !== null) {
            $qb->andWhere('u.tenant = :tenantId')
               ->setParameter('tenantId', $tenantId);
        }

        $qb->orderBy('u.lastName', 'ASC')
           ->addOrderBy('u.firstName', 'ASC');

        /** @var list<User> $rows */
        $rows = $qb->getQuery()->getResult();
        return $rows;
    }

    /**
     * Find users with custom role
     */
    public function findByCustomRole(string $roleName): array
    {
        return $this->createQueryBuilder('u')
            ->innerJoin('u.customRoles', 'r')
            ->andWhere('r.name = :roleName')
            ->setParameter('roleName', $roleName)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search users by name or email
     */
    public function searchUsers(string $query): array
    {
        $queryBuilder = $this->createQueryBuilder('u');

        return $queryBuilder->andWhere($queryBuilder->expr()->orX(
                $queryBuilder->expr()->like('u.firstName', ':query'),
                $queryBuilder->expr()->like('u.lastName', ':query'),
                $queryBuilder->expr()->like('u.email', ':query')
            ))
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get user statistics
     */
    public function getUserStatistics(): array
    {
        $this->getEntityManager();

        $totalUsers = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $activeUsers = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        $azureUsers = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.authProvider IN (:providers)')
            ->setParameter('providers', ['azure_oauth', 'azure_saml'])
            ->getQuery()
            ->getSingleScalarResult();

        $localUsers = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.authProvider = :provider OR u.authProvider IS NULL')
            ->setParameter('provider', 'local')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $totalUsers,
            'active' => $activeUsers,
            'inactive' => $totalUsers - $activeUsers,
            'azure' => $azureUsers,
            'local' => $localUsers,
        ];
    }

    /**
     * Get recently logged in users
     */
    public function getRecentlyActiveUsers(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.lastLoginAt IS NOT NULL')
            ->orderBy('u.lastLoginAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count active users for a given tenant (used by AlvaHint rules).
     */
    public function countActiveByTenant(\App\Entity\Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('u.isActive = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
