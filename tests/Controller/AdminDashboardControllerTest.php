<?php

namespace App\Tests\Controller;

use App\Controller\AdminDashboardController;
use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Twig\Environment;

class AdminDashboardControllerTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $userRepository;
    private MockObject $auditLogRepository;
    private MockObject $logger;
    private MockObject $container;
    private MockObject $tokenStorage;
    private MockObject $twig;
    private MockObject $urlGenerator;
    private AdminDashboardController $controller;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogRepository = $this->createMock(AuditLogRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        // Configure container
        $this->container->method('has')->willReturnCallback(function ($id) {
            return in_array($id, ['security.token_storage', 'twig', 'router'], true);
        });

        $this->container->method('get')->willReturnCallback(function ($id) {
            return match ($id) {
                'security.token_storage' => $this->tokenStorage,
                'twig' => $this->twig,
                'router' => $this->urlGenerator,
                default => null,
            };
        });

        $this->controller = new AdminDashboardController(
            $this->entityManager,
            $this->userRepository,
            $this->auditLogRepository,
            $this->logger
        );
        $this->controller->setContainer($this->container);
    }

    public function testIndexRendersAdminDashboard(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->mockAuthenticatedUser($user);

        // Mock user repository
        $this->userRepository->method('count')->willReturnMap([
            [[], 100],
            [['isActive' => true], 85],
            [['isActive' => false], 15],
            [['isVerified' => false], 5],
        ]);

        // Mock database connection for table stats
        $connection = $this->createMock(Connection::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        // Mock database platform
        $platform = $this->createMock(SQLitePlatform::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getDatabase')->willReturn('test_db');
        $connection->method('getParams')->willReturn(['path' => sys_get_temp_dir() . '/test.db']);

        // Mock query results for table counts
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['count' => 10]);
        $connection->method('executeQuery')->willReturn($result);

        // Mock audit log repository for recent activity
        $this->auditLogRepository
            ->method('findBy')
            ->willReturn([]);

        // Mock query builder for active sessions
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(25);
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->auditLogRepository
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        // Mock URL generator
        $this->urlGenerator
            ->method('generate')
            ->willReturn('/en/admin/users');

        // Mock Twig rendering
        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/dashboard.html.twig',
                $this->callback(function ($context) {
                    return isset($context['stats'])
                        && isset($context['recentActivity'])
                        && isset($context['alerts'])
                        && isset($context['currentTenant']);
                })
            )
            ->willReturn('<html>Dashboard</html>');

        $response = $this->controller->index();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('<html>Dashboard</html>', $response->getContent());
    }

    public function testIndexHandlesNullTenant(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn(null);

        $this->mockAuthenticatedUser($user);

        // Mock user repository
        $this->userRepository->method('count')->willReturnMap([
            [[], 50],
            [['isActive' => true], 45],
            [['isActive' => false], 5],
            [['isVerified' => false], 2],
        ]);

        // Mock database connection
        $connection = $this->createMock(Connection::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $platform = $this->createMock(SQLitePlatform::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn(['path' => sys_get_temp_dir() . '/test.db']);

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['count' => 5]);
        $connection->method('executeQuery')->willReturn($result);

        $this->auditLogRepository->method('findBy')->willReturn([]);

        // Mock query builder
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(10);
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->auditLogRepository->method('createQueryBuilder')->willReturn($queryBuilder);
        $this->urlGenerator->method('generate')->willReturn('/en/admin/users');

        $this->twig
            ->method('render')
            ->willReturn('<html>Dashboard</html>');

        $response = $this->controller->index();

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexIncludesInactiveUserAlert(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->mockAuthenticatedUser($user);

        // 20 inactive users should trigger alert
        $this->userRepository->method('count')->willReturnMap([
            [[], 100],
            [['isActive' => true], 80],
            [['isActive' => false], 20],
            [['isVerified' => false], 0],
        ]);

        $this->mockDatabaseOperations();
        $this->auditLogRepository->method('findBy')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(5);
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->auditLogRepository->method('createQueryBuilder')->willReturn($queryBuilder);
        $this->urlGenerator->method('generate')->willReturn('/en/admin/users');

        $this->twig
            ->method('render')
            ->with(
                'admin/dashboard.html.twig',
                $this->callback(function ($context) {
                    // Check that alerts array contains inactive users warning
                    return isset($context['alerts']) && count($context['alerts']) > 0;
                })
            )
            ->willReturn('<html>Dashboard</html>');

        $response = $this->controller->index();
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexIncludesUnverifiedUserAlert(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->mockAuthenticatedUser($user);

        $this->userRepository->method('count')->willReturnMap([
            [[], 100],
            [['isActive' => true], 100],
            [['isActive' => false], 0],
            [['isVerified' => false], 10],
        ]);

        $this->mockDatabaseOperations();
        $this->auditLogRepository->method('findBy')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(5);
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->auditLogRepository->method('createQueryBuilder')->willReturn($queryBuilder);
        $this->urlGenerator->method('generate')->willReturn('/en/admin/users');

        $this->twig
            ->method('render')
            ->with(
                'admin/dashboard.html.twig',
                $this->callback(function ($context) {
                    return isset($context['alerts']) && count($context['alerts']) > 0;
                })
            )
            ->willReturn('<html>Dashboard</html>');

        $response = $this->controller->index();
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexHandlesMySQLDatabaseSize(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->mockAuthenticatedUser($user);

        $this->userRepository->method('count')->willReturnMap([
            [[], 50],
            [['isActive' => true], 50],
            [['isActive' => false], 0],
            [['isVerified' => false], 0],
        ]);

        // Mock MySQL connection
        $connection = $this->createMock(Connection::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $platform = $this->createMock(MySQLPlatform::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getDatabase')->willReturn('test_db');

        // Mock different results for different queries
        $connection->method('executeQuery')->willReturnCallback(function ($sql) {
            $result = $this->createMock(Result::class);

            if (str_contains($sql, 'information_schema')) {
                // Database size query
                $result->method('fetchAssociative')->willReturn(['size_mb' => 512.50]);
            } else {
                // Table count query
                $result->method('fetchAssociative')->willReturn(['count' => 10]);
            }

            return $result;
        });

        $this->auditLogRepository->method('findBy')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(5);
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->auditLogRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->twig
            ->method('render')
            ->with(
                'admin/dashboard.html.twig',
                $this->callback(function ($context) {
                    return isset($context['stats']['database']['size_mb']);
                })
            )
            ->willReturn('<html>Dashboard</html>');

        $response = $this->controller->index();
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexHandlesPostgreSQLDatabaseSize(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->mockAuthenticatedUser($user);

        $this->userRepository->method('count')->willReturnMap([
            [[], 50],
            [['isActive' => true], 50],
            [['isActive' => false], 0],
            [['isVerified' => false], 0],
        ]);

        // Mock PostgreSQL connection
        $connection = $this->createMock(Connection::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $platform = $this->createMock(PostgreSQLPlatform::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getDatabase')->willReturn('test_db');

        $connection->method('executeQuery')->willReturnCallback(function ($sql) {
            $result = $this->createMock(Result::class);

            if (str_contains($sql, 'pg_database_size')) {
                // PostgreSQL size query returns formatted string
                $result->method('fetchAssociative')->willReturn(['size' => '256 MB']);
            } else {
                $result->method('fetchAssociative')->willReturn(['count' => 10]);
            }

            return $result;
        });

        $this->auditLogRepository->method('findBy')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(5);
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->auditLogRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->twig
            ->method('render')
            ->willReturn('<html>Dashboard</html>');

        $response = $this->controller->index();
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexHandlesDatabaseErrors(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->mockAuthenticatedUser($user);

        $this->userRepository->method('count')->willReturn(50);

        // Mock database connection that throws exceptions
        $connection = $this->createMock(Connection::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $platform = $this->createMock(MySQLPlatform::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getDatabase')->willReturn('test_db');
        $connection->method('executeQuery')->willThrowException(new \Exception('Database error'));

        $this->auditLogRepository->method('findBy')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(0);
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->auditLogRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        // Expect logger to be called
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('error');

        $this->twig
            ->method('render')
            ->willReturn('<html>Dashboard</html>');

        $response = $this->controller->index();
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testIndexReturnsRecentActivityLimited(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->mockAuthenticatedUser($user);

        $this->userRepository->method('count')->willReturn(10);
        $this->mockDatabaseOperations();

        // Create 10 audit logs (matching RECENT_ACTIVITY_LIMIT constant)
        $auditLogs = array_fill(0, 10, $this->createMock(AuditLog::class));

        $this->auditLogRepository
            ->expects($this->once())
            ->method('findBy')
            ->with(
                [],
                ['createdAt' => 'DESC'],
                10  // RECENT_ACTIVITY_LIMIT
            )
            ->willReturn($auditLogs);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(5);
        $queryBuilder->method('getQuery')->willReturn($query);

        $this->auditLogRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->twig
            ->method('render')
            ->with(
                'admin/dashboard.html.twig',
                $this->callback(function ($context) {
                    return count($context['recentActivity']) === 10;
                })
            )
            ->willReturn('<html>Dashboard</html>');

        $response = $this->controller->index();
        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Helper method to mock authenticated user
     */
    private function mockAuthenticatedUser(?User $user): void
    {
        if ($user === null) {
            $this->tokenStorage->method('getToken')->willReturn(null);
        } else {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $this->tokenStorage->method('getToken')->willReturn($token);
        }
    }

    /**
     * Helper method to mock standard database operations
     */
    private function mockDatabaseOperations(): void
    {
        $connection = $this->createMock(Connection::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $platform = $this->createMock(SQLitePlatform::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn(['path' => sys_get_temp_dir() . '/test.db']);

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['count' => 5]);
        $connection->method('executeQuery')->willReturn($result);
    }
}
