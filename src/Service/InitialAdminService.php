<?php

namespace App\Service;

use Exception;
use RuntimeException;
use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service to identify and protect the initial setup administrator.
 *
 * Security Model:
 * - The initial admin is the first user created with ROLE_ADMIN
 * - This user cannot be deleted or deactivated
 * - ROLE_ADMIN cannot be removed from this user
 * - Uses in-memory caching to minimize database queries
 *
 * Performance:
 * - Cached for 5 minutes to reduce DB load
 * - Cache key: 'initial_admin_id'
 */
class InitialAdminService
{
    private const string CACHE_KEY = 'initial_admin_id';
    private const int CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get the initial setup admin user.
     *
     * @return User|null The initial admin or null if not found
     */
    public function getInitialAdmin(): ?User
    {
        try {
            $adminId = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?int {
                $item->expiresAfter(self::CACHE_TTL);

                // Find the first admin user (lowest ID with ROLE_ADMIN or ROLE_SUPER_ADMIN)
                $admin = $this->userRepository->createQueryBuilder('u')
                    ->where('u.roles LIKE :role_admin OR u.roles LIKE :role_super_admin')
                    ->setParameter('role_admin', '%"ROLE_ADMIN"%')
                    ->setParameter('role_super_admin', '%"ROLE_SUPER_ADMIN"%')
                    ->orderBy('u.id', 'ASC')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                return $admin?->getId();
            });

            if ($adminId === null) {
                return null;
            }

            return $this->userRepository->find($adminId);
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve initial admin', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if a user is the initial setup admin.
     *
     * @param User $user The user to check
     * @return bool True if the user is the initial admin
     */
    public function isInitialAdmin(User $user): bool
    {
        $initialAdmin = $this->getInitialAdmin();

        if (!$initialAdmin instanceof User) {
            return false;
        }

        // User must have an ID to be compared (not a new, unpersisted entity)
        if ($user->getId() === null) {
            return false;
        }

        return $user->getId() === $initialAdmin->getId();
    }

    /**
     * Clear the initial admin cache.
     * Useful after user operations that might affect admin status.
     */
    public function clearCache(): void
    {
        try {
            $this->cache->delete(self::CACHE_KEY);
            $this->logger->info('Initial admin cache cleared');
        } catch (Exception $e) {
            $this->logger->error('Failed to clear initial admin cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate that an operation on a user is allowed.
     * Throws exception if the operation would compromise system security.
     *
     * @param User $user The user being operated on
     * @param string $operation The operation being performed (delete, deactivate, remove_admin_role)
     * @throws RuntimeException If operation is not allowed
     */
    public function validateOperation(User $user, string $operation): void
    {
        if (!$this->isInitialAdmin($user)) {
            return;
        }

        $message = match ($operation) {
            'delete' => 'Cannot delete the initial setup administrator. This user is required for system security.',
            'deactivate' => 'Cannot deactivate the initial setup administrator. This user must remain active for system recovery.',
            'remove_admin_role' => 'Cannot remove ROLE_ADMIN from the initial setup administrator. At least one admin must exist.',
            default => 'Operation not allowed on initial setup administrator.',
        };

        $this->logger->warning('Attempted operation on initial admin blocked', [
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'operation' => $operation,
        ]);

        throw new RuntimeException($message);
    }
}
