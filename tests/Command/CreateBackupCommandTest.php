<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CreateBackupCommand;
use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\BackupNotifier;
use App\Service\BackupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class CreateBackupCommandTest extends TestCase
{
    private MockObject $backupService;
    private MockObject $tenantRepository;
    private MockObject $backupNotifier;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->backupService    = $this->createMock(BackupService::class);
        $this->tenantRepository = $this->createMock(TenantRepository::class);
        $this->backupNotifier   = $this->createMock(BackupNotifier::class);
        $this->projectDir       = sys_get_temp_dir() . '/create_backup_test_' . uniqid();
        mkdir($this->projectDir . '/var/backups', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    // ------------------------------------------------------------------ //

    public function testNonExistentTenantReturnsFailure(): void
    {
        $this->tenantRepository
            ->method('findOneBy')
            ->willReturn(null);

        $tester = $this->buildTester();
        $tester->execute(['--include-audit-log' => false, '--tenant' => 'nonexistent']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('nonexistent', $tester->getDisplay());
    }

    public function testNonExistentTenantReturnsFailureWithJsonFlag(): void
    {
        $this->tenantRepository
            ->method('findOneBy')
            ->willReturn(null);

        $tester = $this->buildTester();
        $tester->execute(['--include-audit-log' => false, '--tenant' => 'nonexistent', '--json' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('nonexistent', $decoded['error']);
    }

    public function testSuccessfulBackupWritesFileAndOutputsPath(): void
    {
        $backupFilePath = $this->projectDir . '/var/backups/backup_test.json';
        file_put_contents($backupFilePath, json_encode(['metadata' => ['sha256' => 'abc'], 'statistics' => ['Asset' => 5]]));

        $backupData = [
            'metadata'   => ['sha256' => 'abc123def456'],
            'statistics' => ['Asset' => 5, 'Risk' => 3],
            'data'       => [],
        ];

        $this->backupService
            ->method('createBackup')
            ->willReturn($backupData);

        $this->backupService
            ->method('saveBackupToFile')
            ->willReturn($backupFilePath);

        $this->tenantRepository
            ->method('findOneBy')
            ->willReturn(null);

        $tester = $this->buildTester();
        $tester->execute(['--no-include-user-sessions' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('backup_test.json', $tester->getDisplay());
    }

    public function testSuccessfulBackupJsonOutput(): void
    {
        $backupFilePath = $this->projectDir . '/var/backups/backup_json.json';
        file_put_contents($backupFilePath, '{}');

        $backupData = [
            'metadata'   => ['sha256' => 'sha256hash'],
            'statistics' => ['Asset' => 10],
            'data'       => [],
        ];

        $this->backupService->method('createBackup')->willReturn($backupData);
        $this->backupService->method('saveBackupToFile')->willReturn($backupFilePath);
        $this->tenantRepository->method('findOneBy')->willReturn(null);

        $tester = $this->buildTester();
        $tester->execute(['--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertTrue($decoded['success']);
        $this->assertArrayHasKey('path', $decoded);
        $this->assertArrayHasKey('size_bytes', $decoded);
        $this->assertArrayHasKey('entity_count', $decoded);
        $this->assertArrayHasKey('duration_ms', $decoded);
        $this->assertArrayHasKey('sha256', $decoded);
    }

    public function testBackupServiceExceptionReturnsFailure(): void
    {
        $this->backupService
            ->method('createBackup')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $this->tenantRepository->method('findOneBy')->willReturn(null);

        $tester = $this->buildTester();
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('DB connection lost', $tester->getDisplay());
    }

    public function testNotifierCalledOnSuccessWhenFlagProvided(): void
    {
        $backupFilePath = $this->projectDir . '/var/backups/backup_notify.json';
        file_put_contents($backupFilePath, '{}');

        $this->backupService->method('createBackup')->willReturn([
            'metadata'   => ['sha256' => 'x'],
            'statistics' => [],
            'data'       => [],
        ]);
        $this->backupService->method('saveBackupToFile')->willReturn($backupFilePath);
        $this->tenantRepository->method('findOneBy')->willReturn(null);

        $this->backupNotifier
            ->expects($this->once())
            ->method('notifySuccess')
            ->with($this->isArray(), 'admin@example.com');

        $tester = $this->buildTester();
        $tester->execute(['--notify' => 'admin@example.com']);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testNotifierCalledOnFailureWhenFlagProvided(): void
    {
        $this->backupService
            ->method('createBackup')
            ->willThrowException(new \RuntimeException('disk full'));

        $this->tenantRepository->method('findOneBy')->willReturn(null);

        $this->backupNotifier
            ->expects($this->once())
            ->method('notifyFailure')
            ->with($this->isInstanceOf(\RuntimeException::class), 'admin@example.com');

        $tester = $this->buildTester();
        $tester->execute(['--notify' => 'admin@example.com']);

        $this->assertSame(1, $tester->getStatusCode());
    }

    // ------------------------------------------------------------------ //

    private function buildTester(): CommandTester
    {
        $command = new CreateBackupCommand(
            $this->backupService,
            $this->tenantRepository,
            $this->backupNotifier,
            new NullLogger(),
        );

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('app:backup:create'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
