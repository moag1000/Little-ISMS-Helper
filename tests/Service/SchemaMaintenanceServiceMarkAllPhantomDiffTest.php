<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\SchemaHealthService;
use App\Service\SchemaMaintenanceService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Types\StringType;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsSet;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\MigrationsRepository;
use Doctrine\Migrations\Query\Query;
use Doctrine\Migrations\Version\AliasResolver;
use Doctrine\Migrations\Version\MigrationPlanCalculator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchemaMaintenanceService::markAllPhantomDiffMigrationsAsExecuted()
 * using Approach A (schema-introspection per migration).
 *
 * Each migration's SQL is inspected independently against the live schema:
 * - CREATE TABLE → table must already exist
 * - ADD COLUMN   → column must already exist in table
 * - CREATE INDEX → index must already exist on table
 * - Any unknown/DROP/INSERT/UPDATE → migration is NOT a phantom-diff candidate
 *
 * Tests are pure unit tests using PHPUnit mocks — no database required.
 */
class SchemaMaintenanceServiceMarkAllPhantomDiffTest extends TestCase
{
    private const V1 = 'DoctrineMigrations\\Version20991231000001';
    private const V2 = 'DoctrineMigrations\\Version20991231000002';
    private const V3 = 'DoctrineMigrations\\Version20991231000003';
    private const V4 = 'DoctrineMigrations\\Version20991231000004';
    private const V5 = 'DoctrineMigrations\\Version20991231000005';

    private MockObject&SchemaHealthService $schemaHealthService;
    private MockObject&DependencyFactory $dependencyFactory;
    private MockObject&AuditLogger $auditLogger;
    private MockObject&MigrationsRepository $migrationRepository;
    private MockObject&MetadataStorage $metadataStorage;
    private MockObject&Connection $connection;
    /** @var MockObject&AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform> */
    private MockObject&AbstractSchemaManager $schemaManager;

    protected function setUp(): void
    {
        $this->schemaHealthService = $this->createMock(SchemaHealthService::class);
        $this->dependencyFactory = $this->createMock(DependencyFactory::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->migrationRepository = $this->createMock(MigrationsRepository::class);
        $this->metadataStorage = $this->createMock(MetadataStorage::class);
        $this->connection = $this->createMock(Connection::class);
        $this->schemaManager = $this->createMock(AbstractSchemaManager::class);

        $this->dependencyFactory->method('getMigrationRepository')->willReturn($this->migrationRepository);
        $this->dependencyFactory->method('getMetadataStorage')->willReturn($this->metadataStorage);
        $this->dependencyFactory->method('getConnection')->willReturn($this->connection);
        $this->metadataStorage->method('ensureInitialized');
        $this->connection->method('createSchemaManager')->willReturn($this->schemaManager);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(): SchemaMaintenanceService
    {
        return new SchemaMaintenanceService(
            $this->schemaHealthService,
            $this->dependencyFactory,
            $this->auditLogger,
        );
    }

    /**
     * Create a mock AbstractMigration that returns the given SQL strings from getSql().
     *
     * @param string[] $sqlStatements
     */
    private function makeMigrationWithSql(array $sqlStatements): AbstractMigration
    {
        $migration = $this->getMockBuilder(AbstractMigration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['up', 'getSql'])
            ->getMock();

        $queries = array_map(static fn (string $sql): Query => new Query($sql), $sqlStatements);
        $migration->method('getSql')->willReturn($queries);
        $migration->method('up'); // no-op — getSql() returns pre-loaded queries

        return $migration;
    }

    private function makeAvailableMigration(string $version, AbstractMigration $migration): AvailableMigration
    {
        return new AvailableMigration(new Version($version), $migration);
    }

    private function makeExecutedList(string ...$versionStrings): ExecutedMigrationsList
    {
        $items = [];
        foreach ($versionStrings as $vs) {
            $items[] = new ExecutedMigration(new Version($vs));
        }
        return new ExecutedMigrationsList($items);
    }

    private function makeAvailableSet(string ...$versions): AvailableMigrationsSet
    {
        // We won't use this directly in Approach A (we use getMigration per-version)
        // but it's needed by markMigrationAsExecuted's validation.
        $items = [];
        foreach ($versions as $vs) {
            $items[] = new AvailableMigration(new Version($vs), $this->makeMigrationWithSql([]));
        }
        return new AvailableMigrationsSet($items);
    }

    private function stubColumn(string $name): Column
    {
        return new Column($name, new StringType());
    }

    private function stubIndex(string $name): Index
    {
        return new Index($name, []);
    }

    /**
     * Wire up markMigrationAsExecuted() prerequisites for a version:
     * the migration repository knows the version AND it is not yet executed.
     */
    private function stubMarkAsExecutedSuccess(string ...$versions): void
    {
        $this->migrationRepository
            ->method('getMigrations')
            ->willReturn($this->makeAvailableSet(...$versions));
        $this->metadataStorage
            ->method('getExecutedMigrations')
            ->willReturn($this->makeExecutedList());
        $this->connection->method('insert');
    }

    // -------------------------------------------------------------------------
    // Test 1: Empty pending list → immediate success
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_withNoPendingMigrations_returnsSuccessWithEmptyMarked(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturn([]);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([], $result['marked']);
        self::assertSame(0, $result['remaining_pending']);
        self::assertNull($result['stopped_at_error']);
    }

    // -------------------------------------------------------------------------
    // Test 2: CREATE TABLE targeting an EXISTING table → marked as phantom-diff
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_createTableTargetingExistingTable_markedAsPhantomDiff(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V1],  // initial pending scan
                [],          // remaining_pending final check
            );

        $migration = $this->makeMigrationWithSql([
            'CREATE TABLE IF NOT EXISTS `my_table` (id INT NOT NULL)',
        ]);

        $this->migrationRepository
            ->method('getMigration')
            ->with(new Version(self::V1))
            ->willReturn($this->makeAvailableMigration(self::V1, $migration));

        // Table ALREADY exists in live schema → phantom-diff
        $this->schemaManager->method('tablesExist')->with(['my_table'])->willReturn(true);

        $this->stubMarkAsExecutedSuccess(self::V1);
        $this->auditLogger->method('logCustom');

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([self::V1], $result['marked']);
        self::assertSame(0, $result['remaining_pending']);
        self::assertNull($result['stopped_at_error']);
    }

