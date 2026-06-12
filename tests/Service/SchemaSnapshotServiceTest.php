<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\BackupService;
use App\Service\SchemaSnapshotService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SchemaSnapshotServiceTest extends TestCase
{
    #[Test]
    public function returnsSkippedWithWarningWhenNoMysqldumpAndLogicalFails(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'host' => 'localhost', 'dbname' => 'x', 'user' => 'u', 'password' => 'p']);
        $backup = $this->createMock(BackupService::class);
        $backup->method('createBackup')->willThrowException(new \RuntimeException('schema broken'));

        $service = new SchemaSnapshotService(
            $conn,
            $backup,
            $this->createMock(AuditLogger::class),
            sys_get_temp_dir() . '/quickfix-test-' . getmypid(),
            mysqldumpBinary: '/nonexistent/mysqldump',
        );

        $result = $service->snapshot('test-reason');

        self::assertSame('skipped', $result['method']);
        self::assertNotNull($result['warning']);
    }

    #[Test]
    public function fallsBackToLogicalWhenMysqldumpMissing(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'dbname' => 'x']);
        $backup = $this->createMock(BackupService::class);
        $backup->method('createBackup')->willReturn(['meta' => [], 'data' => []]);
        $backup->method('saveBackupToFile')->willReturn('/tmp/logical-snap.json');

        $service = new SchemaSnapshotService(
            $conn,
            $backup,
            $this->createMock(AuditLogger::class),
            sys_get_temp_dir() . '/quickfix-test-' . getmypid(),
            mysqldumpBinary: '/nonexistent/mysqldump',
        );

        $result = $service->snapshot('test-reason');

        self::assertSame('logical', $result['method']);
        self::assertSame('/tmp/logical-snap.json', $result['path']);
    }

    #[Test]
    public function skipsEntirelyInTestEnvironment(): void
    {
        // Under the test SAPI the in-request runner executes jobs synchronously,
        // so a real mysqldump/logical export would run inside the test process.
        // The service must short-circuit before touching the connection or backup.
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::never())->method('getParams');
        $backup = $this->createMock(BackupService::class);
        $backup->expects(self::never())->method('createBackup');

        $service = new SchemaSnapshotService(
            $conn,
            $backup,
            $this->createMock(AuditLogger::class),
            sys_get_temp_dir() . '/quickfix-test-' . getmypid(),
            mysqldumpBinary: '/nonexistent/mysqldump',
            environment: 'test',
        );

        $result = $service->snapshot('test-reason');

        self::assertSame('skipped', $result['method']);
        self::assertNull($result['path']);
    }
}
