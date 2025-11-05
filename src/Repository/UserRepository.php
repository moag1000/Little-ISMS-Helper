<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
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
        $qb = $this->createQueryBuilder('u');

        return $qb->andWhere($qb->expr()->orX(
                $qb->expr()->like('u.firstName', ':query'),
                $qb->expr()->like('u.lastName', ':query'),
                $qb->expr()->like('u.email', ':query')
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
        $em = $this->getEntityManager();

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
}
