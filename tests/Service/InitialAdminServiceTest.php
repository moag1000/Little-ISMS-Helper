<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\InitialAdminService;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class InitialAdminServiceTest extends TestCase
{
    private MockObject $userRepository;
    private MockObject $cache;
    private MockObject $logger;
    private InitialAdminService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new InitialAdminService(
            $this->userRepository,
            $this->cache,
            $this->logger
        );
    }

    public function testGetInitialAdminReturnsFirstAdminUser(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        // Cache miss - callback will be executed
        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($admin) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())
                    ->method('expiresAfter')
                    ->with(300);

                return $callback($item);
            });

        // Mock QueryBuilder chain
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($admin);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->userRepository->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        $result = $this->service->getInitialAdmin();

        $this->assertSame($admin, $result);
    }

    public function testGetInitialAdminReturnsFirstSuperAdminUser(): void
    {
        $superAdmin = $this->createUser(1, 'superadmin@example.com', ['ROLE_SUPER_ADMIN']);

        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($superAdmin) {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->with(300);
                return $callback($item);
            });

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($superAdmin);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->userRepository->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($superAdmin);

        $result = $this->service->getInitialAdmin();

        $this->assertSame($superAdmin, $result);
    }

    public function testGetInitialAdminReturnsNullWhenNoAdminExists(): void
    {
        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->with(300);
                return $callback($item);
            });

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->userRepository->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $result = $this->service->getInitialAdmin();

        $this->assertNull($result);
    }

    public function testGetInitialAdminReturnsNullWhenCachedIdIsNull(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $result = $this->service->getInitialAdmin();

        $this->assertNull($result);
    }

    public function testGetInitialAdminUsesCachedValue(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        // Cache hit - return cached ID directly
        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($admin);

        // createQueryBuilder should NOT be called when cache hits
        $this->userRepository->expects($this->never())
            ->method('createQueryBuilder');

        $result = $this->service->getInitialAdmin();

        $this->assertSame($admin, $result);
    }

    public function testGetInitialAdminHandlesExceptionAndReturnsNull(): void
    {
        $this->cache->method('get')
            ->willThrowException(new \RuntimeException('Cache error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to retrieve initial admin',
                $this->callback(function ($context) {
                    return isset($context['error']) && $context['error'] === 'Cache error';
                })
            );

        $result = $this->service->getInitialAdmin();

        $this->assertNull($result);
    }

    public function testGetInitialAdminSetsCorrectCacheTtl(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($admin) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())
                    ->method('expiresAfter')
                    ->with(300); // 5 minutes

                return $callback($item);
            });

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($admin);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->userRepository->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $this->userRepository->method('find')
            ->willReturn($admin);

        $this->service->getInitialAdmin();
    }

    public function testIsInitialAdminReturnsTrueForInitialAdmin(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        $result = $this->service->isInitialAdmin($admin);

        $this->assertTrue($result);
    }

    public function testIsInitialAdminReturnsFalseForDifferentUser(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);
        $otherUser = $this->createUser(2, 'user@example.com', ['ROLE_USER']);

        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        $result = $this->service->isInitialAdmin($otherUser);

        $this->assertFalse($result);
    }

    public function testIsInitialAdminReturnsFalseWhenNoInitialAdminExists(): void
    {
        $user = $this->createUser(1, 'user@example.com', ['ROLE_USER']);

        $this->cache->method('get')
            ->willReturn(null);

        $result = $this->service->isInitialAdmin($user);

        $this->assertFalse($result);
    }

    public function testIsInitialAdminReturnsFalseForUnpersistedUser(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);
        $newUser = $this->createUser(null, 'new@example.com', ['ROLE_USER']);

        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        $result = $this->service->isInitialAdmin($newUser);

        $this->assertFalse($result);
    }

    public function testClearCacheDeletesCacheEntry(): void
    {
        $this->cache->expects($this->once())
            ->method('delete')
            ->with('initial_admin_id');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Initial admin cache cleared');

        $this->service->clearCache();
    }

    public function testClearCacheHandlesExceptionGracefully(): void
    {
        $this->cache->method('delete')
            ->willThrowException(new \Exception('Cache delete failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to clear initial admin cache',
                $this->callback(function ($context) {
                    return isset($context['error']) && $context['error'] === 'Cache delete failed';
                })
            );

        // Should not throw exception
        $this->service->clearCache();
    }

    public function testValidateOperationAllowsOperationOnNonInitialAdmin(): void
    {
        $user = $this->createUser(2, 'user@example.com', ['ROLE_USER']);
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        // Should not throw exception
        $this->service->validateOperation($user, 'delete');
        $this->service->validateOperation($user, 'deactivate');
        $this->service->validateOperation($user, 'remove_admin_role');

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testValidateOperationThrowsExceptionForDeleteOnInitialAdmin(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Attempted operation on initial admin blocked',
                $this->callback(function ($context) {
                    return $context['user_id'] === 1
                        && $context['user_email'] === 'admin@example.com'
                        && $context['operation'] === 'delete';
                })
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete the initial setup administrator. This user is required for system security.');

        $this->service->validateOperation($admin, 'delete');
    }

    public function testValidateOperationThrowsExceptionForDeactivateOnInitialAdmin(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Attempted operation on initial admin blocked',
                $this->callback(function ($context) {
                    return $context['operation'] === 'deactivate';
                })
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot deactivate the initial setup administrator. This user must remain active for system recovery.');

        $this->service->validateOperation($admin, 'deactivate');
    }

    public function testValidateOperationThrowsExceptionForRemoveAdminRoleOnInitialAdmin(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Attempted operation on initial admin blocked',
                $this->callback(function ($context) {
                    return $context['operation'] === 'remove_admin_role';
                })
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove ROLE_ADMIN from the initial setup administrator. At least one admin must exist.');

        $this->service->validateOperation($admin, 'remove_admin_role');
    }

    public function testValidateOperationThrowsExceptionForUnknownOperation(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Attempted operation on initial admin blocked',
                $this->callback(function ($context) {
                    return $context['operation'] === 'unknown_operation';
                })
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Operation not allowed on initial setup administrator.');

        $this->service->validateOperation($admin, 'unknown_operation');
    }

    public function testGetInitialAdminUsesCorrectCacheKey(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        $this->cache->expects($this->once())
            ->method('get')
            ->with('initial_admin_id', $this->isCallable())
            ->willReturn(1);

        $this->userRepository->method('find')
            ->willReturn($admin);

        $this->service->getInitialAdmin();
    }

    public function testGetInitialAdminQueriesForLowestIdAdmin(): void
    {
        $admin = $this->createUser(5, 'admin@example.com', ['ROLE_ADMIN']);

        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($admin) {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->with(300);
                return $callback($item);
            });

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($admin);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('orderBy')
            ->with('u.id', 'ASC')
            ->willReturnSelf();

        $queryBuilder->expects($this->once())
            ->method('setMaxResults')
            ->with(1)
            ->willReturnSelf();

        $queryBuilder->method('getQuery')->willReturn($query);

        $this->userRepository->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $this->userRepository->method('find')
            ->with(5)
            ->willReturn($admin);

        $result = $this->service->getInitialAdmin();

        $this->assertSame($admin, $result);
    }

    public function testIsInitialAdminHandlesNullFromGetInitialAdmin(): void
    {
        $user = $this->createUser(1, 'user@example.com', ['ROLE_USER']);

        // Simulate getInitialAdmin returning null
        $this->cache->method('get')
            ->willThrowException(new \Exception('Database error'));

        $this->logger->method('error');

        $result = $this->service->isInitialAdmin($user);

        $this->assertFalse($result);
    }

    public function testMultipleCallsUseCacheEffectively(): void
    {
        $admin = $this->createUser(1, 'admin@example.com', ['ROLE_ADMIN']);

        // Cache should be called multiple times but only compute once
        $this->cache->method('get')
            ->willReturn(1);

        $this->userRepository->method('find')
            ->with(1)
            ->willReturn($admin);

        // Multiple calls
        $result1 = $this->service->getInitialAdmin();
        $result2 = $this->service->getInitialAdmin();
        $result3 = $this->service->getInitialAdmin();

        $this->assertSame($admin, $result1);
        $this->assertSame($admin, $result2);
        $this->assertSame($admin, $result3);
    }

    private function createUser(?int $id, string $email, array $roles): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getEmail')->willReturn($email);
        $user->method('getRoles')->willReturn($roles);
        return $user;
    }
}
