<?php

declare(strict_types=1);

namespace App\Tests\Service\Setup;

use App\Service\Setup\SetupConsoleRunner;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

#[AllowMockObjectsWithoutExpectations]
final class SetupConsoleRunnerTest extends TestCase
{
    private MockObject $kernel;
    private MockObject $entityManager;
    private SetupConsoleRunner $runner;

    protected function setUp(): void
    {
        $this->kernel        = $this->createMock(KernelInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->runner        = new SetupConsoleRunner($this->kernel, $this->entityManager);
    }

    // ────────────────────────────────────────────────────────────────────────
    // createAdminUser
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function create_admin_user_returns_failure_when_kernel_throws(): void
    {
        // Application::__construct calls kernel->getBundles() which throws here
        $this->kernel->method('getBundles')->willThrowException(new \RuntimeException('No container'));

        $result = $this->runner->createAdminUser([
            'email'     => 'admin@example.com',
            'password'  => 'secret',
            'firstName' => 'Admin',
            'lastName'  => 'User',
        ]);

        self::assertFalse($result['success']);
        self::assertNotEmpty($result['message']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // cleanupDatabaseConnection
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function cleanup_database_connection_clears_entity_manager_when_no_active_transaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->expects(self::once())->method('clear');

        $this->runner->cleanupDatabaseConnection();
    }

    #[Test]
    public function cleanup_database_connection_rolls_back_open_transaction(): void
    {
        $connection = $this->createMock(Connection::class);
        // First call → transaction active; second call (after rollback) → not active
        $connection->method('isTransactionActive')->willReturnOnConsecutiveCalls(true, false);
        $connection->expects(self::once())->method('rollBack');

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('clear');

        $this->runner->cleanupDatabaseConnection();
        self::assertTrue(true); // reached without exception
    }

    #[Test]
    public function cleanup_database_connection_handles_connection_exception_gracefully(): void
    {
        $this->entityManager->method('getConnection')->willThrowException(new \RuntimeException('Connection lost'));

        // Should not throw
        $this->runner->cleanupDatabaseConnection();
        self::assertTrue(true);
    }
}
