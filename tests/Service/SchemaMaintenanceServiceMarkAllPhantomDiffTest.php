<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\SchemaHealthService;
use App\Service\SchemaMaintenanceService;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsSet;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Migrator;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\MigrationsRepository;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\MigrationPlanCalculator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchemaMaintenanceService::markAllPhantomDiffMigrationsAsExecuted()
 * using Approach C (per-version isolated execution loop).
 *
 * Each pending migration version is executed INDEPENDENTLY via a single-version
 * MigrationPlanCalculator::getPlanForVersions() call. A failure in version N
 * does NOT abort the loop.
 *
 * Per-version outcomes tested:
 *  - Migration executes cleanly → recorded naturally (pending count drops)
 *  - Migration throws phantom-diff error → force-marked via markMigrationAsExecuted()
 *  - Migration throws real error → added to skipped[], loop continues
 *  - Empty pending list → early success return
 *
 * Tests are pure unit tests using PHPUnit mocks — no database required.
 * MigrationPlan is final and cannot be mocked; it is constructed directly with
 * an anonymous AbstractMigration subclass.
 */
#[AllowMockObjectsWithoutExpectations]
class SchemaMaintenanceServiceMarkAllPhantomDiffTest extends TestCase
{
    private const V1 = 'DoctrineMigrations\\Version20991231000001';
    private const V2 = 'DoctrineMigrations\\Version20991231000002';
    private const V3 = 'DoctrineMigrations\\Version20991231000003';
    private const V4 = 'DoctrineMigrations\\Version20991231000004';
    private const V5 = 'DoctrineMigrations\\Version20991231000005';

    // All test-doubles declared as MockObject so we can call ->method() on them.
    // Per-test, only those that actually participate in a call flow are configured.
    // The #[AllowMockObjectsWithoutExpectations] attribute silences PHPUnit 13
    // notices for mocks that are legitimately wired up in setUp but not invoked
    // in every test.
    private MockObject&SchemaHealthService $schemaHealthService;
    private MockObject&DependencyFactory $dependencyFactory;
    private MockObject&AuditLogger $auditLogger;
    private MockObject&MetadataStorage $metadataStorage;
    private MockObject&MigrationPlanCalculator $planCalculator;
    private MockObject&Migrator $migrator;
    private MockObject&Connection $connection;
    private MockObject&MigrationsRepository $migrationRepository;
    private MockObject&ManagerRegistry $managerRegistry;

