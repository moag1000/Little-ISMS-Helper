<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackupRepairService;
use App\Service\RepairReport;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BackupRepairService.
 *
 * All tests work on pure file I/O — no live database required.
 * Temporary directories are created in sys_get_temp_dir() and cleaned up
 * in tearDown().
 */
class BackupRepairServiceTest extends TestCase
{
    private string $tmpDir;
    private BackupRepairService $service;

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir() . '/backup_repair_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);

        // Service without EntityManager — skips the id-field null-check heuristic
        $this->service = new BackupRepairService();
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tmpDir);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    /**
     * Write a backup JSON file and return its path.
     *
     * @param array<string, mixed> $data
     */
    private function writeBackupJson(array $data, string $filename = 'backup.json'): string
    {
        $path = $this->tmpDir . '/' . $filename;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        return $path;
    }

    /**
     * Build a minimal well-formed backup array.
     *
     * @param array<string, list<array<string, mixed>>> $entityData
     * @return array<string, mixed>
     */
    private function makeBackup(array $entityData = []): array
    {
        $dataJson = json_encode($entityData);
        return [
            'metadata' => [
                'version'    => '2.0',
                'created_at' => '2026-04-24T10:00:00+00:00',
                'entities'   => count($entityData),
                'sha256'     => hash('sha256', $dataJson),
                'scope_type' => 'global',
            ],
            'data'       => $entityData,
            'statistics' => array_map(fn(array $rows): int => count($rows), $entityData),
        ];
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
    // Test: analyze on a clean backup — no issues, 100% recoverable
    // ==================================================================

    public function testAnalyzeCleanBackup(): void
    {
        $entityData = [
            'Asset' => [
                ['id' => 1, 'name' => 'Server 01', 'tenant_id' => 1],
                ['id' => 2, 'name' => 'Laptop 07', 'tenant_id' => 1],
            ],
            'Risk'  => [
                ['id' => 10, 'title' => 'Data loss', 'score' => 15],
            ],
        ];

        $path   = $this->writeBackupJson($this->makeBackup($entityData));
        $report = $this->service->analyze($path);

        $this->assertInstanceOf(RepairReport::class, $report);
        $this->assertTrue($report->isRecoverable);
        $this->assertSame(2, $report->totalEntities);
        $this->assertSame(3, $report->totalRows);
        $this->assertSame(3, $report->recoveredRows);
        $this->assertSame(0, $report->lostRows);
        $this->assertEmpty($report->metadataIssues);
        $this->assertNull($report->recomputedSha256, 'analyze() must not compute sha256');

        // Per-entity breakdown
        $this->assertArrayHasKey('Asset', $report->perEntity);
        $this->assertSame(2, $report->perEntity['Asset']['total']);
        $this->assertSame(2, $report->perEntity['Asset']['recovered']);
        $this->assertSame(0, $report->perEntity['Asset']['lost']);
        $this->assertEmpty($report->perEntity['Asset']['issues']);
    }

    // ==================================================================
    // Test: SHA-256 mismatch reported but rows are still recoverable
    // ==================================================================

    public function testAnalyzeSha256Mismatch(): void
    {
        $entityData = [
            'Control' => [
                ['id' => 5, 'title' => 'Access control policy'],
            ],
        ];

        $backup = $this->makeBackup($entityData);
        // Tamper the stored hash so it mismatches the actual data hash
        $backup['metadata']['sha256'] = 'deadbeef1234567890deadbeef1234567890deadbeef1234567890deadbeef12';

        $path   = $this->writeBackupJson($backup);
        $report = $this->service->analyze($path);

        $this->assertTrue($report->isRecoverable, 'SHA-256 mismatch must not make backup unrecoverable');
        $this->assertSame(1, $report->recoveredRows);
        $this->assertSame(0, $report->lostRows);

        $hasHashIssue = false;
        foreach ($report->metadataIssues as $issue) {
            if (str_contains(strtolower($issue), 'sha256')) {
                $hasHashIssue = true;
                break;
            }
        }
        $this->assertTrue($hasHashIssue, 'Report must mention sha256 mismatch in metadataIssues');
    }

    // ==================================================================
    // Test: missing metadata fields reported, rows still recoverable
    // ==================================================================

    public function testAnalyzeMissingMetadata(): void
    {
        $entityData = [
            'Incident' => [
                ['id' => 99, 'title' => 'Phishing attempt'],
            ],
        ];

        // Deliberately omit 'version' and 'created_at'
        $backup = [
            'metadata' => [
                'entities' => 1,
                'sha256'   => hash('sha256', json_encode($entityData)),
            ],
            'data' => $entityData,
        ];

        $path   = $this->writeBackupJson($backup);
        $report = $this->service->analyze($path);

        $this->assertTrue($report->isRecoverable);
        $this->assertSame(1, $report->recoveredRows);

        // Both 'version' and 'created_at' must appear in metadataIssues
        $allIssues = implode(' ', $report->metadataIssues);
        $this->assertStringContainsString('version', $allIssues);
        $this->assertStringContainsString('created_at', $allIssues);
    }

    // ==================================================================
    // Test: malformed rows dropped, valid rows preserved
    // ==================================================================

    public function testRepairDropsMalformedRows(): void
    {
        $entityData = [
            'Supplier' => [
                ['id' => 1, 'name' => 'Acme Corp'],   // valid
                null,                                   // invalid: not an array
                false,                                  // invalid: not an array
                42,                                     // invalid: not an array
                [],                                     // invalid: empty array
                ['id' => 2, 'name' => 'Beta Ltd'],     // valid
            ],
        ];

        $backup = [
            'metadata' => [
                'version'    => '2.0',
                'created_at' => '2026-04-24T12:00:00+00:00',
                'entities'   => 1,
                'sha256'     => hash('sha256', json_encode($entityData)),
                'scope_type' => 'global',
            ],
            'data'       => $entityData,
            'statistics' => ['Supplier' => 6],
        ];

        $inputPath  = $this->writeBackupJson($backup, 'malformed.json');
        $outputPath = $this->tmpDir . '/malformed.repaired.json';

        $report = $this->service->repair($inputPath, $outputPath);

        $this->assertTrue($report->isRecoverable);
        $this->assertSame(6, $report->totalRows);
        $this->assertSame(2, $report->recoveredRows, 'Only 2 valid rows survive');
        $this->assertSame(4, $report->lostRows);
        $this->assertNotEmpty($report->perEntity['Supplier']['issues']);

        // Verify the cleaned file content
        $this->assertFileExists($outputPath);
        $cleaned = json_decode(file_get_contents($outputPath), true);
        $this->assertCount(2, $cleaned['data']['Supplier']);
    }

    // ==================================================================
    // Test: rows with id=null dropped
    // ==================================================================

    public function testRepairDropsRowsWithNullId(): void
    {
        // We need a service that knows 'Asset' has an 'id' field.
        // Without EntityManager the id-null check relies on the field
        // being present in the row with a null value — confirmed by the
        // Doctrine-aware branch.  For this unit test we inject a mock.
        $mockMeta = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $mockMeta->method('getIdentifierFieldNames')->willReturn(['id']);

        $mockEm = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $mockEm->method('getClassMetadata')
            ->with('App\\Entity\\Asset')
            ->willReturn($mockMeta);

        $service = new BackupRepairService(entityManager: $mockEm);

        $entityData = [
            'Asset' => [
                ['id' => 1,    'name' => 'Server'],   // valid
                ['id' => null, 'name' => 'Ghost'],    // invalid: null id
                ['id' => 3,    'name' => 'Laptop'],   // valid
            ],
        ];

        $backup     = $this->makeBackup($entityData);
        $inputPath  = $this->writeBackupJson($backup, 'null_id.json');
        $outputPath = $this->tmpDir . '/null_id.repaired.json';

        $report = $service->repair($inputPath, $outputPath);

        $this->assertTrue($report->isRecoverable);
        $this->assertSame(3, $report->totalRows);
        $this->assertSame(2, $report->recoveredRows);
        $this->assertSame(1, $report->lostRows);

        $cleaned = json_decode(file_get_contents($outputPath), true);
        $this->assertCount(2, $cleaned['data']['Asset']);
        // Confirm ghost row is gone
        $names = array_column($cleaned['data']['Asset'], 'name');
        $this->assertNotContains('Ghost', $names);
    }

    // ==================================================================
    // Test: repair produces valid JSON with correct sha256
    // ==================================================================

    public function testRepairWritesCleanedJson(): void
    {
        $entityData = [
            'Document' => [
                ['id' => 1, 'title' => 'Policy v1'],
                ['id' => 2, 'title' => 'Procedure v3'],
            ],
        ];

        $backup     = $this->makeBackup($entityData);
        $inputPath  = $this->writeBackupJson($backup, 'clean.json');
        $outputPath = $this->tmpDir . '/clean.repaired.json';

        $report = $this->service->repair($inputPath, $outputPath);

        $this->assertTrue($report->isRecoverable);
        $this->assertNotNull($report->recomputedSha256);
        $this->assertFileExists($outputPath);

        // Validate the output is parseable JSON
        $cleaned = json_decode(file_get_contents($outputPath), true);
        $this->assertNotNull($cleaned, 'Output must be valid JSON');

        // SHA-256 must match the data section of the cleaned output
        $expectedSha = hash('sha256', (string) json_encode($cleaned['data']));
        $this->assertSame($expectedSha, $cleaned['metadata']['sha256']);
        $this->assertSame($report->recomputedSha256, $cleaned['metadata']['sha256']);

        // repair_report_summary and repaired_at must be present
        $this->assertArrayHasKey('repaired_at', $cleaned['metadata']);
        $this->assertArrayHasKey('repair_report_summary', $cleaned['metadata']);
    }

    // ==================================================================
    // Test: completely broken JSON → isRecoverable=false
    // ==================================================================

    public function testRepairUnrecoverable(): void
    {
        $path = $this->tmpDir . '/broken.json';
        file_put_contents($path, '{ this is not valid JSON !!!');

        $outputPath = $this->tmpDir . '/broken.repaired.json';
        $report     = $this->service->repair($path, $outputPath);

        $this->assertFalse($report->isRecoverable);
        $this->assertSame(0, $report->recoveredRows);
        $this->assertNotEmpty($report->metadataIssues);
        $this->assertFileDoesNotExist($outputPath, 'No output file should be written when unrecoverable');
    }

    // ==================================================================
    // Test: scope metadata (scope_type, tenant_scope) survives repair
    // ==================================================================

    public function testRepairPreservesScopeMetadata(): void
    {
        $entityData = [
            'Tenant' => [
                ['id' => 1, 'name' => 'Holding GmbH'],
                ['id' => 2, 'name' => 'Sub A GmbH'],
            ],
        ];

        $backup = $this->makeBackup($entityData);
        $backup['metadata']['scope_type']    = 'holding';
        $backup['metadata']['tenant_scope']  = [1, 2, 3];
        $backup['metadata']['schema_version'] = '20260418120000';
        // Deliberately tamper sha256 to also test that SHA recompute issue is noted
        $backup['metadata']['sha256'] = 'aaaa';

        $inputPath  = $this->writeBackupJson($backup, 'scope.json');
        $outputPath = $this->tmpDir . '/scope.repaired.json';

        $report = $this->service->repair($inputPath, $outputPath);

        $this->assertTrue($report->isRecoverable);

        $cleaned = json_decode(file_get_contents($outputPath), true);
        $this->assertSame('holding', $cleaned['metadata']['scope_type']);
        $this->assertSame([1, 2, 3], $cleaned['metadata']['tenant_scope']);
        $this->assertSame('20260418120000', $cleaned['metadata']['schema_version']);

        // SHA-256 must be valid after repair
        $expectedSha = hash('sha256', (string) json_encode($cleaned['data']));
        $this->assertSame($expectedSha, $cleaned['metadata']['sha256']);
    }
}
