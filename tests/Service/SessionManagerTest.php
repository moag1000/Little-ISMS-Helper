<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use App\Service\AuditLogger;
use App\Service\SessionManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class SessionManagerTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $sessionRepository;
    private MockObject $requestStack;
    private MockObject $auditLogger;
    private MockObject $logger;
    private SessionManager $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->sessionRepository = $this->createMock(UserSessionRepository::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new SessionManager(
            $this->entityManager,
            $this->sessionRepository,
            $this->requestStack,
            $this->auditLogger,
            $this->logger
        );
    }

    public function testCreateSessionWhenTableNotAvailable(): void
    {
        $this->mockTableNotExists();

        $user = $this->createMockUser(1, 'test@example.com');
        $result = $this->service->createSession($user, 'session123');

        $this->assertNull($result);
    }

    public function testCreateSessionSuccessfully(): void
    {
        $this->mockTableExists();

        $user = $this->createMockUser(1, 'test@example.com');
        $request = $this->createMockRequest('192.168.1.1', 'Mozilla/5.0');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->sessionRepository->method('countActiveByUser')->willReturn(0);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->createSession($user, 'session123');

        $this->assertInstanceOf(UserSession::class, $result);
    }

    public function testCreateSessionEnforcesSessionLimit(): void
    {
        $this->mockTableExists();

        $user = $this->createMockUser(1, 'test@example.com');
        $request = $this->createMockRequest('192.168.1.1', 'Mozilla/5.0');
        $oldestSession = $this->createMockSession('old_session_id');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->sessionRepository->method('countActiveByUser')->willReturn(5); // At limit

        // Create array of 5 sessions where the 5th (index 4) is the oldest
        $sessions = [
            $this->createMockSession('s1'),
            $this->createMockSession('s2'),
            $this->createMockSession('s3'),
            $this->createMockSession('s4'),
            $oldestSession,
        ];

        $this->sessionRepository->method('findActiveByUser')
            ->willReturn($sessions);

        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        // The service should call terminateSession internally
        $result = $this->service->createSession($user, 'new_session');

        $this->assertInstanceOf(UserSession::class, $result);
    }

    public function testCreateSessionHandlesException(): void
    {
        $this->mockTableExists();

        $user = $this->createMockUser(1, 'test@example.com');
        $request = $this->createMockRequest('192.168.1.1', 'Mozilla/5.0');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->sessionRepository->method('countActiveByUser')->willReturn(0);
        $this->entityManager->method('persist')->willThrowException(new \Exception('DB error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to create session record');

        $result = $this->service->createSession($user, 'session123');

        $this->assertNull($result);
    }

    public function testUpdateActivityWhenTableNotAvailable(): void
    {
        $this->mockTableNotExists();

        // Should not throw exception
        $this->service->updateActivity('session123');

        $this->assertTrue(true);
    }

    public function testUpdateActivitySuccessfully(): void
    {
        $this->mockTableExists();

        $session = $this->createMockSession('session123');
        $session->method('isActive')->willReturn(true);
        $session->expects($this->once())->method('updateActivity');

        $this->sessionRepository->method('findBySessionId')->willReturn($session);
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->updateActivity('session123');
    }

    public function testUpdateActivityDoesNotUpdateInactiveSession(): void
    {
        $this->mockTableExists();

        $session = $this->createMockSession('session123');
        $session->method('isActive')->willReturn(false);
        $session->expects($this->never())->method('updateActivity');

        $this->sessionRepository->method('findBySessionId')->willReturn($session);

        $this->service->updateActivity('session123');
    }

    public function testEndSessionSuccessfully(): void
    {
        $this->mockTableExists();

        $user = $this->createMockUser(1, 'test@example.com');
        $session = $this->createMockSession('session123');
        $session->method('isActive')->willReturn(true);
        $session->method('getUser')->willReturn($user);
        $session->method('getId')->willReturn(1);
        $session->method('getFormattedDuration')->willReturn('1 hour 23 minutes');
        $session->method('getDuration')->willReturn(4980);
        $session->expects($this->once())->method('terminate')->with('logout');

        $this->sessionRepository->method('findBySessionId')->willReturn($session);
        $this->entityManager->expects($this->once())->method('flush');

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                'session_ended',
                'UserSession',
                1,
                null,
                $this->callback(function ($data) {
                    return $data['reason'] === 'logout' && $data['duration_seconds'] === 4980;
                })
            );

        $this->service->endSession('session123', 'logout');
    }

    public function testTerminateSessionSuccessfully(): void
    {
        $this->mockTableExists();

        $user = $this->createMockUser(1, 'admin@example.com');
        $session = $this->createMockSession('session123');
        $session->method('isActive')->willReturn(true);
        $session->method('getUser')->willReturn($user);
        $session->method('getId')->willReturn(1);
        $session->expects($this->once())->method('terminate')->with('forced', 'admin@example.com');

        $this->sessionRepository->method('findBySessionId')->willReturn($session);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->terminateSession('session123', 'forced', 'admin@example.com');

        $this->assertTrue($result);
    }

    public function testTerminateSessionReturnsFalseWhenNotFound(): void
    {
        $this->mockTableExists();

        $this->sessionRepository->method('findBySessionId')->willReturn(null);

        $result = $this->service->terminateSession('nonexistent', 'forced');

        $this->assertFalse($result);
    }

    public function testTerminateSessionReturnsFalseWhenInactive(): void
    {
        $this->mockTableExists();

        $session = $this->createMockSession('session123');
        $session->method('isActive')->willReturn(false);

        $this->sessionRepository->method('findBySessionId')->willReturn($session);

        $result = $this->service->terminateSession('session123', 'forced');

        $this->assertFalse($result);
    }

    public function testTerminateUserSessionsSuccessfully(): void
    {
        $this->mockTableExists();

        $user = $this->createMockUser(1, 'test@example.com');

        $this->sessionRepository->method('terminateUserSessions')
            ->with($user, 'forced', 'admin@example.com')
            ->willReturn(3);

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                'all_sessions_terminated',
                'User',
                1,
                null,
                $this->callback(function ($data) {
                    return $data['terminated_count'] === 3 && $data['terminated_by'] === 'admin@example.com';
                })
            );

        $count = $this->service->terminateUserSessions($user, 'admin@example.com');

        $this->assertEquals(3, $count);
    }

    public function testTerminateUserSessionsReturnsZeroWhenTableNotAvailable(): void
    {
        $this->mockTableNotExists();

        $user = $this->createMockUser(1, 'test@example.com');
        $count = $this->service->terminateUserSessions($user);

        $this->assertEquals(0, $count);
    }

    public function testIsSessionValidReturnsTrueForActiveSession(): void
    {
        $this->mockTableExists();

        $session = $this->createMockSession('session123');
        $session->method('isActive')->willReturn(true);
        $session->method('isExpired')->willReturn(false);

        $this->sessionRepository->method('findBySessionId')->willReturn($session);

        $result = $this->service->isSessionValid('session123');

        $this->assertTrue($result);
    }

    public function testIsSessionValidReturnsFalseForInactiveSession(): void
    {
        $this->mockTableExists();

        $session = $this->createMockSession('session123');
        $session->method('isActive')->willReturn(false);

        $this->sessionRepository->method('findBySessionId')->willReturn($session);

        $result = $this->service->isSessionValid('session123');

        $this->assertFalse($result);
    }

    public function testIsSessionValidReturnsFalseForExpiredSession(): void
    {
        $this->mockTableExists();

        $user = $this->createMockUser(1, 'test@example.com');
        $session = $this->createMockSession('session123');
        $session->method('isActive')->willReturn(true);
        $session->method('isExpired')->willReturn(true);
        $session->method('getUser')->willReturn($user);
        $session->method('getId')->willReturn(1);
        $session->method('getFormattedDuration')->willReturn('1 hour');
        $session->method('getDuration')->willReturn(3600);
        $session->expects($this->once())->method('terminate')->with('timeout');

        $this->sessionRepository->method('findBySessionId')->willReturn($session);

        $result = $this->service->isSessionValid('session123');

        $this->assertFalse($result);
    }

    public function testIsSessionValidReturnsFalseWhenNotFound(): void
    {
        $this->mockTableExists();

        $this->sessionRepository->method('findBySessionId')->willReturn(null);

        $result = $this->service->isSessionValid('nonexistent');

        $this->assertFalse($result);
    }

    public function testGetUserActiveSessionsReturnsArray(): void
    {
        $this->mockTableExists();

        $user = $this->createMockUser(1, 'test@example.com');
        $sessions = [
            $this->createMockSession('session1'),
            $this->createMockSession('session2'),
        ];

        $this->sessionRepository->method('findActiveByUser')->willReturn($sessions);

        $result = $this->service->getUserActiveSessions($user);

        $this->assertCount(2, $result);
    }

    public function testGetUserActiveSessionsReturnsEmptyWhenTableNotAvailable(): void
    {
        $this->mockTableNotExists();

        $user = $this->createMockUser(1, 'test@example.com');
        $result = $this->service->getUserActiveSessions($user);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAllActiveSessionsReturnsArray(): void
    {
        $this->mockTableExists();

        $sessions = [
            $this->createMockSession('session1'),
            $this->createMockSession('session2'),
            $this->createMockSession('session3'),
        ];

        $this->sessionRepository->method('getActiveSessions')->willReturn($sessions);

        $result = $this->service->getAllActiveSessions();

        $this->assertCount(3, $result);
    }

    public function testGetAllActiveSessionsWithFilters(): void
    {
        $this->mockTableExists();

        $sessions = [$this->createMockSession('session1')];

        $this->sessionRepository->expects($this->once())
            ->method('getActiveSessions')
            ->with(10, 'test@example.com')
            ->willReturn($sessions);

        $result = $this->service->getAllActiveSessions('test@example.com', 10);

        $this->assertCount(1, $result);
    }

    public function testGetStatisticsReturnsData(): void
    {
        $this->mockTableExists();

        $stats = [
            'total_sessions' => 100,
            'active_sessions' => 25,
            'inactive_sessions' => 75,
        ];

        $this->sessionRepository->method('getStatistics')->willReturn($stats);

        $result = $this->service->getStatistics();

        $this->assertEquals(100, $result['total_sessions']);
        $this->assertEquals(25, $result['active_sessions']);
        $this->assertEquals(75, $result['inactive_sessions']);
    }

    public function testGetStatisticsReturnsZerosWhenTableNotAvailable(): void
    {
        $this->mockTableNotExists();

        $result = $this->service->getStatistics();

        $this->assertEquals(0, $result['total_sessions']);
        $this->assertEquals(0, $result['active_sessions']);
        $this->assertEquals(0, $result['inactive_sessions']);
    }

    public function testCleanupExpiredSessionsReturnsCount(): void
    {
        $this->mockTableExists();

        $this->sessionRepository->method('cleanupExpiredSessions')->willReturn(15);

        $count = $this->service->cleanupExpiredSessions();

        $this->assertEquals(15, $count);
    }

    public function testCleanupExpiredSessionsReturnsZeroWhenTableNotAvailable(): void
    {
        $this->mockTableNotExists();

        $count = $this->service->cleanupExpiredSessions();

        $this->assertEquals(0, $count);
    }

    public function testGetMaxConcurrentSessionsReturnsCorrectValue(): void
    {
        $max = $this->service->getMaxConcurrentSessions();

        $this->assertEquals(5, $max);
    }

    public function testGetSessionLifetimeReturnsCorrectValue(): void
    {
        $lifetime = $this->service->getSessionLifetime();

        $this->assertEquals(3600, $lifetime);
    }

    public function testCreateSessionCachesTableExistence(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects($this->once()) // Should only be called once
            ->method('listTableNames')
            ->willReturn(['user_sessions']);

        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $user = $this->createMockUser(1, 'test@example.com');
        $request = $this->createMockRequest('192.168.1.1', 'Mozilla/5.0');

        $this->requestStack->method('getCurrentRequest')->willReturn($request);
        $this->sessionRepository->method('countActiveByUser')->willReturn(0);

        // Call twice - should only check table existence once
        $this->service->createSession($user, 'session1');
        $this->service->createSession($user, 'session2');
    }

    private function mockTableExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['user_sessions']);

        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $this->entityManager->method('getConnection')->willReturn($connection);
    }

    private function mockTableNotExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn([]);

        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $this->entityManager->method('getConnection')->willReturn($connection);
    }

    private function createMockUser(int $id, string $email): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getEmail')->willReturn($email);
        return $user;
    }

    private function createMockSession(string $sessionId): MockObject
    {
        $session = $this->createMock(UserSession::class);
        $session->method('getSessionId')->willReturn($sessionId);
        return $session;
    }

    private function createMockRequest(string $ip, string $userAgent): MockObject
    {
        $request = $this->createMock(Request::class);
        $request->method('getClientIp')->willReturn($ip);
        $request->headers = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $request->headers->method('get')->with('User-Agent')->willReturn($userAgent);
        return $request;
    }
}
