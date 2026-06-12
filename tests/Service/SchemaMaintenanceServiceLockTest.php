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
class SchemaMaintenanceServiceLockTest extends TestCase
{
    private function makeServiceWithConnection(\Doctrine\DBAL\Connection $conn): SchemaMaintenanceService
    {
        $df = $this->createMock(DependencyFactory::class);
        $df->method('getConnection')->willReturn($conn);
        return new SchemaMaintenanceService(
            $this->createMock(SchemaHealthService::class),
            $df,
            $this->createMock(AuditLogger::class),
            $this->createMock(ManagerRegistry::class),
        );
    }

    #[Test]
    public function withSchemaLockReturnsBlockedWhenLockBusy(): void
    {
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('fetchOne')->willReturn(0); // GET_LOCK busy

        $service = $this->makeServiceWithConnection($conn);
        $ref = new \ReflectionMethod($service, 'withSchemaLock');
        $result = $ref->invoke($service, fn () => ['success' => true]);

        self::assertSame(['success' => false, 'blocked' => 'locked', 'error' => 'Another schema operation is in progress.'], $result);
    }

    #[Test]
    public function withSchemaLockRunsAndReleasesWhenFree(): void
    {
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('fetchOne')->willReturn(1);   // GET_LOCK acquired
        $conn->method('quote')->willReturn("'quickfix_schema'");
        $released = false;
        $conn->method('executeStatement')->willReturnCallback(function () use (&$released): int { $released = true; return 0; });

        $service = $this->makeServiceWithConnection($conn);
        $ref = new \ReflectionMethod($service, 'withSchemaLock');
        $result = $ref->invoke($service, fn () => ['success' => true, 'executed' => 3]);

        self::assertSame(['success' => true, 'executed' => 3], $result);
        self::assertTrue($released);
    }
}
