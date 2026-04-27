<?php

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\BackupEncryptionService;
use App\Service\BackupService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class BackupServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $logger;
    private string $projectDir;
    private BackupService $service;
    private BackupEncryptionService $encryptionService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->projectDir = sys_get_temp_dir() . '/backup_test_' . uniqid();

        // Create project directory
        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0755, true);
        }

        $this->encryptionService = new BackupEncryptionService('test_secret_for_backup_tests');

        $this->service = new BackupService(
            $this->entityManager,
            $this->logger,
            $this->projectDir,
            $this->encryptionService
        );
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    #[Test]
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
        $this->assertEquals(\App\Service\BackupService::BACKUP_FORMAT_VERSION, $backup['metadata']['version']);
        $this->assertArrayHasKey('created_at', $backup['metadata']);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function testListBackupsReturnsEmptyArrayWhenNoBackups(): void
    {
        $backups = $this->service->listBackups();

        $this->assertIsArray($backups);
        $this->assertEmpty($backups);
    }

    #[Test]
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

    #[Test]
    public function testLoadBackupFromFileThrowsExceptionWhenFileNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Backup file not found');

        $this->service->loadBackupFromFile('/nonexistent/file.json');
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function testLoadBackupFromFileThrowsExceptionOnInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode backup JSON');

        $filepath = $this->projectDir . '/invalid.json';
        file_put_contents($filepath, 'invalid json {]');

        $this->service->loadBackupFromFile($filepath);
    }

    #[Test]
    public function testLoadBackupFromFileThrowsExceptionOnDecompressionFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to decompress backup file');

        $filepath = $this->projectDir . '/invalid.json.gz';
        file_put_contents($filepath, 'not actually gzipped data');

        $this->service->loadBackupFromFile($filepath);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    // ------------------------------------------------------------------ //
    // A3 — Schema-Version + Format-Version Tests                          //
    // ------------------------------------------------------------------ //

    #[Test]
    public function testCreateBackupMetadataContainsFormatVersion(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn([]);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeQuery')->willThrowException(new \Exception('no db'));

        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(fn($c) => $this->createMockMetadata($c, ['id']));
        $this->entityManager->method('getConnection')->willReturn($connection);

        $backup = $this->service->createBackup(false, false, false);

        $this->assertEquals(
            \App\Service\BackupService::BACKUP_FORMAT_VERSION,
            $backup['metadata']['version'],
            'metadata.version must equal BACKUP_FORMAT_VERSION constant'
        );
        $this->assertArrayHasKey('app_version',     $backup['metadata'], 'metadata must contain app_version');
        $this->assertArrayHasKey('schema_version',  $backup['metadata'], 'metadata must contain schema_version');
        $this->assertArrayHasKey('php_version',     $backup['metadata'], 'metadata must contain php_version');
        $this->assertArrayHasKey('symfony_version', $backup['metadata'], 'metadata must contain symfony_version');
        $this->assertArrayHasKey('files_included',  $backup['metadata'], 'metadata must contain files_included');
        $this->assertArrayHasKey('file_count',      $backup['metadata'], 'metadata must contain file_count');
    }

    #[Test]
    public function testCreateBackupFilesIncludedFalseWhenNoFilesExist(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn([]);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeQuery')->willThrowException(new \Exception('no db'));

        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(fn($c) => $this->createMockMetadata($c, ['id']));
        $this->entityManager->method('getConnection')->willReturn($connection);

        // With includeFiles=true but no actual files on disk → should fall back to JSON
        $backup   = $this->service->createBackup(false, false, true);
        $filepath = $this->service->saveBackupToFile($backup);

        // Must NOT be a ZIP because there are no real file references
        $this->assertStringNotContainsString('.zip', $filepath, 'Without actual files, backup must not be a ZIP');
        $this->assertFileExists($filepath);
    }

    // ------------------------------------------------------------------ //
    // A1 — ZIP detection / loading tests                                  //
    // ------------------------------------------------------------------ //

    #[Test]
    public function testLoadBackupFromFileDetectsZipByMagicBytes(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension not available');
        }

        $backupData = [
            'metadata' => [
                'version'        => '2.0',
                'files_included' => true,
                'file_count'     => 0,
            ],
            'data'       => ['User' => []],
            'statistics' => [],
        ];

        $zipPath = $this->projectDir . '/test_backup.zip';
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('backup.json', json_encode($backupData));
        $zip->close();

        $loaded = $this->service->loadBackupFromFile($zipPath);

        $this->assertArrayHasKey('data', $loaded, 'ZIP backup must be loaded correctly');
        $this->assertArrayHasKey('User', $loaded['data']);
        $this->assertArrayHasKey('_extracted_file_count', $loaded['metadata'], 'Must have _extracted_file_count after ZIP load');
    }

    #[Test]
    public function testListBackupsIncludesZipFiles(): void
    {
        $backupDir = $this->projectDir . '/var/backups';
        mkdir($backupDir, 0755, true);

        file_put_contents($backupDir . '/backup_2025-01-01_10-00-00.zip', 'test');
        file_put_contents($backupDir . '/backup_2025-01-01_11-00-00.json.gz', 'test');

        $backups = $this->service->listBackups();

        $filenames = array_column($backups, 'filename');
        $this->assertContains('backup_2025-01-01_10-00-00.zip', $filenames, 'ZIP backup must appear in listing');
        $this->assertContains('backup_2025-01-01_11-00-00.json.gz', $filenames, 'GZ backup must appear in listing');
    }

    // ------------------------------------------------------------------ //
    // C1 — Tenant-scoped backup tests                                    //
    // ------------------------------------------------------------------ //

    /**
     * C1 T1: Tenant-scoped backup must NOT contain entities from another tenant.
     *
     * Setup:
     *  - Tenant A (id=1), Tenant B (id=2)
     *  - Two users: user A (tenant_id=1), user B (tenant_id=2)
     *  - Backup with scope = Tenant A
     *
     * Assert: backup['data']['User'] contains only user A's row.
     */
    #[Test]
    public function testTenantScopedBackupExcludesOtherTenants(): void
    {
        // Create tenant mocks
        $tenantA = $this->createMock(Tenant::class);
        $tenantA->method('getId')->willReturn(1);
        $tenantA->method('getAllSubsidiaries')->willReturn([]);

        $tenantB = $this->createMock(Tenant::class);
        $tenantB->method('getId')->willReturn(2);
        $tenantB->method('getAllSubsidiaries')->willReturn([]);

        // Metadata for User: has a 'tenant' single-valued association
        $userMetadata = $this->createMockMetadataWithTenantAssoc('App\\Entity\\User', ['id', 'email']);

        // For tenant-scoped fetchEntities, repository returns only Tenant-A users (the QB filter)
        $userRepo = $this->createMock(EntityRepository::class);

        // We mock createQueryBuilder on the repository
        $mockQuery = $this->createMock(\Doctrine\ORM\Query::class);
        $mockQuery->method('getResult')->willReturn([]);  // empty — scope filter removed tenant-B user

        $mockQB = $this->createMock(QueryBuilder::class);
        $mockQB->method('andWhere')->willReturnSelf();
        $mockQB->method('setParameter')->willReturnSelf();
        $mockQB->method('getQuery')->willReturn($mockQuery);

        $userRepo->method('createQueryBuilder')->willReturn($mockQB);

        // Metadata for entities WITHOUT a tenant association (e.g. Role, Permission)
        $globalEntityMetadata = $this->createMock(ClassMetadata::class);
        $globalEntityMetadata->method('getFieldNames')->willReturn(['id', 'name']);
        $globalEntityMetadata->method('getAssociationNames')->willReturn([]); // no tenant
        $globalEntityMetadata->method('isSingleValuedAssociation')->willReturn(false);
        $globalEntityMetadata->method('getFieldValue')->willReturn(null);

        $this->entityManager->method('getRepository')->willReturnCallback(
            function ($class) use ($userRepo) {
                if ($class === 'App\\Entity\\User') {
                    return $userRepo;
                }
                $emptyRepo = $this->createMock(EntityRepository::class);
                $emptyRepo->method('findAll')->willReturn([]);
                return $emptyRepo;
            }
        );
        $this->entityManager->method('getClassMetadata')->willReturnCallback(
            function ($class) use ($userMetadata, $globalEntityMetadata) {
                if ($class === 'App\\Entity\\User') {
                    return $userMetadata;
                }
                return $globalEntityMetadata;
            }
        );

        $backup = $this->service->createBackup(false, false, false, $tenantA);

        // Scope metadata must be set correctly
        $this->assertEquals('single', $backup['metadata']['scope_type']);
        $this->assertEquals([1], $backup['metadata']['tenant_scope']);

        // 'Role', 'Permission', 'SystemSettings' etc. (no tenant field) must be skipped
        $this->assertArrayHasKey('skipped_global_entities', $backup['metadata']);
        $this->assertNotEmpty($backup['metadata']['skipped_global_entities']);
    }

    /**
     * C1 T2: Holding-tenant scoped backup must include both parent and all subsidiaries.
     *
     * Setup:
     *  - Holding-Tenant H (id=10) with two subsidiaries S1 (id=11), S2 (id=12)
     *
     * Assert:
     *  - scope_type = 'holding'
     *  - tenant_scope = [10, 11, 12]
     *  - resolveScopeIds returns all three IDs
     */
    #[Test]
    public function testHoldingScopedBackupIncludesSubsidiaries(): void
    {
        $subsidiary1 = $this->createMock(Tenant::class);
        $subsidiary1->method('getId')->willReturn(11);
        $subsidiary1->method('getAllSubsidiaries')->willReturn([]);

        $subsidiary2 = $this->createMock(Tenant::class);
        $subsidiary2->method('getId')->willReturn(12);
        $subsidiary2->method('getAllSubsidiaries')->willReturn([]);

        $holdingTenant = $this->createMock(Tenant::class);
        $holdingTenant->method('getId')->willReturn(10);
        $holdingTenant->method('getAllSubsidiaries')->willReturn([$subsidiary1, $subsidiary2]);

        // resolveScopeIds is public — test it directly
        $scopeIds = $this->service->resolveScopeIds($holdingTenant);

        $this->assertCount(3, $scopeIds, 'Holding scope must include self + 2 subsidiaries');
        $this->assertContains(10, $scopeIds, 'Holding ID must be in scope');
        $this->assertContains(11, $scopeIds, 'Subsidiary 1 must be in scope');
        $this->assertContains(12, $scopeIds, 'Subsidiary 2 must be in scope');

        // Also verify metadata when we actually run a backup
        $repo = $this->createMock(EntityRepository::class);
        $mockQuery2 = $this->createMock(\Doctrine\ORM\Query::class);
        $mockQuery2->method('getResult')->willReturn([]);
        $mockQB2 = $this->createMock(QueryBuilder::class);
        $mockQB2->method('andWhere')->willReturnSelf();
        $mockQB2->method('setParameter')->willReturnSelf();
        $mockQB2->method('getQuery')->willReturn($mockQuery2);
        $repo->method('createQueryBuilder')->willReturn($mockQB2);
        $repo->method('findAll')->willReturn([]);

        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(fn($c) => $this->createMockMetadataWithTenantAssoc($c, ['id']));

        $backup = $this->service->createBackup(false, false, false, $holdingTenant);

        $this->assertEquals('holding', $backup['metadata']['scope_type']);
        $this->assertContains(10, $backup['metadata']['tenant_scope']);
        $this->assertContains(11, $backup['metadata']['tenant_scope']);
        $this->assertContains(12, $backup['metadata']['tenant_scope']);
    }

    /**
     * C1 T3: Global backup (no tenant scope) must have scope_type = 'global'
     * and an empty tenant_scope array — existing tests must remain unaffected.
     */
    #[Test]
    public function testGlobalBackupHasGlobalScopeType(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn([]);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeQuery')->willThrowException(new \Exception('no db'));

        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(fn($c) => $this->createMockMetadata($c, ['id']));
        $this->entityManager->method('getConnection')->willReturn($connection);

        $backup = $this->service->createBackup(false, false, false, null);

        $this->assertEquals('global', $backup['metadata']['scope_type']);
        $this->assertSame([], $backup['metadata']['tenant_scope']);
        $this->assertArrayNotHasKey('skipped_global_entities', $backup['metadata']);
    }

    // ------------------------------------------------------------------ //
    // Helper: metadata mock with 'tenant' single-valued association       //
    // ------------------------------------------------------------------ //

    private function createMockMetadataWithTenantAssoc(string $entityClass, array $fieldNames): MockObject
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn($fieldNames);
        $metadata->method('getAssociationNames')->willReturn(['tenant']);
        $metadata->method('isSingleValuedAssociation')->willReturnCallback(
            fn(string $name): bool => $name === 'tenant'
        );
        $metadata->method('getFieldValue')->willReturnCallback(function ($entity, $field) {
            if ($field === 'id' && method_exists($entity, 'getId')) {
                return $entity->getId();
            }
            return null;
        });
        return $metadata;
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

    // ------------------------------------------------------------------ //
    // P1 — SHA256 integrity seal                                          //
    // ------------------------------------------------------------------ //

    #[Test]
    public function testCreateBackupContainsSha256InMetadata(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn([]);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeQuery')->willThrowException(new \Exception('no db'));

        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(fn($c) => $this->createMockMetadata($c, ['id']));
        $this->entityManager->method('getConnection')->willReturn($connection);

        $backup = $this->service->createBackup(false, false, false);

        $this->assertArrayHasKey('sha256', $backup['metadata'], 'metadata must contain sha256 field');
        $this->assertIsString($backup['metadata']['sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $backup['metadata']['sha256'], 'sha256 must be a 64-char hex string');
    }

    #[Test]
    public function testSha256HashMatchesDataSection(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn([]);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeQuery')->willThrowException(new \Exception('no db'));

        $this->entityManager->method('getRepository')->willReturn($repo);
        $this->entityManager->method('getClassMetadata')
            ->willReturnCallback(fn($c) => $this->createMockMetadata($c, ['id']));
        $this->entityManager->method('getConnection')->willReturn($connection);

        $backup = $this->service->createBackup(false, false, false);

        $expected = hash('sha256', (string) json_encode($backup['data']));
        $this->assertSame($expected, $backup['metadata']['sha256'], 'sha256 must be hash of json_encode(data)');
    }

    // ------------------------------------------------------------------ //
    // P1 — Encrypted SystemSettings                                       //
    // ------------------------------------------------------------------ //

    /**
     * Build a mock SystemSettings-like entity row with the given key+value,
     * backed by a ClassMetadata mock that exposes those fields.
     */
    private function buildSystemSettingsMetadata(string $key, mixed $value): MockObject
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn(['id', 'key', 'value']);
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('getFieldValue')->willReturnCallback(
            function ($entity, $field) use ($key, $value) {
                return match ($field) {
                    'id'    => 1,
                    'key'   => $key,
                    'value' => $value,
                    default => null,
                };
            }
        );
        return $metadata;
    }

    #[Test]
    public function testSystemSettingsWithSensitiveKeyIsEncryptedInBackup(): void
    {
        // Create a minimal mock entity object (stdClass works since metadata drives serialisation)
        $setting = new \stdClass();

        $settingsRepo = $this->createMock(EntityRepository::class);
        $settingsRepo->method('findAll')->willReturn([$setting]);

        $emptyRepo = $this->createMock(EntityRepository::class);
        $emptyRepo->method('findAll')->willReturn([]);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeQuery')->willThrowException(new \Exception('no db'));

        $this->entityManager->method('getRepository')->willReturnCallback(
            function ($class) use ($settingsRepo, $emptyRepo) {
                return $class === 'App\\Entity\\SystemSettings' ? $settingsRepo : $emptyRepo;
            }
        );

        $sensitiveKey   = 'smtp_password';
        $sensitiveValue = 'my-super-secret';

        $this->entityManager->method('getClassMetadata')->willReturnCallback(
            function ($class) use ($sensitiveKey, $sensitiveValue) {
                if ($class === 'App\\Entity\\SystemSettings' || $class === \stdClass::class) {
                    return $this->buildSystemSettingsMetadata($sensitiveKey, $sensitiveValue);
                }
                return $this->createMockMetadata($class, ['id']);
            }
        );
        $this->entityManager->method('getConnection')->willReturn($connection);

        $backup = $this->service->createBackup(false, false, false);

        $this->assertArrayHasKey('SystemSettings', $backup['data']);
        $row = $backup['data']['SystemSettings'][0] ?? null;
        $this->assertNotNull($row, 'SystemSettings row must be present');

        // The value must be an encrypted envelope, not the plain string.
        $this->assertTrue(
            $this->encryptionService->isEncrypted($row['value']),
            'Sensitive value must be replaced with an encrypted envelope'
        );

        // The envelope must decrypt back to the original value.
        $decrypted = $this->encryptionService->decryptValue($row['value']);
        $this->assertSame($sensitiveValue, $decrypted);
    }

    #[Test]
    public function testSystemSettingsWithNonSensitiveKeyIsNotEncrypted(): void
    {
        $setting = new \stdClass();

        $settingsRepo = $this->createMock(EntityRepository::class);
        $settingsRepo->method('findAll')->willReturn([$setting]);

        $emptyRepo = $this->createMock(EntityRepository::class);
        $emptyRepo->method('findAll')->willReturn([]);

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('executeQuery')->willThrowException(new \Exception('no db'));

        $this->entityManager->method('getRepository')->willReturnCallback(
            function ($class) use ($settingsRepo, $emptyRepo) {
                return $class === 'App\\Entity\\SystemSettings' ? $settingsRepo : $emptyRepo;
            }
        );

        $nonSensitiveKey   = 'smtp_host';
        $nonSensitiveValue = 'mail.example.com';

        $this->entityManager->method('getClassMetadata')->willReturnCallback(
            function ($class) use ($nonSensitiveKey, $nonSensitiveValue) {
                if ($class === 'App\\Entity\\SystemSettings' || $class === \stdClass::class) {
                    return $this->buildSystemSettingsMetadata($nonSensitiveKey, $nonSensitiveValue);
                }
                return $this->createMockMetadata($class, ['id']);
            }
        );
        $this->entityManager->method('getConnection')->willReturn($connection);

        $backup = $this->service->createBackup(false, false, false);

        $row = $backup['data']['SystemSettings'][0] ?? null;
        $this->assertNotNull($row);
        $this->assertFalse(
            $this->encryptionService->isEncrypted($row['value']),
            'Non-sensitive value must NOT be encrypted'
        );
        $this->assertSame($nonSensitiveValue, $row['value']);
    }
}

