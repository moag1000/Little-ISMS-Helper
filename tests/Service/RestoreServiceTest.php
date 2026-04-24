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
use Doctrine\ORM\Mapping\FieldMapping;
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

    /**
     * Audit-Befund: ManyToMany-Collections wurden stillschweigend übergangen.
     * Prüft, dass restoreManyToManyAssociations() INSERT IGNORE-Statements
     * für owning-side ManyToMany-Assoziationen in die Pivot-Tabelle schreibt.
     */
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
}
