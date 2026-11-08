<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuickFixStatusSnapshot;
use App\Service\SchemaMaintenanceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** The open Quick-Fix page must not run a fresh schema scan for every anonymous request. */
final class QuickFixStatusSnapshotTest extends TestCase
{
    #[Test]
    public function anAnonymousReloadReusesTheLastScan(): void
    {
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->expects(self::once())->method('getMaintenanceStatus')->willReturn([]);
        $maintenance->method('listPendingMigrationVersionsFromFileSystem')->willReturn([]);
        $maintenance->method('verifyClean')->willReturn(['ok' => true]);
        $snapshot = new QuickFixStatusSnapshot($maintenance, new ArrayAdapter());

        $snapshot->get(false);
        $snapshot->get(false);
        $snapshot->get(false);
    }

    #[Test]
    public function aPrivilegedViewAlwaysScansAgain(): void
    {
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->expects(self::exactly(2))->method('getMaintenanceStatus')->willReturn([]);
        $maintenance->method('listPendingMigrationVersionsFromFileSystem')->willReturn([]);
        $maintenance->method('verifyClean')->willReturn([]);
        $snapshot = new QuickFixStatusSnapshot($maintenance, new ArrayAdapter());

        $snapshot->get(true);
        $snapshot->get(true);
    }

    #[Test]
    public function aFailedScanIsNotCached(): void
    {
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->expects(self::exactly(2))->method('getMaintenanceStatus')
            ->willThrowException(new \RuntimeException('db down'));
        $snapshot = new QuickFixStatusSnapshot($maintenance, new ArrayAdapter());

        foreach ([1, 2] as $ignored) {
            try {
                $snapshot->get(false);
            } catch (\RuntimeException) {
            }
        }
    }

    #[Test]
    public function anUnreadableMigrationStateCountsAsPending(): void
    {
        $maintenance = $this->createStub(SchemaMaintenanceService::class);
        $maintenance->method('listPendingMigrationVersionsFromFileSystem')
            ->willThrowException(new \RuntimeException('db down'));

        self::assertTrue((new QuickFixStatusSnapshot($maintenance, new ArrayAdapter()))->hasPendingMigrations());
    }
}
