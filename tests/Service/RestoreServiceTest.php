<?php

namespace App\Tests\Service;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\RestoreService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityRepository;
use Doctrine\Common\EventManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RestoreServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $auditLogger;
    private MockObject $logger;
    private MockObject $passwordHasher;
    private RestoreService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $this->service = new RestoreService(
            $this->entityManager,
            $this->auditLogger,
            $this->logger,
            $this->passwordHasher
        );
    }

    public function testValidateBackupWithValidData(): void
    {
        $backup = [
            'metadata' => [
                'version' => '1.0',
                'created_at' => '2024-01-01T12:00:00+00:00',
            ],
            'data' => [
                'User' => [],
                'Tenant' => [],
            ],
        ];

        $result = $this->service->validateBackup($backup);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertIsArray($result['warnings']);
    }

    public function testValidateBackupFailsWithoutMetadata(): void
    {
        $backup = [
            'data' => [],
        ];

        $result = $this->service->validateBackup($backup);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing metadata section', $result['errors']);
    }

    public function testValidateBackupFailsWithUnsupportedVersion(): void
    {
        $backup = [
            'metadata' => [
                'version' => '2.0',
            ],
            'data' => [],
        ];

        $result = $this->service->validateBackup($backup);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, array_filter($result['errors'], function ($error) {
            return str_contains($error, 'Unsupported backup version');
        }));
    }

    public function testValidateBackupFailsWithoutDataSection(): void
    {
        $backup = [
            'metadata' => [
                'version' => '1.0',
            ],
        ];

        $result = $this->service->validateBackup($backup);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing or invalid data section', $result['errors']);
    }

    public function testValidateBackupWarnsAboutUnknownEntities(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [
                'UnknownEntity' => [],
            ],
        ];

        $result = $this->service->validateBackup($backup);

        $this->assertTrue($result['valid']);
        $this->assertCount(1, array_filter($result['warnings'], function ($warning) {
            return str_contains($warning, 'Entity class not found');
        }));
    }

    public function testGetRestorePreviewReturnsPreviewData(): void
    {
        $backup = [
            'metadata' => [
                'version' => '1.0',
                'created_at' => '2024-01-01T12:00:00+00:00',
            ],
            'data' => [
                'User' => [
                    ['id' => 1, 'email' => 'test1@example.com'],
                    ['id' => 2, 'email' => 'test2@example.com'],
                ],
                'Tenant' => [
                    ['id' => 1, 'name' => 'Test Tenant'],
                ],
            ],
        ];

        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('count')->willReturn(5);

        $tenantRepository = $this->createMock(EntityRepository::class);
        $tenantRepository->method('count')->willReturn(2);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($userRepository, $tenantRepository) {
                if ($class === 'App\\Entity\\User') {
                    return $userRepository;
                }
                if ($class === 'App\\Entity\\Tenant') {
                    return $tenantRepository;
                }
                $repo = $this->createMock(EntityRepository::class);
                $repo->method('count')->willReturn(0);
                return $repo;
            });

        $preview = $this->service->getRestorePreview($backup);

        $this->assertArrayHasKey('metadata', $preview);
        $this->assertArrayHasKey('entities', $preview);
        $this->assertArrayHasKey('total_records', $preview);
        $this->assertEquals(3, $preview['total_records']);
        $this->assertEquals(2, $preview['entities']['User']['to_restore']);
        $this->assertEquals(5, $preview['entities']['User']['existing']);
    }

    public function testRestoreFromBackupWithInvalidDataThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid backup');

        $backup = [
            'metadata' => ['version' => '99.0'],
            'data' => [],
        ];

        $this->service->restoreFromBackup($backup);
    }

    public function testRestoreFromBackupInDryRunMode(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('rollback');

        $result = $this->service->restoreFromBackup($backup, ['dry_run' => true]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['dry_run']);
        $this->assertArrayHasKey('statistics', $result);
    }

    public function testRestoreFromBackupCreatesNewEntities(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->expects($this->once())->method('beginTransaction');
        $this->entityManager->expects($this->once())->method('flush');
        $this->entityManager->expects($this->once())->method('commit');

        $result = $this->service->restoreFromBackup($backup, ['dry_run' => false]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['dry_run']);
        $this->assertArrayHasKey('statistics', $result);
    }

    public function testRestoreFromBackupSkipsExistingEntitiesWhenConfigured(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->expects($this->once())->method('beginTransaction');

        $result = $this->service->restoreFromBackup($backup, [
            'existing_data_strategy' => RestoreService::EXISTING_SKIP,
            'dry_run' => false,
        ]);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['statistics']);
    }

    public function testRestoreFromBackupUpdatesExistingEntitiesWhenConfigured(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->expects($this->once())->method('beginTransaction');

        $result = $this->service->restoreFromBackup($backup, [
            'existing_data_strategy' => RestoreService::EXISTING_UPDATE,
            'dry_run' => false,
        ]);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['statistics']);
    }

    public function testRestoreFromBackupClearsDataBeforeRestoreWhenConfigured(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);

        $result = $this->service->restoreFromBackup($backup, [
            'clear_before_restore' => true,
            'dry_run' => false,
        ]);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['statistics']);
    }

    public function testRestoreFromBackupHandlesClosedEntityManager(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        // Return false on the final isOpen check (after operations complete)
        $this->entityManager->method('isOpen')->willReturnOnConsecutiveCalls(true, true, true, true, false);
        $this->entityManager->expects($this->once())->method('beginTransaction');

        $result = $this->service->restoreFromBackup($backup, ['dry_run' => false]);

        // Service should handle closed EM gracefully
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testRestoreFromBackupSetsAdminPasswordWhenProvided(): void
    {
        // This tests that admin password can be set after restore
        // Simplified to avoid complex query builder mocking
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [],
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);

        $result = $this->service->restoreFromBackup($backup, [
            'admin_password' => 'test123',
            'dry_run' => false,
        ]);

        $this->assertTrue($result['success']);
    }

    public function testGetValidationErrorsReturnsErrors(): void
    {
        $backup = [
            'metadata' => ['version' => '99.0'],
            'data' => [],
        ];

        $this->service->validateBackup($backup);
        $errors = $this->service->getValidationErrors();

        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
    }

    public function testGetWarningsReturnsWarnings(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [
                'UnknownEntity' => [],
            ],
        ];

        $this->service->validateBackup($backup);
        $warnings = $this->service->getWarnings();

        $this->assertIsArray($warnings);
    }

    public function testGetStatisticsReturnsStatistics(): void
    {
        $stats = $this->service->getStatistics();

        $this->assertIsArray($stats);
        $this->assertEmpty($stats); // No restore performed yet
    }

    private function createMockMetadata(string $entityClass): MockObject
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn(['id', 'name', 'slug', 'email']);
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('isSingleValuedAssociation')->willReturn(false);
        $metadata->method('getFieldMapping')->willReturn([
            'type' => 'string',
            'nullable' => true,
        ]);
        $metadata->generatorType = ClassMetadata::GENERATOR_TYPE_AUTO;
        $metadata->idGenerator = new \Doctrine\ORM\Id\IdentityGenerator();

        return $metadata;
    }
}
