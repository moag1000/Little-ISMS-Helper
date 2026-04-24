<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RepairBackupCommand;
use App\Service\BackupRepairService;
use App\Service\RepairReport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke tests for the app:backup:repair console command.
 *
 * Uses a real BackupRepairService instance for the file-not-found case
 * and a mock for the happy-path scenarios to keep tests fast and isolated.
 */
class RepairBackupCommandTest extends TestCase
{
    private string $tmpDir;
    private MockObject $repairService;

    protected function setUp(): void
    {
        $this->tmpDir        = sys_get_temp_dir() . '/repair_cmd_test_' . uniqid('', true);
        $this->repairService = $this->createMock(BackupRepairService::class);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tmpDir);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function buildTester(?BackupRepairService $service = null): CommandTester
    {
        $command = new RepairBackupCommand($service ?? $this->repairService);
        $app     = new Application();
        $app->addCommand($command);
        return new CommandTester($app->find('app:backup:repair'));
    }

    /**
     * Build a minimal recoverable RepairReport for mocking.
     *
     * @param array<string, array{total: int, recovered: int, lost: int, issues: list<string>}> $perEntity
     */
    private function makeRecoverableReport(array $perEntity = []): RepairReport
    {
        $total     = 0;
        $recovered = 0;
        $lost      = 0;
        foreach ($perEntity as $stats) {
            $total     += $stats['total'];
            $recovered += $stats['recovered'];
            $lost      += $stats['lost'];
        }

        return new RepairReport(
            totalEntities:    count($perEntity),
            totalRows:        $total,
            recoveredRows:    $recovered,
            lostRows:         $lost,
            perEntity:        $perEntity,
            metadataIssues:   [],
            recomputedSha256: hash('sha256', 'dummy'),
            isRecoverable:    $recovered > 0,
        );
    }