    protected function setUp(): void
    {
        $this->schemaHealthService  = $this->createMock(SchemaHealthService::class);
        $this->dependencyFactory    = $this->createMock(DependencyFactory::class);
        $this->auditLogger          = $this->createMock(AuditLogger::class);
        $this->metadataStorage      = $this->createMock(MetadataStorage::class);
        $this->planCalculator       = $this->createMock(MigrationPlanCalculator::class);
        $this->migrator             = $this->createMock(Migrator::class);
        $this->connection           = $this->createMock(Connection::class);
        $this->migrationRepository  = $this->createMock(MigrationsRepository::class);

        $this->dependencyFactory->method('getMetadataStorage')->willReturn($this->metadataStorage);
        $this->dependencyFactory->method('getMigrationPlanCalculator')->willReturn($this->planCalculator);
        $this->dependencyFactory->method('getMigrator')->willReturn($this->migrator);
        $this->dependencyFactory->method('getConnection')->willReturn($this->connection);
        $this->dependencyFactory->method('getMigrationRepository')->willReturn($this->migrationRepository);
        $this->metadataStorage->method('ensureInitialized');
        $this->auditLogger->method('logCustom');

        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
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
            $this->managerRegistry,
        );
    }

    /**
     * Build a real MigrationPlanList for a single version.
     * MigrationPlan is final, so we cannot mock it — we construct it directly
     * using an anonymous AbstractMigration subclass as a no-op stub.
     * AbstractMigration::__construct() takes (Connection, LoggerInterface).
     */
    private function makePlanList(string $version): MigrationPlanList
    {
        $migrationStub = new class($this->connection, $this->createMock(\Psr\Log\LoggerInterface::class)) extends AbstractMigration {
            public function up(\Doctrine\DBAL\Schema\Schema $schema): void {}
            public function down(\Doctrine\DBAL\Schema\Schema $schema): void {}
        };

        $plan = new MigrationPlan(new Version($version), $migrationStub, Direction::UP);

        return new MigrationPlanList([$plan], Direction::UP);
    }

    private function makeEmptyExecutedList(): ExecutedMigrationsList
    {
        return new ExecutedMigrationsList([]);
    }

    /**
     * Wire markMigrationAsExecuted() prerequisites so force-marking succeeds:
     * - getMigrationRepository()->getMigrations() returns the given versions
     * - getExecutedMigrations() returns empty (not yet executed)
     * - connection->insert() is available (no-op)
     *
     * @param string[] $versions
     */
    private function stubMarkAsExecutedSuccess(string ...$versions): void
    {
        $items = [];
        foreach ($versions as $v) {
            $migStub = new class($this->connection, $this->createMock(\Psr\Log\LoggerInterface::class)) extends AbstractMigration {
                public function up(\Doctrine\DBAL\Schema\Schema $schema): void {}
                public function down(\Doctrine\DBAL\Schema\Schema $schema): void {}
            };
            $items[] = new AvailableMigration(new Version($v), $migStub);
        }

        $availableSet = new AvailableMigrationsSet($items);
        $this->migrationRepository->method('getMigrations')->willReturn($availableSet);
        $this->metadataStorage->method('getExecutedMigrations')->willReturn($this->makeEmptyExecutedList());
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
        self::assertSame([], $result['skipped']);
        self::assertSame(0, $result['remaining_pending']);
        self::assertNull($result['stopped_at_error']);
    }

    // -------------------------------------------------------------------------
    // Test 2: Migration executes cleanly → not force-marked, pending drops naturally
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_migrationRunsCleanly_notMarkedAndPendingDrops(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V1],  // initial pending scan
                [],          // remaining_pending after clean execution
            );

        $this->planCalculator
            ->method('getPlanForVersions')
            ->willReturn($this->makePlanList(self::V1));

        // Migrator does NOT throw → clean execution
        $this->migrator->method('migrate')->willReturn([]);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([], $result['marked']);   // nothing force-marked
        self::assertSame([], $result['skipped']);
        self::assertSame(0, $result['remaining_pending']);
    }

    // -------------------------------------------------------------------------
    // Test 3: Phantom-diff — "Duplicate column name" (SQLSTATE 42S21) → force-marked
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_phantomDiffDuplicateColumnError_forcedMarked(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V1],
                [],
            );

        $this->planCalculator
            ->method('getPlanForVersions')
            ->willReturn($this->makePlanList(self::V1));

        $this->migrator
            ->method('migrate')
            ->willThrowException(new \RuntimeException("SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'status'"));

        $this->stubMarkAsExecutedSuccess(self::V1);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([self::V1], $result['marked']);
        self::assertSame([], $result['skipped']);
        self::assertSame(0, $result['remaining_pending']);
        self::assertNull($result['stopped_at_error']);
    }

    // -------------------------------------------------------------------------
    // Test 4: Phantom-diff — "Table already exists" SQLSTATE[42S01] → force-marked
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_phantomDiffTableAlreadyExistsError_forcedMarked(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V2],
                [],
            );

        $this->planCalculator
            ->method('getPlanForVersions')
            ->willReturn($this->makePlanList(self::V2));

        $this->migrator
            ->method('migrate')
            ->willThrowException(new \RuntimeException("SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'assets' already exists"));

        $this->stubMarkAsExecutedSuccess(self::V2);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([self::V2], $result['marked']);
        self::assertSame([], $result['skipped']);
    }

    // -------------------------------------------------------------------------
    // Test 5: Real error (FK constraint) → skipped with category, loop continues
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_realFkError_versionSkippedLoopContinues(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V3],
                [self::V3],  // still pending after skip
            );

        $this->planCalculator
            ->method('getPlanForVersions')
            ->willReturn($this->makePlanList(self::V3));

        $this->migrator
            ->method('migrate')
            ->willThrowException(new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails'));

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertFalse($result['success']);
        self::assertSame([], $result['marked']);
        self::assertArrayHasKey(self::V3, $result['skipped']);
        self::assertStringContainsString('foreign_key_constraint', $result['skipped'][self::V3]);
        self::assertSame(1, $result['remaining_pending']);
        self::assertNull($result['stopped_at_error']);
    }

    // -------------------------------------------------------------------------
    // Test 6: Mixed — phantom + clean success + real error
    // V1 phantom → force-marked, V2 runs cleanly, V3 real error → skipped
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_mixedPhantomCleanAndRealError_markedAndSkippedCorrectly(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V1, self::V2, self::V3],
                [self::V3],  // only real-error version remains
            );

        $planV1 = $this->makePlanList(self::V1);
        $planV2 = $this->makePlanList(self::V2);
        $planV3 = $this->makePlanList(self::V3);

        $this->planCalculator
            ->method('getPlanForVersions')
            ->willReturnCallback(static function (array $versions) use ($planV1, $planV2, $planV3): MigrationPlanList {
                $v = (string) $versions[0];
                return match ($v) {
                    self::V1 => $planV1,
                    self::V2 => $planV2,
                    default  => $planV3,
                };
            });

        $this->migrator
            ->method('migrate')
            ->willReturnCallback(function (MigrationPlanList $plan, MigratorConfiguration $cfg): array {
                $items = $plan->getItems();
                $v = (string) $items[0]->getVersion();
                if ($v === self::V1) {
                    throw new \RuntimeException("SQLSTATE[42S21]: Duplicate column name 'tenant_id'");
                }
                if ($v === self::V3) {
                    throw new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: foreign key constraint fails');
                }
                return []; // V2 runs cleanly
            });

        $this->stubMarkAsExecutedSuccess(self::V1, self::V2, self::V3);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertFalse($result['success']);  // V3 skipped → partial
        self::assertSame([self::V1], $result['marked']);
        self::assertArrayHasKey(self::V3, $result['skipped']);
        self::assertStringContainsString('foreign_key_constraint', $result['skipped'][self::V3]);
        self::assertCount(1, $result['skipped']);
        self::assertSame(1, $result['remaining_pending']);
        self::assertNull($result['stopped_at_error']);
    }

    // -------------------------------------------------------------------------
    // Test 7: All phantom-diff → all force-marked, success=true, skipped=[]
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_allPhantomDiff_allMarkedSuccessTrue(): void
    {
        $allVersions = [self::V1, self::V2, self::V3];

        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                $allVersions,
                [],
            );

        $this->planCalculator
            ->method('getPlanForVersions')
            ->willReturnCallback(fn (array $versions): MigrationPlanList => $this->makePlanList((string) $versions[0]));

        $this->migrator
            ->method('migrate')
            ->willThrowException(new \RuntimeException("SQLSTATE[42S21]: Duplicate column name 'x'"));

        $this->stubMarkAsExecutedSuccess(...$allVersions);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertCount(3, $result['marked']);
        self::assertSame($allVersions, $result['marked']);
        self::assertSame([], $result['skipped']);
        self::assertSame(0, $result['remaining_pending']);
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
        self::assertArrayHasKey('skipped', $result);
        self::assertArrayHasKey('remaining_pending', $result);
        self::assertArrayHasKey('stopped_at_error', $result);
        self::assertIsArray($result['marked']);
        self::assertIsArray($result['skipped']);
        self::assertIsInt($result['remaining_pending']);
        self::assertNull($result['stopped_at_error']); // always null in Approach C
    }

    // -------------------------------------------------------------------------
    // Test 9: stopped_at_error is always null even on real errors (backward-compat)
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_stoppedAtErrorAlwaysNullEvenOnRealError(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V1],
                [self::V1],
            );

        $this->planCalculator
            ->method('getPlanForVersions')
            ->willReturn($this->makePlanList(self::V1));

        $this->migrator
            ->method('migrate')
            ->willThrowException(new \RuntimeException('some real unexpected error without known pattern'));

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        // Real error → skipped, but stopped_at_error must remain null (Approach C guarantee)
        self::assertNull($result['stopped_at_error']);
        self::assertArrayHasKey(self::V1, $result['skipped']);
        self::assertFalse($result['success']);
    }
}
