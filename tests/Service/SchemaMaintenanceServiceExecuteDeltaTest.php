<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\SchemaHealthService;
use App\Service\SchemaMaintenanceService;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Version;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * executePendingMigrations() must report the number ACTUALLY executed (the
 * before/after delta in doctrine_migration_versions), not the planned count.
 */
#[AllowMockObjectsWithoutExpectations]
class SchemaMaintenanceServiceExecuteDeltaTest extends TestCase
{
    #[Test]
    public function executedCountReflectsRealDeltaOnPartialFailure(): void
    {
        $storage = $this->createMock(MetadataStorage::class);
        // before: 0 executed; after: 1 executed (only the first of two landed)
        $storage->method('getExecutedMigrations')->willReturnOnConsecutiveCalls(
            new ExecutedMigrationsList([]),
            new ExecutedMigrationsList([new ExecutedMigration(new Version('App\\Migrations\\VersionA'))]),
            new ExecutedMigrationsList([new ExecutedMigration(new Version('App\\Migrations\\VersionA'))]),
        );

        $df = $this->createMock(DependencyFactory::class);
        $df->method('getMetadataStorage')->willReturn($storage);
        $service = $this->makeServiceWithFailingMigrate($df, 'SQLSTATE[23000] foreign key');

        $result = $service->executePendingMigrations('test');

        self::assertFalse($result['success']);
        self::assertSame(1, $result['executed'], 'must reflect the 1 version that actually landed');
    }

    private function makeServiceWithFailingMigrate(DependencyFactory $df, string $errorMessage): SchemaMaintenanceService
    {
        // Plan resolves to two versions; migrate() throws mid-way.
        $aliasResolver = $this->createMock(\Doctrine\Migrations\Version\AliasResolver::class);
        $aliasResolver->method('resolveVersionAlias')->willReturn(new Version('App\\Migrations\\VersionB'));
        $df->method('getVersionAliasResolver')->willReturn($aliasResolver);

        $planB = new \Doctrine\Migrations\Metadata\MigrationPlan(
            new Version('App\\Migrations\\VersionB'),
            $this->makeNoopMigration(),
            \Doctrine\Migrations\Version\Direction::UP,
        );
        $planA = new \Doctrine\Migrations\Metadata\MigrationPlan(
            new Version('App\\Migrations\\VersionA'),
            $this->makeNoopMigration(),
            \Doctrine\Migrations\Version\Direction::UP,
        );
        $planList = new \Doctrine\Migrations\Metadata\MigrationPlanList([$planA, $planB], \Doctrine\Migrations\Version\Direction::UP);

        $calc = $this->createMock(\Doctrine\Migrations\Version\MigrationPlanCalculator::class);
        $calc->method('getPlanUntilVersion')->willReturn($planList);
        $df->method('getMigrationPlanCalculator')->willReturn($calc);

        $migrator = $this->createMock(\Doctrine\Migrations\Migrator::class);
        $migrator->method('migrate')->willThrowException(new \RuntimeException($errorMessage));
        $df->method('getMigrator')->willReturn($migrator);

        // QF-7 advisory lock: withSchemaLock() reads GET_LOCK via the
        // DependencyFactory connection. Wire it so GET_LOCK acquires (1) and the
        // wrapped executePendingMigrations actually runs.
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('fetchOne')->willReturnCallback(static fn (string $sql) => str_contains($sql, 'GET_LOCK') ? 1 : null);
        $conn->method('quote')->willReturn("'quickfix_schema'");
        $conn->method('executeStatement')->willReturn(0);
        $df->method('getConnection')->willReturn($conn);

        return new SchemaMaintenanceService(
            $this->createMock(SchemaHealthService::class),
            $df,
            $this->createMock(AuditLogger::class),
            $this->createMock(ManagerRegistry::class),
        );
    }

    private function makeNoopMigration(): \Doctrine\Migrations\AbstractMigration
    {
        return new class($this->createMock(\Doctrine\DBAL\Connection::class), $this->createMock(\Psr\Log\LoggerInterface::class)) extends \Doctrine\Migrations\AbstractMigration {
            public function up(\Doctrine\DBAL\Schema\Schema $schema): void {}
            public function down(\Doctrine\DBAL\Schema\Schema $schema): void {}
        };
    }
}