    private function makeUnrecoverableReport(): RepairReport
    {
        return new RepairReport(
            totalEntities:    0,
            totalRows:        0,
            recoveredRows:    0,
            lostRows:         0,
            perEntity:        [],
            metadataIssues:   ['JSON parse error: syntax error'],
            recomputedSha256: null,
            isRecoverable:    false,
        );
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ==================================================================
    // Test: non-existent file → exit 1 with clear error
    // ==================================================================

    public function testFileNotFoundExitsWithFailure(): void
    {
        // Use a real service here so we test the actual "file not found" branch
        $realService = new BackupRepairService();
        $tester      = $this->buildTester($realService);

        $nonExistentPath = $this->tmpDir . '/does_not_exist.json';

        $tester->execute(['filepath' => $nonExistentPath]);

        $this->assertSame(1, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertTrue(
            str_contains($display, 'not found') || str_contains($display, 'does_not_exist'),
            "Output should mention the missing file, got: {$display}",
        );
    }

    public function testFileNotFoundWithJsonFlagEmitsJsonError(): void
    {
        $realService     = new BackupRepairService();
        $tester          = $this->buildTester($realService);
        $nonExistentPath = $this->tmpDir . '/gone.json';

        $tester->execute(['filepath' => $nonExistentPath, '--json' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('not found', strtolower($decoded['error']));
    }

    // ==================================================================
    // Test: --dry-run reports but does NOT write an output file
    // ==================================================================

    public function testDryRunDoesNotWriteOutputFile(): void
    {
        $inputPath = $this->tmpDir . '/source.json';
        file_put_contents($inputPath, json_encode([
            'metadata' => ['version' => '2.0', 'created_at' => '2026-04-24', 'entities' => 0],
            'data'     => [],
        ]));

        $report = $this->makeRecoverableReport([
            'Asset' => ['total' => 5, 'recovered' => 5, 'lost' => 0, 'issues' => []],
        ]);

        $this->repairService
            ->expects($this->once())
            ->method('analyze')
            ->with($inputPath)
            ->willReturn($report);

        $this->repairService
            ->expects($this->never())
            ->method('repair');

        $tester = $this->buildTester();
        $tester->execute(['filepath' => $inputPath, '--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());

        // The default output path must NOT have been written
        $expectedOutput = $this->tmpDir . '/source.repaired.json';
        $this->assertFileDoesNotExist(
            $expectedOutput,
            'Dry-run must not write any output file',
        );
    }

    // ==================================================================
    // Test: --json mode emits parseable JSON report
    // ==================================================================

    public function testJsonModeEmitsParseableReport(): void
    {
        $inputPath  = $this->tmpDir . '/input.json';
        $outputPath = $this->tmpDir . '/input.repaired.json';
        file_put_contents($inputPath, '{}');   // content doesn't matter — mock handles it

        $perEntity = [
            'Risk' => ['total' => 3, 'recovered' => 2, 'lost' => 1, 'issues' => ['Row #1 is not an array']],
        ];
        $report = $this->makeRecoverableReport($perEntity);

        $this->repairService
            ->method('repair')
            ->willReturn($report);

        $tester = $this->buildTester();
        $tester->execute([
            'filepath'   => $inputPath,
            '--output'   => $outputPath,
            '--json'     => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertNotNull($decoded, 'Output must be valid JSON');
        $this->assertArrayHasKey('totalEntities', $decoded);
        $this->assertArrayHasKey('totalRows', $decoded);
        $this->assertArrayHasKey('recoveredRows', $decoded);
        $this->assertArrayHasKey('lostRows', $decoded);
        $this->assertArrayHasKey('perEntity', $decoded);
        $this->assertArrayHasKey('metadataIssues', $decoded);
        $this->assertArrayHasKey('isRecoverable', $decoded);
        $this->assertTrue($decoded['isRecoverable']);
        $this->assertFalse((bool) $decoded['dry_run']);
        $this->assertSame($outputPath, $decoded['output_path']);
    }

    // ==================================================================
    // Test: unrecoverable backup → exit 1
    // ==================================================================

    public function testUnrecoverableBackupReturnsExitOne(): void
    {
        $inputPath  = $this->tmpDir . '/broken.json';
        $outputPath = $this->tmpDir . '/broken.repaired.json';
        file_put_contents($inputPath, '{ broken json');

        $this->repairService
            ->method('repair')
            ->willReturn($this->makeUnrecoverableReport());

        $tester = $this->buildTester();
        $tester->execute(['filepath' => $inputPath, '--output' => $outputPath]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    // ==================================================================
    // Test: happy-path repair succeeds, exit 0
    // ==================================================================

    public function testSuccessfulRepairExitsZero(): void
    {
        $inputPath  = $this->tmpDir . '/good.json';
        $outputPath = $this->tmpDir . '/good.repaired.json';
        file_put_contents($inputPath, '{}');

        $perEntity = [
            'Asset'    => ['total' => 10, 'recovered' => 10, 'lost' => 0, 'issues' => []],
            'Incident' => ['total' =>  4, 'recovered' =>  3, 'lost' => 1, 'issues' => ['Row #2 empty']],
        ];
        $report = $this->makeRecoverableReport($perEntity);

        $this->repairService
            ->method('repair')
            ->willReturn($report);

        $tester = $this->buildTester();
        $tester->execute(['filepath' => $inputPath, '--output' => $outputPath]);

        $this->assertSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Asset', $display);
        $this->assertStringContainsString('Incident', $display);
    }

    // ==================================================================
    // Test: default output path derivation
    // ==================================================================

    public function testDefaultOutputPathIsDerivedException(): void
    {
        // Test that passing no --output causes the command to derive
        // a path ending in .repaired.json (or .repaired.zip) from the input.
        $inputPath = $this->tmpDir . '/mybackup.json';
        file_put_contents($inputPath, '{}');

        $capturedOutput = null;
        $report         = $this->makeRecoverableReport([
            'Tenant' => ['total' => 1, 'recovered' => 1, 'lost' => 0, 'issues' => []],
        ]);

        $this->repairService
            ->method('repair')
            ->willReturnCallback(function (string $src, string $dst) use (&$capturedOutput, $report): RepairReport {
                $capturedOutput = $dst;
                return $report;
            });

        $tester = $this->buildTester();
        $tester->execute(['filepath' => $inputPath]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertNotNull($capturedOutput);
        $this->assertStringContainsString('repaired', (string) $capturedOutput);
        $this->assertStringEndsWith('.json', (string) $capturedOutput);
    }
}
