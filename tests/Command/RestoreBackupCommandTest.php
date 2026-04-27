<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RestoreBackupCommand;
use App\Repository\TenantRepository;
use App\Service\BackupService;
use App\Service\RestoreService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class RestoreBackupCommandTest extends TestCase
{
    private MockObject $backupService;
    private MockObject $restoreService;
    private MockObject $tenantRepository;
    private MockObject $logger;
    private string $projectDir;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->backupService    = $this->createMock(BackupService::class);
        $this->restoreService   = $this->createMock(RestoreService::class);
        $this->tenantRepository = $this->createMock(TenantRepository::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->projectDir       = sys_get_temp_dir();

        $command = new RestoreBackupCommand(
            $this->backupService,
            $this->restoreService,
            $this->tenantRepository,
            $this->logger,
            $this->projectDir,
        );

        $application = new Application();
        $application->addCommand($command);

        $this->commandTester = new CommandTester($application->find('app:backup:restore'));
    }

    /**
     * Smoke test: file does not exist → exit 1 with a clear error message.
     */
    #[Test]
    public function testNonexistentFileExitsWithFailure(): void
    {
        $this->commandTester->execute([
            'filepath' => '/absolutely/nonexistent/backup.json',
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode(),
            'Exit code must be 1 when the backup file does not exist');

        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('not found', strtolower($display),
            'Output must mention that the file was not found');
    }

    /**
     * Smoke test: file does not exist, --json flag → exit 1 with JSON error.
     */
    #[Test]
    public function testNonexistentFileJsonOutputExitsWithFailure(): void
    {
        $this->commandTester->execute([
            'filepath' => '/nonexistent/backup.json',
            '--json'   => true,
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());

        $output = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($output);
        $this->assertFalse($output['success']);
        $this->assertArrayHasKey('error', $output);
    }

    /**
     * Unknown tenant code → exit 1.
     */
    #[Test]
    public function testUnknownTenantExitsWithFailure(): void
    {
        $this->tenantRepository->method('findOneBy')->willReturn(null);

        // Create a real temp file so file-not-found is not the failure reason
        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test_');
        file_put_contents($tmpFile, json_encode(['metadata' => ['version' => '1.0'], 'data' => []]));

        $this->commandTester->execute([
            'filepath' => $tmpFile,
            '--tenant' => 'UNKNOWN_CODE',
        ]);

        @unlink($tmpFile);

        $this->assertSame(1, $this->commandTester->getStatusCode(),
            'Exit code must be 1 when the tenant code is not found');
    }

    /**
     * Successful restore → exit 0.
     */
    #[Test]
    public function testSuccessfulRestoreExitsZero(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test_');
        $backupData = ['metadata' => ['version' => '1.0'], 'data' => []];
        file_put_contents($tmpFile, json_encode($backupData));

        $this->backupService->method('loadBackupFromFile')->willReturn($backupData);

        $this->restoreService->method('restoreFromBackup')->willReturn([
            'success'    => true,
            'statistics' => [],
            'warnings'   => [],
            'failures'   => [],
            'dry_run'    => false,
        ]);

        $this->commandTester->execute([
            'filepath' => $tmpFile,
        ]);

        @unlink($tmpFile);

        $this->assertSame(0, $this->commandTester->getStatusCode(),
            'Exit code must be 0 on successful restore');
    }

    /**
     * Best-effort partial restore (some failures) → exit 0.
     */
    #[Test]
    public function testBestEffortPartialRestoreExitsZero(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test_');
        $backupData = ['metadata' => ['version' => '1.0'], 'data' => []];
        file_put_contents($tmpFile, json_encode($backupData));

        $this->backupService->method('loadBackupFromFile')->willReturn($backupData);

        $this->restoreService->method('restoreFromBackup')->willReturn([
            'success'    => true,
            'statistics' => ['Role' => ['created' => 1, 'updated' => 0, 'skipped' => 0, 'errors' => 1]],
            'warnings'   => [],
            'failures'   => [
                [
                    'entity'        => 'Role',
                    'row_index'     => 1,
                    'row_id'        => 5,
                    'error_class'   => 'RuntimeException',
                    'error_message' => 'Integrity constraint violation',
                    'original_data' => ['id' => 5, 'name' => 'ROLE_BROKEN'],
                ],
            ],
            'dry_run' => false,
        ]);

        $this->commandTester->execute([
            'filepath'      => $tmpFile,
            '--best-effort' => true,
        ]);

        @unlink($tmpFile);

        $this->assertSame(0, $this->commandTester->getStatusCode(),
            'Best-effort partial restore must exit 0');

        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Skipped rows', $display,
            'Output must list skipped rows when failures are present');
    }

    /**
     * Dry-run flag is passed through to RestoreService options.
     */
    #[Test]
    public function testDryRunFlagPassedToRestoreService(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test_');
        $backupData = ['metadata' => ['version' => '1.0'], 'data' => []];
        file_put_contents($tmpFile, json_encode($backupData));

        $this->backupService->method('loadBackupFromFile')->willReturn($backupData);

        $capturedOptions = null;
        $this->restoreService->method('restoreFromBackup')
            ->willReturnCallback(function ($backup, $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return [
                    'success'    => true,
                    'statistics' => [],
                    'warnings'   => [],
                    'failures'   => [],
                    'dry_run'    => true,
                ];
            });

        $this->commandTester->execute([
            'filepath'  => $tmpFile,
            '--dry-run' => true,
        ]);

        @unlink($tmpFile);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertTrue($capturedOptions['dry_run'],
            'dry_run must be true when --dry-run flag is passed');
    }

    /**
     * Best-effort flag is passed through to RestoreService options.
     */
    #[Test]
    public function testBestEffortFlagPassedToRestoreService(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test_');
        $backupData = ['metadata' => ['version' => '1.0'], 'data' => []];
        file_put_contents($tmpFile, json_encode($backupData));

        $this->backupService->method('loadBackupFromFile')->willReturn($backupData);

        $capturedOptions = null;
        $this->restoreService->method('restoreFromBackup')
            ->willReturnCallback(function ($backup, $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return [
                    'success'    => true,
                    'statistics' => [],
                    'warnings'   => [],
                    'failures'   => [],
                    'dry_run'    => false,
                ];
            });

        $this->commandTester->execute([
            'filepath'      => $tmpFile,
            '--best-effort' => true,
        ]);

        @unlink($tmpFile);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertTrue($capturedOptions['best_effort'],
            'best_effort must be true when --best-effort flag is passed');
    }

    /**
     * Skip-entities option is parsed and passed as an array.
     */
    #[Test]
    public function testSkipEntitiesOptionParsed(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'restore_test_');
        $backupData = ['metadata' => ['version' => '1.0'], 'data' => []];
        file_put_contents($tmpFile, json_encode($backupData));

        $this->backupService->method('loadBackupFromFile')->willReturn($backupData);

        $capturedOptions = null;
        $this->restoreService->method('restoreFromBackup')
            ->willReturnCallback(function ($backup, $options) use (&$capturedOptions) {
                $capturedOptions = $options;
                return [
                    'success'    => true,
                    'statistics' => [],
                    'warnings'   => [],
                    'failures'   => [],
                    'dry_run'    => false,
                ];
            });

        $this->commandTester->execute([
            'filepath'         => $tmpFile,
            '--skip-entities'  => 'AuditLog,UserSession',
        ]);

        @unlink($tmpFile);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertContains('AuditLog', $capturedOptions['skip_entities']);
        $this->assertContains('UserSession', $capturedOptions['skip_entities']);
    }
}
