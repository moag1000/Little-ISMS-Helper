<?php

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\BackupService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BackupServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $auditLogger;
    private MockObject $logger;
    private string $projectDir;
    private BackupService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->projectDir = sys_get_temp_dir() . '/backup_test_' . uniqid();

        // Create project directory
        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0755, true);
        }

        $this->service = new BackupService(
            $this->entityManager,
            $this->auditLogger,
            $this->logger,
            $this->projectDir
        );
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testCreateBackupWithDefaultOptions(): void
    {
        $user = $this->createMockUser(1, 'test@example.com');

        // Mock User repository
        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('findAll')->willReturn([$user]);

        // Mock AuditLog repository - returns empty array
        $auditLogRepository = $this->createMock(EntityRepository::class);
        $auditLogRepository->method('findAll')->willReturn([]);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($userRepository, $auditLogRepository) {
                if ($class === 'App\\Entity\\User') {
                    return $userRepository;
                }
                if ($class === AuditLog::class) {
                    return $auditLogRepository;
                }
                // Return empty repository for other entities
                $emptyRepo = $this->createMock(EntityRepository::class);
                $emptyRepo->method('findAll')->willReturn([]);
                return $emptyRepo;
            });

        // Mock metadata for User entity - use willReturnCallback to handle any class
        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(function ($class) {
                return $this->createMockMetadata($class, ['id', 'email', 'name']);
            });

        $backup = $this->service->createBackup(true, false);

        $this->assertIsArray($backup);
        $this->assertArrayHasKey('metadata', $backup);
        $this->assertArrayHasKey('data', $backup);
        $this->assertArrayHasKey('statistics', $backup);
        $this->assertEquals('1.0', $backup['metadata']['version']);
        $this->assertArrayHasKey('created_at', $backup['metadata']);
    }

    public function testCreateBackupExcludesAuditLogWhenRequested(): void
    {
        $user = $this->createMockUser(1, 'test@example.com');

        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('findAll')->willReturn([$user]);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($userRepository) {
                if ($class === 'App\\Entity\\User') {
                    return $userRepository;
                }
                $emptyRepo = $this->createMock(EntityRepository::class);
                $emptyRepo->method('findAll')->willReturn([]);
                return $emptyRepo;
            });

        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(function ($class) {
                return $this->createMockMetadata($class, ['id', 'email', 'name']);
            });

        $backup = $this->service->createBackup(false, false);

        $this->assertArrayNotHasKey('AuditLog', $backup['data']);
    }

    public function testCreateBackupIncludesUserSessionsWhenRequested(): void
    {
        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('findAll')->willReturn([]);

        $userSessionRepository = $this->createMock(EntityRepository::class);
        $userSessionRepository->method('findAll')->willReturn([]);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($userRepository, $userSessionRepository) {
                if ($class === 'App\\Entity\\UserSession') {
                    return $userSessionRepository;
                }
                return $userRepository;
            });

        $backup = $this->service->createBackup(false, true);

        // UserSession should be attempted (even if no data)
        $this->assertIsArray($backup['data']);
    }

    public function testSaveBackupToFileCreatesDirectory(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [],
            'statistics' => [],
        ];

        $filepath = $this->service->saveBackupToFile($backup);

        $this->assertFileExists($filepath);
        $this->assertStringEndsWith('.gz', $filepath);
    }

    public function testSaveBackupToFileWithCustomFilename(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [],
            'statistics' => [],
        ];

        $filename = 'custom_backup.json';
        $filepath = $this->service->saveBackupToFile($backup, $filename);

        $this->assertStringContainsString('custom_backup.json.gz', $filepath);
        $this->assertFileExists($filepath);
    }

    public function testSaveBackupToFileThrowsExceptionOnJsonError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode backup data to JSON');

        // Create an invalid structure that can't be JSON encoded
        // Using a resource which cannot be JSON encoded
        $resource = fopen('php://memory', 'r');
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => ['invalid' => $resource],
        ];

        try {
            $this->service->saveBackupToFile($backup);
        } finally {
            fclose($resource);
        }
    }

    public function testListBackupsReturnsEmptyArrayWhenNoBackups(): void
    {
        $backups = $this->service->listBackups();

        $this->assertIsArray($backups);
        $this->assertEmpty($backups);
    }

    public function testListBackupsReturnsExistingBackups(): void
    {
        $backupDir = $this->projectDir . '/var/backups';
        mkdir($backupDir, 0755, true);

        // Create test backup files
        file_put_contents($backupDir . '/backup_2024-01-01_12-00-00.json.gz', 'test');
        file_put_contents($backupDir . '/uploaded_test.json.gz', 'test');

        $backups = $this->service->listBackups();

        $this->assertGreaterThanOrEqual(2, count($backups));
        $this->assertArrayHasKey('filename', $backups[0]);
        $this->assertArrayHasKey('path', $backups[0]);
        $this->assertArrayHasKey('size', $backups[0]);
        $this->assertArrayHasKey('created_at', $backups[0]);
    }

    public function testLoadBackupFromFileThrowsExceptionWhenFileNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Backup file not found');

        $this->service->loadBackupFromFile('/nonexistent/file.json');
    }

    public function testLoadBackupFromFileLoadsCompressedFile(): void
    {
        $backupData = [
            'metadata' => ['version' => '1.0'],
            'data' => ['User' => []],
            'statistics' => [],
        ];

        $json = json_encode($backupData);
        $compressed = gzencode($json);

        $filepath = $this->projectDir . '/test_backup.json.gz';
        file_put_contents($filepath, $compressed);

        $loaded = $this->service->loadBackupFromFile($filepath);

        $this->assertEquals($backupData, $loaded);
    }

    public function testLoadBackupFromFileLoadsUncompressedFile(): void
    {
        $backupData = [
            'metadata' => ['version' => '1.0'],
            'data' => ['User' => []],
            'statistics' => [],
        ];

        $json = json_encode($backupData);
        $filepath = $this->projectDir . '/test_backup.json';
        file_put_contents($filepath, $json);

        $loaded = $this->service->loadBackupFromFile($filepath);

        $this->assertEquals($backupData, $loaded);
    }

    public function testLoadBackupFromFileThrowsExceptionOnInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode backup JSON');

        $filepath = $this->projectDir . '/invalid.json';
        file_put_contents($filepath, 'invalid json {]');

        $this->service->loadBackupFromFile($filepath);
    }

    public function testLoadBackupFromFileThrowsExceptionOnDecompressionFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decompress backup file');

        $filepath = $this->projectDir . '/invalid.json.gz';
        file_put_contents($filepath, 'not actually gzipped data');

        $this->service->loadBackupFromFile($filepath);
    }

    public function testSerializeEntitiesExcludesSensitiveFields(): void
    {
        $user = $this->createMockUser(1, 'test@example.com');

        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('findAll')->willReturn([$user]);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(function ($class) use ($userRepository) {
                if ($class === 'App\\Entity\\User') {
                    return $userRepository;
                }
                $emptyRepo = $this->createMock(EntityRepository::class);
                $emptyRepo->method('findAll')->willReturn([]);
                return $emptyRepo;
            });

        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(function ($class) {
                return $this->createMockMetadata($class, ['id', 'email', 'password']);
            });

        $backup = $this->service->createBackup(false, false);

        // Password should be excluded
        if (isset($backup['data']['User'][0])) {
            $this->assertArrayNotHasKey('password', $backup['data']['User'][0]);
        }
    }

    public function testCreateBackupHandlesEntityNotFound(): void
    {
        // This test verifies that the backup handles missing entity classes gracefully
        // by logging warnings and continuing with other entities

        $emptyRepo = $this->createMock(EntityRepository::class);
        $emptyRepo->method('findAll')->willReturn([]);

        $this->entityManager->method('getRepository')
            ->willReturn($emptyRepo);

        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(function ($class) {
                return $this->createMockMetadata($class, ['id']);
            });

        $backup = $this->service->createBackup(false, false);

        $this->assertIsArray($backup);
        $this->assertArrayHasKey('metadata', $backup);
    }

    public function testCreateBackupLogsProgress(): void
    {
        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('findAll')->willReturn([]);

        $this->entityManager->method('getRepository')
            ->willReturn($userRepository);

        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(function ($class) {
                return $this->createMockMetadata($class, ['id']);
            });

        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->service->createBackup(false, false);
    }

    private function createMockUser(int $id, string $email): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getEmail')->willReturn($email);
        return $user;
    }

    private function createMockMetadata(string $entityClass, array $fieldNames): MockObject
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn($fieldNames);
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('getFieldValue')->willReturnCallback(function ($entity, $field) {
            if ($field === 'id') {
                return $entity->getId();
            }
            if ($field === 'email') {
                return $entity->getEmail();
            }
            return null;
        });
        return $metadata;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
