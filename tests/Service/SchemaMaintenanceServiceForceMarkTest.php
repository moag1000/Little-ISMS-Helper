<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\SchemaHealthService;
use App\Service\SchemaMaintenanceService;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsSet;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\MigrationsRepository;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchemaMaintenanceService::markMigrationAsExecuted().
 *
 * Uses concrete Doctrine Migrations value-objects (AvailableMigration,
 * ExecutedMigration, Version) which are final and cannot be mocked.
 * AbstractMigration is mocked because it is abstract.
 *
 * Covers:
 * - Unknown version (not in file-system list) → success=false
 * - Already-executed version (idempotent) → success=true, no INSERT
 * - Valid pending version → success=true, INSERT called on connection
 * - Connection error → success=false with error message
 */
class SchemaMaintenanceServiceForceMarkTest extends TestCase
{
    private const VERSION_STRING = 'App\\Migrations\\Version20991231000000';

    private MockObject&SchemaHealthService $schemaHealthService;
    private MockObject&DependencyFactory $dependencyFactory;
    private MockObject&AuditLogger $auditLogger;
    private MockObject&MigrationsRepository $migrationRepository;
    private MockObject&MetadataStorage $metadataStorage;
    private MockObject&Connection $connection;
    private SchemaMaintenanceService $service;

    protected function setUp(): void
    {
        $this->schemaHealthService = $this->createMock(SchemaHealthService::class);
        $this->dependencyFactory = $this->createMock(DependencyFactory::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->migrationRepository = $this->createMock(MigrationsRepository::class);
        $this->metadataStorage = $this->createMock(MetadataStorage::class);
        $this->connection = $this->createMock(Connection::class);

        $this->dependencyFactory
            ->method('getMigrationRepository')
            ->willReturn($this->migrationRepository);
        $this->dependencyFactory
            ->method('getMetadataStorage')
            ->willReturn($this->metadataStorage);
        $this->dependencyFactory
            ->method('getConnection')
            ->willReturn($this->connection);

        $this->service = new SchemaMaintenanceService(
            $this->schemaHealthService,
            $this->dependencyFactory,
            $this->auditLogger,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeAvailableSet(string ...$versionStrings): AvailableMigrationsSet
    {
        $items = [];
        foreach ($versionStrings as $vs) {
            // AbstractMigration is abstract — createMock() creates a concrete sub-class.
            $abstractMigration = $this->createMock(AbstractMigration::class);
            $items[] = new AvailableMigration(new Version($vs), $abstractMigration);
        }
        return new AvailableMigrationsSet($items);
    }

    private function makeExecutedList(string ...$versionStrings): ExecutedMigrationsList
    {
        $items = [];
        foreach ($versionStrings as $vs) {
            $items[] = new ExecutedMigration(new Version($vs));
        }
        return new ExecutedMigrationsList($items);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function markMigrationAsExecuted_withUnknownVersion_returnsFailure(): void
    {
        // Arrange: file system has no migrations
        $this->migrationRepository
            ->method('getMigrations')
            ->willReturn($this->makeAvailableSet());

        $this->metadataStorage->method('ensureInitialized');

        // Act
        $result = $this->service->markMigrationAsExecuted(self::VERSION_STRING);

        // Assert
        self::assertFalse($result['success']);
        self::assertStringContainsString('not found in migration list', (string) $result['error']);
        self::assertSame(self::VERSION_STRING, $result['version']);
    }

    #[Test]
    public function markMigrationAsExecuted_withAlreadyExecutedVersion_returnsSuccessWithoutInsert(): void
    {
        // Arrange: version exists in fs + already in executed list
        $this->migrationRepository
            ->method('getMigrations')
            ->willReturn($this->makeAvailableSet(self::VERSION_STRING));

        $this->metadataStorage
            ->method('getExecutedMigrations')
            ->willReturn($this->makeExecutedList(self::VERSION_STRING));

        $this->metadataStorage->method('ensureInitialized');

        // INSERT must NOT be called
        $this->connection
            ->expects(self::never())
            ->method('insert');

        // Act
        $result = $this->service->markMigrationAsExecuted(self::VERSION_STRING);

        // Assert
        self::assertTrue($result['success']);
        self::assertNull($result['error']);
        self::assertSame(self::VERSION_STRING, $result['version']);
    }

    #[Test]
    public function markMigrationAsExecuted_withValidPendingVersion_insertsAndReturnsSuccess(): void
    {
        // Arrange: version on fs, NOT yet executed
        $this->migrationRepository
            ->method('getMigrations')
            ->willReturn($this->makeAvailableSet(self::VERSION_STRING));

        $this->metadataStorage
            ->method('getExecutedMigrations')
            ->willReturn($this->makeExecutedList());

        $this->metadataStorage->method('ensureInitialized');

        // INSERT must be called with correct data
        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'doctrine_migration_versions',
                self::callback(static function (array $data): bool {
                    return $data['version'] === self::VERSION_STRING
                        && isset($data['executed_at'])
                        && $data['execution_time'] === 0;
                }),
            );

        // Audit log must fire with correct event name
        $this->auditLogger
            ->expects(self::once())
            ->method('logCustom')
            ->with(
                'admin.schema.force_mark_executed',
                self::anything(),
                self::anything(),
                self::anything(),
                self::callback(static fn (array $ctx): bool => isset($ctx['version']) && $ctx['version'] === self::VERSION_STRING),
                self::anything(),
            );

        // Act
        $result = $this->service->markMigrationAsExecuted(self::VERSION_STRING);

        // Assert
        self::assertTrue($result['success']);
        self::assertNull($result['error']);
        self::assertSame(self::VERSION_STRING, $result['version']);
    }

    #[Test]
    public function markMigrationAsExecuted_whenConnectionThrows_returnsFailure(): void
    {
        // Arrange: version on fs, not executed — but DB write fails
        $this->migrationRepository
            ->method('getMigrations')
            ->willReturn($this->makeAvailableSet(self::VERSION_STRING));

        $this->metadataStorage
            ->method('getExecutedMigrations')
            ->willReturn($this->makeExecutedList());

        $this->metadataStorage->method('ensureInitialized');

        $this->connection
            ->method('insert')
            ->willThrowException(new \RuntimeException('Simulated DB error'));

        // Act
        $result = $this->service->markMigrationAsExecuted(self::VERSION_STRING);

        // Assert
        self::assertFalse($result['success']);
        self::assertStringContainsString('Simulated DB error', (string) $result['error']);
        self::assertSame(self::VERSION_STRING, $result['version']);
    }
}
