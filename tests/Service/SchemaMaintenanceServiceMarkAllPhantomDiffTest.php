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
 * Unit tests for SchemaMaintenanceService::markAllPhantomDiffMigrationsAsExecuted().
 *
 * The operator confirms (Quick-Fix safety checkbox) that every table/column of
 * the pending migrations already exists, so each pending version is RECORDED as
 * executed via a metadata-only INSERT (markMigrationAsExecuted) and its DDL is
 * NEVER run. The migrator is therefore never invoked.
 *
 * Per-version outcomes tested:
 *  - Version known to the repository → marked without running
 *  - Version unknown to the repository → markMigrationAsExecuted fails → skipped[]
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

        // QF-7 advisory lock: withSchemaLock() acquires GET_LOCK on the
        // DependencyFactory's connection before running the wrapped method.
        // GET_LOCK → 1 (acquired); RELEASE_LOCK tolerated. fetchOne is not used
        // elsewhere in this flow, so the GET_LOCK-aware callback is safe.
        $this->connection
            ->method('fetchOne')
            ->willReturnCallback(static fn (string $sql) => str_contains($sql, 'GET_LOCK') ? 1 : null);
        $this->connection->method('quote')->willReturn("'quickfix_schema'");
        $this->connection->method('executeStatement')->willReturn(0);

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
        $this->stubMarkAsExecutedSuccessWithValidate(null, ...$versions);
    }

    /**
     * Like stubMarkAsExecutedSuccess(), but lets the caller override the
     * validate() payload the post-loop drift probe sees. When $validateReturn
     * is null, a clean (no-drift) payload is used. Per-version marking now uses
     * verify:false, so validate() is only consulted by the post-loop
     * getEntityVsDbDrift() probe.
     *
     * @param array<string, mixed>|null $validateReturn
     * @param string[]                  $versions
     */
    private function stubMarkAsExecutedSuccessWithValidate(?array $validateReturn, string ...$versions): void
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
        // Per-version marking uses verify:false (no validate() during the loop).
        // validate() is consulted only by the post-loop drift probe. Default to
        // a clean payload; callers needing a drift scenario override it.
        $this->schemaHealthService->method('validate')->willReturn($validateReturn ?? [
            'mapping_in_sync' => true,
            'database_in_sync' => true,
            'mapping_errors' => [],
            'pending_sql' => [],
            'pending_migrations' => [],
            'overall_status' => 'healthy',
        ]);
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
    // Test 2: Pending version known to the repository → marked WITHOUT running
    //          its DDL (the migrator is never invoked).
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_pendingVersionInRepository_markedWithoutRunning(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V1],  // initial pending scan
                [],          // remaining_pending after marking
            );

        $this->stubMarkAsExecutedSuccess(self::V1);

        // The operator confirmed all already exist → no DDL must run.
        $this->migrator->expects(self::never())->method('migrate');

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertTrue($result['success']);
        self::assertSame([self::V1], $result['marked']);
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
    // Test 5: Pending version NOT known to the migration repository → marking
    //          fails for it (markMigrationAsExecuted rejects unknown versions),
    //          it is skipped, and the loop continues.
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_versionNotInRepository_skipped(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V3],
                [self::V3],  // still pending after skip
            );

        // Repository knows only V1 — the pending V3 is unknown, so
        // markMigrationAsExecuted returns failure and V3 lands in skipped[].
        $this->stubMarkAsExecutedSuccess(self::V1);

        // Mark-without-running: the migrator is never invoked.
        $this->migrator->expects(self::never())->method('migrate');

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertFalse($result['success']);
        self::assertSame([], $result['marked']);
        self::assertArrayHasKey(self::V3, $result['skipped']);
        self::assertStringContainsString('mark-failed', $result['skipped'][self::V3]);
        self::assertSame(1, $result['remaining_pending']);
        self::assertNull($result['stopped_at_error']);
    }

    // -------------------------------------------------------------------------
    // Test 6: Mixed — known versions (V1, V2) are marked, the unknown version
    //          (V3, not in the repository) is skipped. The loop continues.
    // -------------------------------------------------------------------------

    #[Test]
    public function markAll_mixedKnownAndUnknown_marksKnownSkipsUnknown(): void
    {
        $this->schemaHealthService
            ->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls(
                [self::V1, self::V2, self::V3],
                [self::V3],  // only the unknown version remains pending
            );

        // Repository knows V1 + V2 but NOT V3.
        $this->stubMarkAsExecutedSuccess(self::V1, self::V2);

        // Mark-without-running: the migrator is never invoked.
        $this->migrator->expects(self::never())->method('migrate');

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        self::assertFalse($result['success']);  // V3 skipped → partial
        self::assertSame([self::V1, self::V2], $result['marked']);
        self::assertArrayHasKey(self::V3, $result['skipped']);
        self::assertStringContainsString('mark-failed', $result['skipped'][self::V3]);
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
        self::assertArrayHasKey('post_drift_reconciled', $result);
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

        // Post-loop drift probe consults validate(); give it a clean payload so
        // no reconcile fires (this test exercises the skip/real-error path).
        $this->schemaHealthService->method('validate')->willReturn([
            'mapping_in_sync' => true,
            'database_in_sync' => true,
            'mapping_errors' => [],
            'pending_sql' => [],
            'pending_migrations' => [],
            'overall_status' => 'healthy',
        ]);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted();

        // Real error → skipped, but stopped_at_error must remain null (Approach C guarantee)
        self::assertNull($result['stopped_at_error']);
        self::assertArrayHasKey(self::V1, $result['skipped']);
        self::assertFalse($result['success']);
    }

    // -------------------------------------------------------------------------
    // Test 10 (QF-2): post-loop drift guard → additive reconcile + warn
    // -------------------------------------------------------------------------

    #[Test]
    public function postDriftTriggersReconcileAndWarns(): void
    {
        // listPendingMigrationVersions: initial scan returns 2, remaining after = 0
        $this->schemaHealthService->method('listPendingMigrationVersions')
            ->willReturnOnConsecutiveCalls([self::V1, self::V2], []);

        // Per-version marking uses verify:false → no validate() during the loop.
        // The post-loop getEntityVsDbDrift() probe is the sole validate() caller;
        // arrange it to report additive drift.
        $this->stubMarkAsExecutedSuccessWithValidate(
            [
                'mapping_in_sync' => false,
                'database_in_sync' => false,
                'mapping_errors' => [],
                'pending_sql' => ['ALTER TABLE z ADD COLUMN q INT'],
                'pending_migrations' => [],
                'overall_status' => 'warning',
            ],
            self::V1,
            self::V2,
        );

        // Post-loop additive reconcile must fire exactly once.
        $this->schemaHealthService
            ->expects(self::once())
            ->method('applyUpdate')
            ->with('quick-fix', true, false)
            ->willReturn([
                'success' => true,
                'executed_sql' => ['ALTER TABLE z ADD COLUMN q INT'],
                'sql_hash' => null,
                'error' => null,
                'blocked' => null,
                'dropped_fks' => [],
                'skipped_destructive' => [],
            ]);

        $result = $this->makeService()->markAllPhantomDiffMigrationsAsExecuted('quick-fix');

        self::assertTrue($result['post_drift_reconciled']);
        self::assertSame([self::V1, self::V2], $result['marked']);
    }
}
