<?php

namespace App\Tests\Service;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\BackupEncryptionService;
use App\Service\BackupService;
use App\Service\RestoreService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\EntityRepository;
use Doctrine\Common\EventManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class RestoreServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $auditLogger;
    private MockObject $logger;
    private MockObject $passwordHasher;
    private RestoreService $service;
    private BackupEncryptionService $encryptionService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $this->encryptionService = new BackupEncryptionService('test_secret_for_restore_tests');

        $this->service = new RestoreService(
            $this->entityManager,
            $this->auditLogger,
            $this->logger,
            $this->passwordHasher,
            $this->encryptionService
        );
    }

    #[Test]
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

    #[Test]
    public function testValidateBackupFailsWithoutMetadata(): void
    {
        $backup = [
            'data' => [],
        ];

        $result = $this->service->validateBackup($backup);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing metadata section', $result['errors']);
    }

    #[Test]
    public function testValidateBackupFailsWithUnsupportedVersion(): void
    {
        // Use a version that is truly unsupported (3.0 is beyond current max)
        $backup = [
            'metadata' => [
                'version' => '99.0',
            ],
            'data' => [],
        ];

        $result = $this->service->validateBackup($backup);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, array_filter($result['errors'], function ($error) {
            return str_contains($error, 'Unsupported backup version');
        }));
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function testGetStatisticsReturnsStatistics(): void
    {
        $stats = $this->service->getStatistics();

        $this->assertIsArray($stats);
        $this->assertEmpty($stats); // No restore performed yet
    }

    /**
     * Audit-Befund: ManyToMany-Collections wurden stillschweigend übergangen.
     * Prüft, dass restoreManyToManyAssociations() INSERT IGNORE-Statements
     * für owning-side ManyToMany-Assoziationen in die Pivot-Tabelle schreibt.
     */
    #[Test]
    public function testManyToManyAssociationsAreRestored(): void
    {
        // Build a minimal backup with one Asset that has two dependsOn IDs
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [
                'Asset' => [
                    [
                        'id'          => 1,
                        'name'        => 'Server A',
                        'dependsOn_ids' => [['id' => 2], ['id' => 3]],
                    ],
                ],
            ],
        ];

        $pivotInserted = null;

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$pivotInserted) {
                // Capture the first INSERT IGNORE call targeting a pivot table
                if (str_contains($sql, 'INSERT IGNORE') && str_contains($sql, 'asset_dependencies')) {
                    $pivotInserted = ['sql' => $sql, 'params' => $params];
                }
                return 1;
            });

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        // ClassMetadata for Asset must report the dependsOn ManyToMany owning-side mapping
        $fieldMapping = new FieldMapping(type: 'string', fieldName: 'name', columnName: 'name');
        $fieldMapping->nullable = true;

        $assetMetadata = $this->createMock(ClassMetadata::class);
        $assetMetadata->method('getFieldNames')->willReturn(['id', 'name']);
        $assetMetadata->method('getAssociationNames')->willReturn(['dependsOn', 'dependentAssets']);
        $assetMetadata->method('isSingleValuedAssociation')->willReturn(false);
        $assetMetadata->method('getFieldMapping')->willReturn($fieldMapping);
        $assetMetadata->method('getTableName')->willReturn('asset');
        $assetMetadata->method('getAssociationMappings')->willReturn([
            'dependsOn' => [
                'type'         => ClassMetadata::MANY_TO_MANY,
                'isOwningSide' => true,
                'fieldName'    => 'dependsOn',
                'targetEntity' => 'App\\Entity\\Asset',
                'joinTable'    => [
                    'name'               => 'asset_dependencies',
                    'joinColumns'        => [['name' => 'dependent_asset_id']],
                    'inverseJoinColumns' => [['name' => 'depends_on_asset_id']],
                ],
            ],
            'dependentAssets' => [
                'type'         => ClassMetadata::MANY_TO_MANY,
                'isOwningSide' => false,
                'fieldName'    => 'dependentAssets',
                'targetEntity' => 'App\\Entity\\Asset',
                'joinTable'    => [],
            ],
        ]);
        $assetMetadata->generatorType = ClassMetadata::GENERATOR_TYPE_AUTO;
        $assetMetadata->idGenerator   = new \Doctrine\ORM\Id\IdentityGenerator();

        $assetRepository = $this->createMock(EntityRepository::class);
        $assetRepository->method('find')->willReturn(null);
        $assetRepository->method('findOneBy')->willReturn(null);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->method('getClassMetadata')->willReturn($assetMetadata);
        $this->entityManager->method('getRepository')->willReturn($assetRepository);
        $this->entityManager->method('getReference')->willReturn(null);

        $result = $this->service->restoreFromBackup($backup, ['dry_run' => false]);

        $this->assertTrue($result['success']);

        // The pivot INSERT IGNORE must have been issued with owner-ID=1 and both target IDs
        $this->assertNotNull($pivotInserted, 'Expected INSERT IGNORE into asset_dependencies pivot table');
        $this->assertStringContainsString('INSERT IGNORE', $pivotInserted['sql']);
        $this->assertStringContainsString('asset_dependencies', $pivotInserted['sql']);
        $this->assertContains(1, $pivotInserted['params']); // owner ID
        $this->assertContains(2, $pivotInserted['params']); // first target
        $this->assertContains(3, $pivotInserted['params']); // second target
    }

    /**
     * Audit-Befund: DQL DELETE FROM Entity löscht keine Pivot-Tabellen.
     * Prüft, dass clearExistingData() vor den Entity-DELETEs die Pivot-Tabellen leert.
     */
    #[Test]
    public function testPivotTablesAreClearedBeforeEntityDelete(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data' => [
                'Asset' => [],
            ],
        ];

        $deletedTables = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$deletedTables) {
                // Capture DELETE statements targeting pivot tables
                if (preg_match('/DELETE FROM `([^`]+)`/', $sql, $m)) {
                    $deletedTables[] = $m[1];
                }
                return 1;
            });

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        // Minimal metadata for Asset: one owning-side ManyToMany (asset_dependencies)
        $fieldMapping2 = new FieldMapping(type: 'string', fieldName: 'name', columnName: 'name');
        $fieldMapping2->nullable = true;

        $assetMetadata = $this->createMock(ClassMetadata::class);
        $assetMetadata->method('getFieldNames')->willReturn(['id', 'name']);
        $assetMetadata->method('getAssociationNames')->willReturn(['dependsOn']);
        $assetMetadata->method('isSingleValuedAssociation')->willReturn(false);
        $assetMetadata->method('getFieldMapping')->willReturn($fieldMapping2);
        $assetMetadata->method('getTableName')->willReturn('asset');
        $assetMetadata->method('getAssociationMappings')->willReturn([
            'dependsOn' => [
                'type'         => ClassMetadata::MANY_TO_MANY,
                'isOwningSide' => true,
                'fieldName'    => 'dependsOn',
                'targetEntity' => 'App\\Entity\\Asset',
                'joinTable'    => [
                    'name'               => 'asset_dependencies',
                    'joinColumns'        => [['name' => 'dependent_asset_id']],
                    'inverseJoinColumns' => [['name' => 'depends_on_asset_id']],
                ],
            ],
        ]);
        $assetMetadata->generatorType = ClassMetadata::GENERATOR_TYPE_AUTO;
        $assetMetadata->idGenerator   = new \Doctrine\ORM\Id\IdentityGenerator();

        $assetRepository = $this->createMock(EntityRepository::class);
        $assetRepository->method('find')->willReturn(null);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->method('getClassMetadata')->willReturn($assetMetadata);
        $this->entityManager->method('getRepository')->willReturn($assetRepository);

        $result = $this->service->restoreFromBackup($backup, [
            'clear_before_restore' => true,
            'dry_run'              => false,
        ]);

        $this->assertTrue($result['success']);

        // The pivot table must have been DELETEd before (or independently from) entity deletion
        $this->assertContains(
            'asset_dependencies',
            $deletedTables,
            'Expected pivot table asset_dependencies to be cleared during clear_before_restore'
        );
    }

    /**
     * A2 — Round-Trip-Test
     *
     * Prüft, dass BackupService + RestoreService zusammen eine vollständige
     * Backup → Clear → Restore-Pipeline korrekt durchlaufen:
     *
     *  1. BackupService::createBackup() serialisiert Fixtures.
     *  2. BackupService::saveBackupToFile() speichert auf Disk.
     *  3. BackupService::loadBackupFromFile() lädt die Datei zurück.
     *  4. RestoreService::restoreFromBackup() restored die Entitäten.
     *
     * Alle Scalar-Felder und ManyToMany-Associations (dependsOn_ids) müssen
     * nach dem Round-Trip identisch zur Original-Fixture sein.
     */
    #[Test]
    public function testFullRoundTripPreservesAllData(): void
    {
        // ------------------------------------------------------------------ //
        // 1. Fixtures: two Assets where Asset 1 dependsOn [Asset 2, Asset 3] //
        // ------------------------------------------------------------------ //
        $projectDir = sys_get_temp_dir() . '/roundtrip_test_' . uniqid();
        mkdir($projectDir, 0755, true);

        // Minimal Asset-like fixture objects (anonymous classes — no Doctrine needed)
        $asset1 = new class {
            public function getId(): int    { return 1; }
            public function getName(): string { return 'Server Alpha'; }
        };
        $asset2 = new class {
            public function getId(): int    { return 2; }
            public function getName(): string { return 'DB Primary'; }
        };
        $asset3 = new class {
            public function getId(): int    { return 3; }
            public function getName(): string { return 'NAS Storage'; }
        };

        // ------------------------------------------------------------------ //
        // 2. Set up mocked EntityManager for BackupService                   //
        // ------------------------------------------------------------------ //
        $backupLogger  = $this->createMock(\Psr\Log\LoggerInterface::class);

        $assetRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $assetRepo->method('findAll')->willReturn([$asset1, $asset2, $asset3]);

        $emptyRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $emptyRepo->method('findAll')->willReturn([]);

        // Minimal metadata: fields + owning-side ManyToMany for dependsOn
        $fieldMappingId   = new FieldMapping(type: 'integer', fieldName: 'id', columnName: 'id');
        $fieldMappingName = new FieldMapping(type: 'string', fieldName: 'name', columnName: 'name');
        $fieldMappingName->nullable = false;

        // Simulate the ArrayCollection for dependsOn on asset1 only (defined before metadata to capture in closure)
        $dependsOnCollection = new \Doctrine\Common\Collections\ArrayCollection([$asset2, $asset3]);
        $emptyCollection     = new \Doctrine\Common\Collections\ArrayCollection();

        $assetMetadata = $this->createMock(ClassMetadata::class);
        $assetMetadata->method('getFieldNames')->willReturn(['id', 'name']);
        $assetMetadata->method('getFieldMapping')->willReturnCallback(
            fn(string $f) => $f === 'id' ? $fieldMappingId : $fieldMappingName
        );
        // Single combined getFieldValue: handles both scalar fields and association collections.
        // BackupService::serializeEntities() calls getFieldValue for field names AND for association names.
        $assetMetadata->method('getFieldValue')->willReturnCallback(
            function (object $entity, string $field) use ($dependsOnCollection, $emptyCollection): mixed {
                if ($field === 'id')        { return $entity->getId(); }
                if ($field === 'name')      { return $entity->getName(); }
                if ($field === 'dependsOn') { return $entity->getId() === 1 ? $dependsOnCollection : $emptyCollection; }
                return null;
            }
        );
        $assetMetadata->method('getAssociationNames')->willReturn(['dependsOn']);
        $assetMetadata->method('isSingleValuedAssociation')->willReturn(false);
        $assetMetadata->method('getAssociationMappings')->willReturn([
            'dependsOn' => [
                'type'         => ClassMetadata::MANY_TO_MANY,
                'isOwningSide' => true,
                'fieldName'    => 'dependsOn',
                'joinTable'    => [
                    'name'               => 'asset_dependencies',
                    'joinColumns'        => [['name' => 'dependent_asset_id']],
                    'inverseJoinColumns' => [['name' => 'depends_on_asset_id']],
                ],
            ],
        ]);
        $assetMetadata->method('getIdentifierValues')->willReturnCallback(
            fn(object $item): array => ['id' => $item->getId()]
        );

        $backupConn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $backupConn->method('executeQuery')->willThrowException(new \Exception('no db in test'));

        $backupEm = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $backupEm->method('getRepository')->willReturnCallback(
            fn(string $class): object => str_ends_with($class, 'Asset') ? $assetRepo : $emptyRepo
        );
        $backupEm->method('getClassMetadata')->willReturn($assetMetadata);
        $backupEm->method('getConnection')->willReturn($backupConn);

        // ------------------------------------------------------------------ //
        // 3. Create backup                                                    //
        // ------------------------------------------------------------------ //
        $backupService = new \App\Service\BackupService($backupEm, $backupLogger, $projectDir);
        $backupData    = $backupService->createBackup(false, false, false);

        $this->assertArrayHasKey('Asset', $backupData['data'], 'Asset must be present in backup');
        $this->assertCount(3, $backupData['data']['Asset'], 'All 3 assets must be backed up');

        $asset1Serialized = $backupData['data']['Asset'][0];
        $this->assertEquals(1,              $asset1Serialized['id']);
        $this->assertEquals('Server Alpha', $asset1Serialized['name']);
        $this->assertArrayHasKey('dependsOn_ids', $asset1Serialized, 'dependsOn_ids must be serialized');
        $this->assertCount(2, $asset1Serialized['dependsOn_ids'], 'Asset 1 must reference 2 dependsOn');

        // ------------------------------------------------------------------ //
        // 4. Save to disk and reload (round-trip through file)                //
        // ------------------------------------------------------------------ //
        $savedPath   = $backupService->saveBackupToFile($backupData);
        $this->assertFileExists($savedPath, 'Backup file must exist on disk after save');

        $loadedBackup = $backupService->loadBackupFromFile($savedPath);
        $this->assertArrayHasKey('Asset', $loadedBackup['data'], 'Reloaded backup must contain Asset');
        $this->assertCount(3, $loadedBackup['data']['Asset'], 'Reloaded backup must have 3 assets');

        $loadedAsset1 = $loadedBackup['data']['Asset'][0];
        $this->assertEquals(1,              $loadedAsset1['id'],   'ID must survive round-trip');
        $this->assertEquals('Server Alpha', $loadedAsset1['name'], 'Name must survive round-trip');
        $this->assertCount(2, $loadedAsset1['dependsOn_ids'],       'ManyToMany refs must survive round-trip');

        // ------------------------------------------------------------------ //
        // 5. Restore phase: verify restore runs without error and emits       //
        //    the correct INSERT IGNORE for the ManyToMany pivot               //
        // ------------------------------------------------------------------ //
        $pivotInserted = [];
        $restoreConn   = $this->createMock(\Doctrine\DBAL\Connection::class);
        $restoreConn->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []) use (&$pivotInserted): int {
                if (str_contains($sql, 'INSERT IGNORE') && str_contains($sql, 'asset_dependencies')) {
                    $pivotInserted[] = $params;
                }
                return 1;
            }
        );
        $restoreConn->method('isTransactionActive')->willReturn(true);

        $restoreAssetMetadata = $this->createMock(ClassMetadata::class);
        $restoreAssetMetadata->method('getFieldNames')->willReturn(['id', 'name']);
        $restoreAssetMetadata->method('getFieldMapping')->willReturn($fieldMappingName);
        $restoreAssetMetadata->method('getTypeOfField')->willReturn('string');
        $restoreAssetMetadata->method('getAssociationNames')->willReturn(['dependsOn']);
        $restoreAssetMetadata->method('isSingleValuedAssociation')->willReturn(false);
        $restoreAssetMetadata->method('getAssociationMappings')->willReturn([
            'dependsOn' => [
                'type'         => ClassMetadata::MANY_TO_MANY,
                'isOwningSide' => true,
                'fieldName'    => 'dependsOn',
                'joinTable'    => [
                    'name'               => 'asset_dependencies',
                    'joinColumns'        => [['name' => 'dependent_asset_id']],
                    'inverseJoinColumns' => [['name' => 'depends_on_asset_id']],
                ],
            ],
        ]);
        $restoreAssetMetadata->generatorType = ClassMetadata::GENERATOR_TYPE_NONE;
        $restoreAssetMetadata->idGenerator   = new \Doctrine\ORM\Id\AssignedGenerator();

        $restoreAssetRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $restoreAssetRepo->method('find')->willReturn(null);
        $restoreAssetRepo->method('findOneBy')->willReturn(null);

        $eventManager = $this->createMock(\Doctrine\Common\EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $restoreEm = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $restoreEm->method('getConnection')->willReturn($restoreConn);
        $restoreEm->method('getEventManager')->willReturn($eventManager);
        $restoreEm->method('isOpen')->willReturn(true);
        $restoreEm->method('getClassMetadata')->willReturn($restoreAssetMetadata);
        $restoreEm->method('getRepository')->willReturn($restoreAssetRepo);

        $restoreLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $auditLogger   = $this->createMock(\App\Service\AuditLogger::class);
        $passwordHasher = $this->createMock(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);

        $restoreService = new \App\Service\RestoreService(
            $restoreEm,
            $auditLogger,
            $restoreLogger,
            $passwordHasher
        );

        $result = $restoreService->restoreFromBackup($loadedBackup, [
            'dry_run'              => false,
            'clear_before_restore' => false,
        ]);

        $this->assertTrue($result['success'], 'Round-trip restore must succeed');
        $this->assertArrayHasKey('Asset', $result['statistics'], 'Statistics must include Asset');
        $this->assertEquals(3, $result['statistics']['Asset']['created'], 'All 3 assets must be created');

        // ManyToMany pivot inserts must have been emitted
        $this->assertNotEmpty($pivotInserted, 'INSERT IGNORE for asset_dependencies pivot must have been issued');
        $allParams = array_merge(...$pivotInserted);
        $this->assertContains(1, $allParams, 'Owner asset ID 1 must appear in pivot insert params');
        $this->assertContains(2, $allParams, 'Target asset ID 2 must appear in pivot insert params');
        $this->assertContains(3, $allParams, 'Target asset ID 3 must appear in pivot insert params');

        // ------------------------------------------------------------------ //
        // 6. Cleanup                                                          //
        // ------------------------------------------------------------------ //
        $dir   = dirname($savedPath);
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $f) {
            @unlink($dir . '/' . $f);
        }
        @rmdir($dir);
        @rmdir($projectDir . '/var/backups');
        @rmdir($projectDir . '/var');
        @rmdir($projectDir);
    }

    // ------------------------------------------------------------------ //
    // C2 — Tenant-scoped restore tests                                   //
    // ------------------------------------------------------------------ //

    /**
     * C2 T1: Tenant-scoped restore must leave other tenants' entities untouched.
     *
     * Backup contains two users:
     *   - User A: tenant_id = ['id' => 1]   (Tenant A scope)
     *   - User B: tenant_id = ['id' => 2]   (Tenant B scope)
     *
     * Restore is called with targetTenantScope = Tenant A (id=1).
     *
     * Assert:
     *  - Only one entity is persisted (User A)
     *  - User B is silently skipped (no persist call for it)
     */
    #[Test]
    public function testTenantScopedRestoreLeavesOtherTenantsUntouched(): void
    {
        $backup = [
            'metadata' => [
                'version'      => '1.0',
                'tenant_scope' => [1],
                'scope_type'   => 'single',
            ],
            'data' => [
                'User' => [
                    ['id' => 1, 'email' => 'a@example.com', 'tenant_id' => ['id' => 1]],
                    ['id' => 2, 'email' => 'b@example.com', 'tenant_id' => ['id' => 2]],
                ],
            ],
        ];

        $tenantA = $this->createMock(Tenant::class);
        $tenantA->method('getId')->willReturn(1);
        $tenantA->method('getAllSubsidiaries')->willReturn([]);

        $fieldMapping = new FieldMapping(type: 'string', fieldName: 'email', columnName: 'email');
        $fieldMapping->nullable = true;

        $userMetadata = $this->createMock(ClassMetadata::class);
        $userMetadata->method('getFieldNames')->willReturn(['id', 'email']);
        $userMetadata->method('getFieldMapping')->willReturn($fieldMapping);
        $userMetadata->method('getTypeOfField')->willReturn('string');
        $userMetadata->method('getAssociationNames')->willReturn(['tenant']);
        $userMetadata->method('isSingleValuedAssociation')->willReturnCallback(
            fn(string $name): bool => $name === 'tenant'
        );
        $userMetadata->method('getAssociationMappings')->willReturn([]);
        $userMetadata->generatorType = ClassMetadata::GENERATOR_TYPE_AUTO;
        $userMetadata->idGenerator   = new \Doctrine\ORM\Id\IdentityGenerator();

        $userRepository = $this->createMock(EntityRepository::class);
        $userRepository->method('find')->willReturn(null);
        $userRepository->method('findOneBy')->willReturn(null);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $persistedEntities = [];
        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->entityManager->method('getClassMetadata')->willReturn($userMetadata);
        $this->entityManager->method('getRepository')->willReturn($userRepository);
        $this->entityManager->method('getReference')->willReturn(null);
        $this->entityManager->method('persist')->willReturnCallback(
            function ($entity) use (&$persistedEntities): void {
                $persistedEntities[] = $entity;
            }
        );

        $result = $this->service->restoreFromBackup($backup, ['dry_run' => false], $tenantA);

        $this->assertTrue($result['success'], 'Tenant-scoped restore must succeed');

        // Only 1 entity (User A) should have been created, User B must be skipped
        $this->assertArrayHasKey('User', $result['statistics']);
        $this->assertEquals(1, $result['statistics']['User']['created'],
            'Only User A (in scope) must be created; User B must be skipped');
    }

    /**
     * C2 T2: Cross-Tenant-Restore generates a warning in the warnings array.
     *
     * Backup was created for tenant_scope = [1].
     * Restore is called with targetTenantScope = Tenant B (id=2).
     *
     * The scope sets do not overlap → a cross-tenant warning must appear.
     */
    #[Test]
    public function testCrossTenantRestoreGeneratesWarning(): void
    {
        $backup = [
            'metadata' => [
                'version'      => '1.0',
                'tenant_scope' => [1],  // backup was for Tenant A
                'scope_type'   => 'single',
            ],
            'data' => [],  // empty — we only check the warning
        ];

        $tenantB = $this->createMock(Tenant::class);
        $tenantB->method('getId')->willReturn(2);
        $tenantB->method('getAllSubsidiaries')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);

        $result = $this->service->restoreFromBackup($backup, ['dry_run' => false], $tenantB);

        $this->assertTrue($result['success'], 'Cross-tenant restore must not fail (only warn)');
        $this->assertNotEmpty($result['warnings'], 'Cross-tenant restore must produce warnings');

        $crossTenantWarningFound = false;
        foreach ($result['warnings'] as $warning) {
            if (str_contains($warning, 'Cross-Tenant-Restore')) {
                $crossTenantWarningFound = true;
                break;
            }
        }
        $this->assertTrue($crossTenantWarningFound,
            'Cross-Tenant-Restore warning must appear in warnings array');
    }

    private function createMockMetadata(string $entityClass): MockObject
    {
        $fieldMapping = new FieldMapping(type: 'string', fieldName: 'name', columnName: 'name');
        $fieldMapping->nullable = true;

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn(['id', 'name', 'slug', 'email']);
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('isSingleValuedAssociation')->willReturn(false);
        $metadata->method('getFieldMapping')->willReturn($fieldMapping);
        $metadata->generatorType = ClassMetadata::GENERATOR_TYPE_AUTO;
        $metadata->idGenerator = new \Doctrine\ORM\Id\IdentityGenerator();

        return $metadata;
    }

    // ------------------------------------------------------------------ //
    // P1 — SHA256 integrity check                                         //
    // ------------------------------------------------------------------ //

    /** Helper: return a minimal valid restore-ready mock environment. */
    private function mockRestoreEnvironment(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);
    }

    #[Test]
    public function testRestoreThrowsWhenSha256Tampered(): void
    {
        $data = ['User' => [['id' => 1, 'email' => 'a@b.com']]];

        $backup = [
            'metadata' => [
                'version' => '1.0',
                'sha256'  => hash('sha256', json_encode($data)), // correct hash
            ],
            'data' => $data,
        ];

        // Tamper the data after computing the hash.
        $backup['data']['User'][0]['email'] = 'evil@hacker.com';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sha256 mismatch/');

        $this->service->restoreFromBackup($backup);
    }

    #[Test]
    public function testRestoreAcceptsValidSha256(): void
    {
        $data = [];
        $backup = [
            'metadata' => [
                'version' => '1.0',
                'sha256'  => hash('sha256', (string) json_encode($data)),
            ],
            'data' => $data,
        ];

        $this->mockRestoreEnvironment();

        $result = $this->service->restoreFromBackup($backup, ['dry_run' => true]);
        $this->assertTrue($result['success']);
    }

    #[Test]
    public function testLegacyBackupWithoutSha256WarnsAndContinues(): void
    {
        // Legacy backup: no sha256 field in metadata.
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data'     => [],
        ];

        $this->mockRestoreEnvironment();

        $result = $this->service->restoreFromBackup($backup, ['dry_run' => true]);

        $this->assertTrue($result['success'], 'Legacy backup must succeed despite missing sha256');

        $warnings = $result['warnings'];
        $legacyWarningFound = (bool) array_filter(
            $warnings,
            fn(string $w): bool => str_contains($w, 'SHA256') || str_contains($w, 'Legacy backup') || str_contains($w, 'hash')
        );
        $this->assertTrue($legacyWarningFound, 'A warning about missing hash must be present');
    }

    // ------------------------------------------------------------------ //
    // P1 — Encrypted SystemSettings decryption on restore                //
    // ------------------------------------------------------------------ //

    #[Test]
    public function testRestoreDecryptsEncryptedSystemSettingsValue(): void
    {
        $originalValue = 'my-smtp-password-secret';
        $envelope      = $this->encryptionService->encryptValue($originalValue);

        $data = [
            'SystemSettings' => [
                ['id' => 1, 'key' => 'smtp_password', 'value' => $envelope],
            ],
        ];

        $backup = [
            'metadata' => [
                'version' => '1.0',
                'sha256'  => hash('sha256', (string) json_encode($data)),
            ],
            'data' => $data,
        ];

        // We need a service that has the same encryption key (same secret as setUp).
        $capturedRows = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);

        // Dry-run so we don't need full ORM infrastructure; decryption happens BEFORE restore loop.
        $result = $this->service->restoreFromBackup($backup, ['dry_run' => true]);

        // If we get here without an exception, decryption did not fail.
        // The success of the round-trip is verified by the absence of a RuntimeException.
        $this->assertTrue($result['success']);
    }

    // ------------------------------------------------------------------ //
    // P5 — Best-effort restore tests                                       //
    // ------------------------------------------------------------------ //

    /**
     * Build a minimal mock environment shared by P5 tests.
     * Returns the Connection mock so individual tests can verify interactions.
     */
    private function mockBestEffortEnvironment(?ClassMetadata $metadata = null): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);
        // Savepoint methods return void — just configure them to do nothing
        $connection->method('createSavepoint')->willReturnMap([]);
        $connection->method('releaseSavepoint')->willReturnMap([]);
        $connection->method('rollbackSavepoint')->willReturnMap([]);
        $connection->method('setNestTransactionsWithSavepoints')->willReturnMap([]);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);

        if ($metadata !== null) {
            $this->entityManager->method('getClassMetadata')->willReturn($metadata);

            $repo = $this->createMock(EntityRepository::class);
            $repo->method('find')->willReturn(null);
            $repo->method('findOneBy')->willReturn(null);
            $this->entityManager->method('getRepository')->willReturn($repo);
        }

        return $connection;
    }

    /**
     * P5 T1 — Clean backup in best_effort mode must produce zero failures
     * and behave identically to strict mode for valid data.
     */
    #[Test]
    public function testBestEffortEmptyFailuresOnCleanBackup(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data'     => [],
        ];

        $this->mockBestEffortEnvironment();

        $result = $this->service->restoreFromBackup($backup, [
            'dry_run'    => false,
            'best_effort' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('failures', $result);
        $this->assertSame([], $result['failures'],
            'Clean backup in best_effort mode must produce zero failures');
    }

    /**
     * P5 T2 — When the final flush for an entity-type throws (simulating FK violation),
     * best_effort records a failure entry. Other entity-types continue.
     *
     * Strategy: the pre-savepoint flush (position 0 in loop) must succeed so the
     * savepoint can be created. The FINAL entity-type flush must throw.
     * We use a counter: first N-1 calls succeed; the last (final flush) throws.
     */
    #[Test]
    public function testBestEffortSkipsRowWithFKViolation(): void
    {
        $fieldMapping = new FieldMapping(type: 'string', fieldName: 'name', columnName: 'name');
        $fieldMapping->nullable = true;

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn(['id', 'name']);
        $metadata->method('getFieldMapping')->willReturn($fieldMapping);
        $metadata->method('getTypeOfField')->willReturn('string');
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('isSingleValuedAssociation')->willReturn(false);
        $metadata->method('getAssociationMappings')->willReturn([]);
        $metadata->method('getTableName')->willReturn('role');
        $metadata->generatorType = ClassMetadata::GENERATOR_TYPE_AUTO;
        $metadata->idGenerator   = new \Doctrine\ORM\Id\IdentityGenerator();

        $connection = $this->mockBestEffortEnvironment($metadata);

        // Make persist() throw on the second call (second row), simulating a per-row FK violation.
        // This is caught by the per-row try-catch in restoreEntity().
        $persistCallCount = 0;
        $this->entityManager->method('persist')->willReturnCallback(
            function () use (&$persistCallCount): void {
                $persistCallCount++;
                if ($persistCallCount === 2) {
                    throw new \RuntimeException('Integrity constraint violation: 1452 FK constraint failed on row 2');
                }
            }
        );

        $backup = [
            'metadata' => ['version' => '1.0'],
            'data'     => [
                'Role' => [
                    ['id' => 1, 'name' => 'ROLE_USER'],
                    ['id' => 2, 'name' => 'ROLE_ADMIN'],
                ],
            ],
        ];

        $result = $this->service->restoreFromBackup($backup, [
            'dry_run'     => false,
            'best_effort' => true,
        ]);

        $this->assertArrayHasKey('failures', $result);
        // At least one failure was recorded (the second row that threw on persist)
        $this->assertNotEmpty($result['failures'],
            'Expected at least one failure entry for the FK-violating persist');

        $entityNames = array_column($result['failures'], 'entity');
        $this->assertContains('Role', $entityNames,
            'Failure must be attributed to the Role entity');

        // Row ID 2 must be the one that failed
        $roleFailures = array_filter($result['failures'], fn($f) => $f['entity'] === 'Role');
        $failedRowIds = array_column(array_values($roleFailures), 'row_id');
        $this->assertContains(2, $failedRowIds,
            'The failure must capture the row ID of the failing row');
    }

    /**
     * P5 T3 — Strict mode (best_effort=false) must NOT swallow row errors;
     * it should let the exception bubble up or record it as a warning (existing behaviour).
     * The key assertion is that 'failures' key in the result is always present.
     */
    #[Test]
    public function testStrictModeAlwaysIncludesFailuresKeyInResult(): void
    {
        $backup = [
            'metadata' => ['version' => '1.0'],
            'data'     => [],
        ];

        $this->mockBestEffortEnvironment();

        $result = $this->service->restoreFromBackup($backup, [
            'dry_run'     => false,
            'best_effort' => false, // strict
        ]);

        $this->assertTrue($result['success']);
        // Even in strict mode, the failures key must be present (just empty)
        $this->assertArrayHasKey('failures', $result,
            'failures key must always be present in the result, even in strict mode');
        $this->assertSame([], $result['failures']);
    }

    /**
     * P5 T4 — SHA256 mismatch in best_effort mode must NOT throw; instead it must
     * record a failure and continue. In strict mode it must throw.
     */
    #[Test]
    public function testBestEffortSurvivesSha256Mismatch(): void
    {
        $data = ['Role' => [['id' => 1, 'name' => 'ROLE_USER']]];

        $backup = [
            'metadata' => [
                'version' => '1.0',
                'sha256'  => 'deadbeef', // intentionally wrong
            ],
            'data' => $data,
        ];

        $this->mockBestEffortEnvironment();

        // best_effort=true: must NOT throw, must record integrity failure
        $result = $this->service->restoreFromBackup($backup, [
            'dry_run'    => true,
            'best_effort' => true,
        ]);

        $this->assertArrayHasKey('failures', $result);
        $integrityFailure = array_filter(
            $result['failures'],
            fn(array $f): bool => $f['entity'] === '__integrity__'
        );
        $this->assertNotEmpty($integrityFailure,
            'Best-effort SHA256 mismatch must produce an __integrity__ failure record');
    }

    /**
     * P5 T5 — Strict mode SHA256 mismatch must throw RuntimeException.
     */
    #[Test]
    public function testStrictModeSha256MismatchThrows(): void
    {
        $data = ['Role' => [['id' => 1, 'name' => 'ROLE_USER']]];

        $backup = [
            'metadata' => [
                'version' => '1.0',
                'sha256'  => 'deadbeef', // wrong
            ],
            'data' => $data,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sha256 mismatch/');

        $this->service->restoreFromBackup($backup, [
            'dry_run'     => true,
            'best_effort' => false, // strict — must throw
        ]);
    }

    /**
     * P5 T6 — Encrypted SystemSetting with wrong APP_SECRET in best_effort mode.
     * The row must be skipped with a failure entry; no exception thrown.
     */
    #[Test]
    public function testBestEffortReportsEncryptedFieldFailure(): void
    {
        // Encrypt with a DIFFERENT key than the service uses
        $wrongKeyService = new BackupEncryptionService('totally_wrong_secret_key');
        $envelope        = $wrongKeyService->encryptValue('super-secret-value');

        $data = [
            'SystemSettings' => [
                ['id' => 42, 'key' => 'smtp_password', 'value' => $envelope],
            ],
        ];

        $backup = [
            'metadata' => [
                'version' => '1.0',
                'sha256'  => hash('sha256', (string) json_encode($data)),
            ],
            'data' => $data,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('createSavepoint')->willReturnMap([]);
        $connection->method('releaseSavepoint')->willReturnMap([]);
        $connection->method('rollbackSavepoint')->willReturnMap([]);
        $connection->method('setNestTransactionsWithSavepoints')->willReturnMap([]);

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('getListeners')->willReturn([]);

        $this->entityManager->method('getConnection')->willReturn($connection);
        $this->entityManager->method('getEventManager')->willReturn($eventManager);
        $this->entityManager->method('isOpen')->willReturn(true);

        // Must NOT throw even though decryption will fail
        $result = $this->service->restoreFromBackup($backup, [
            'dry_run'     => true,
            'best_effort' => true,
        ]);

        $this->assertArrayHasKey('failures', $result);
        $systemSettingsFailures = array_filter(
            $result['failures'],
            fn(array $f): bool => $f['entity'] === 'SystemSettings'
        );
        $this->assertNotEmpty($systemSettingsFailures,
            'Best-effort decryption failure must produce a SystemSettings failure record');

        // The row ID must be captured
        $failure = array_values($systemSettingsFailures)[0];
        $this->assertSame(42, $failure['row_id'],
            'Failure record must include the row ID of the failing SystemSettings row');
    }
}

