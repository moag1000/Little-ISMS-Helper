<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\SchemaHealthService;
use App\Service\SchemaMaintenanceService;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SchemaMaintenanceServiceVerifyCleanTest extends TestCase
{
    private function make(SchemaHealthService $health): SchemaMaintenanceService
    {
        return new SchemaMaintenanceService(
            $health,
            $this->createMock(DependencyFactory::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(ManagerRegistry::class),
        );
    }

    #[Test]
    public function okWhenNoPendingAndNoDrift(): void
    {
        $health = $this->createMock(SchemaHealthService::class);
        $health->method('listPendingMigrationVersions')->willReturn([]);
        $health->method('validate')->willReturn([
            'mapping_in_sync' => true, 'database_in_sync' => true, 'mapping_errors' => [],
            'pending_sql' => [], 'pending_migrations' => [], 'overall_status' => 'healthy',
        ]);

        self::assertSame(
            ['migrations_up_to_date' => true, 'drift_empty' => true, 'ok' => true],
            $this->make($health)->verifyClean(),
        );
    }

    #[Test]
    public function notOkWhenDriftRemains(): void
    {
        $health = $this->createMock(SchemaHealthService::class);
        $health->method('listPendingMigrationVersions')->willReturn([]);
        $health->method('validate')->willReturn([
            'mapping_in_sync' => true, 'database_in_sync' => false, 'mapping_errors' => [],
            'pending_sql' => ['ALTER TABLE a ADD COLUMN b INT'], 'pending_migrations' => [], 'overall_status' => 'warning',
        ]);

        $result = $this->make($health)->verifyClean();
        self::assertFalse($result['ok']);
        self::assertFalse($result['drift_empty']);
    }
}