    // -------------------------------------------------------------------------
    // Test 3: CREATE TABLE targeting a NON-EXISTING table → NOT marked
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_createTableTargetingNonExistingTable_notMarked(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V1],  // pending scan
                [self::V1],  // remaining_pending — still there
            );

        $migration = $this->makeMigrationWithSql([
            'CREATE TABLE `new_table` (id INT NOT NULL)',
        ]);

        $this->migrationRepository
            ->method('getMigration')
            ->with(new Version(self::V1))
            ->willReturn($this->makeAvailableMigration(self::V1, $migration));

        // Table does NOT exist yet → real pending DDL
        $this->schemaManager->method('tablesExist')->with(['new_table'])->willReturn(false);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([], $result['marked']);
        self::assertSame(1, $result['remaining_pending']);
    }

    // -------------------------------------------------------------------------
    // Test 4: ADD COLUMN targeting an existing column → marked
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_addColumnTargetingExistingColumn_markedAsPhantomDiff(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V2],
                [],
            );

        $migration = $this->makeMigrationWithSql([
            'ALTER TABLE `user` ADD COLUMN `phone` VARCHAR(50) DEFAULT NULL',
        ]);

        $this->migrationRepository
            ->method('getMigration')
            ->with(new Version(self::V2))
            ->willReturn($this->makeAvailableMigration(self::V2, $migration));

        // Column 'phone' already exists
        $this->schemaManager
            ->method('listTableColumns')
            ->with('user')
            ->willReturn(['phone' => $this->stubColumn('phone')]);

        $this->stubMarkAsExecutedSuccess(self::V2);
        $this->auditLogger->method('logCustom');

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([self::V2], $result['marked']);
        self::assertNull($result['stopped_at_error']);
    }

    // -------------------------------------------------------------------------
    // Test 5: Mixed migration (phantom CREATE TABLE + DROP table) → NOT marked
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_migrationWithDropStatement_notMarked(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V3],
                [self::V3],
            );

        // CREATE TABLE exists (phantom) BUT also has a DROP → conservative skip
        $migration = $this->makeMigrationWithSql([
            'CREATE TABLE IF NOT EXISTS `my_table` (id INT NOT NULL)',
            'DROP TABLE `old_table`',
        ]);

        $this->migrationRepository
            ->method('getMigration')
            ->with(new Version(self::V3))
            ->willReturn($this->makeAvailableMigration(self::V3, $migration));

        $this->schemaManager->method('tablesExist')->willReturn(true);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([], $result['marked']); // DROP caused conservative skip
        self::assertSame(1, $result['remaining_pending']);
    }

    // -------------------------------------------------------------------------
    // Test 6: Mixed scenario — 3 phantom, 2 non-phantom, 1 mixed → marks exactly 3
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_mixedScenario_marksOnlyPhantomDiffMigrations(): void
    {
        // V1, V2, V3 → phantom (CREATE TABLE targeting existing table)
        // V4          → non-phantom (CREATE TABLE targeting non-existing table)
        // V5          → mixed (has a DROP → conservative skip)
        // V_extra     → ADD COLUMN targeting missing column → not marked

        $vExtra = 'DoctrineMigrations\\Version20991231000006';

        $allPending = [self::V1, self::V2, self::V3, self::V4, self::V5, $vExtra];

        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                $allPending,        // initial scan
                [self::V4, self::V5, $vExtra],  // remaining after marking 3
            );

        // Phantom migrations — table already exists
        $phantomMigration = $this->makeMigrationWithSql([
            'CREATE TABLE IF NOT EXISTS `existing_table` (id INT NOT NULL)',
        ]);

        // Non-phantom — table does NOT exist
        $nonPhantomMigration = $this->makeMigrationWithSql([
            'CREATE TABLE `brand_new_table` (id INT NOT NULL)',
        ]);

        // Mixed — has DROP
        $mixedMigration = $this->makeMigrationWithSql([
            'CREATE TABLE IF NOT EXISTS `existing_table` (id INT NOT NULL)',
            'DROP TABLE `old_table`',
        ]);

        // Extra — ADD COLUMN where column does NOT exist
        $addColumnMissingMigration = $this->makeMigrationWithSql([
            'ALTER TABLE `user` ADD COLUMN `missing_col` VARCHAR(50)',
        ]);

        $this->migrationRepository
            ->method('getMigration')
            ->willReturnCallback(function (Version $v) use (
                $phantomMigration,
                $nonPhantomMigration,
                $mixedMigration,
                $addColumnMissingMigration,
                $vExtra,
            ): AvailableMigration {
                $vs = (string) $v;
                if (in_array($vs, [self::V1, self::V2, self::V3], true)) {
                    return $this->makeAvailableMigration($vs, $phantomMigration);
                }
                if ($vs === self::V4) {
                    return $this->makeAvailableMigration($vs, $nonPhantomMigration);
                }
                if ($vs === self::V5) {
                    return $this->makeAvailableMigration($vs, $mixedMigration);
                }
                return $this->makeAvailableMigration($vs, $addColumnMissingMigration);
            });

        $this->schemaManager
            ->method('tablesExist')
            ->willReturnCallback(static function (array $tables): bool {
                return $tables === ['existing_table'];
            });

        $this->schemaManager
            ->method('listTableColumns')
            ->willReturn([]); // 'missing_col' absent

        $this->stubMarkAsExecutedSuccess(self::V1, self::V2, self::V3, self::V4, self::V5, $vExtra);
        $this->auditLogger->method('logCustom');

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertCount(3, $result['marked']);
        self::assertContains(self::V1, $result['marked']);
        self::assertContains(self::V2, $result['marked']);
        self::assertContains(self::V3, $result['marked']);
        self::assertNotContains(self::V4, $result['marked']);
        self::assertNotContains(self::V5, $result['marked']);
        self::assertNotContains($vExtra, $result['marked']);
        self::assertSame(3, $result['remaining_pending']);
        self::assertNull($result['stopped_at_error']);
    }

    // -------------------------------------------------------------------------
    // Test 7: CREATE INDEX targeting an existing index → marked
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_createIndexTargetingExistingIndex_markedAsPhantomDiff(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V1],
                [],
            );

        $migration = $this->makeMigrationWithSql([
            'CREATE INDEX idx_user_email ON `user` (email)',
        ]);

        $this->migrationRepository
            ->method('getMigration')
            ->willReturn($this->makeAvailableMigration(self::V1, $migration));

        $this->schemaManager
            ->method('listTableIndexes')
            ->with('user')
            ->willReturn(['idx_user_email' => $this->stubIndex('idx_user_email')]);

        $this->stubMarkAsExecutedSuccess(self::V1);
        $this->auditLogger->method('logCustom');

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([self::V1], $result['marked']);
    }

    // -------------------------------------------------------------------------
    // Test 8: Result shape contract — all keys always present
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_alwaysReturnsAllExpectedKeys(): void
    {
        $this->schemaHealthService->method('listPendingMigrationVersions')->willReturn([]);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertArrayHasKey('success', $result);
        self::assertArrayHasKey('marked', $result);
        self::assertArrayHasKey('remaining_pending', $result);
        self::assertArrayHasKey('stopped_at_error', $result);
        self::assertIsArray($result['marked']);
        self::assertIsInt($result['remaining_pending']);
    }
}
