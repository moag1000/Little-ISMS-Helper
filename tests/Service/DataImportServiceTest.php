<?php

namespace App\Tests\Service;

use App\Service\DataImportService;
use App\Service\ModuleConfigurationService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

class DataImportServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $kernel;
    private MockObject $moduleConfigService;
    private DataImportService $service;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->moduleConfigService = $this->createMock(ModuleConfigurationService::class);
        $this->projectDir = sys_get_temp_dir() . '/test_project';

        // Create temporary project directory
        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0777, true);
        }

        $this->service = new DataImportService(
            $this->entityManager,
            $this->kernel,
            $this->moduleConfigService,
            $this->projectDir
        );
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testImportBaseDataWithNoData(): void
    {
        $this->moduleConfigService->method('getBaseData')
            ->willReturn([]);

        $result = $this->service->importBaseData(['core', 'risk']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('log', $result);
        $this->assertEmpty($result['results']);
    }

    public function testImportBaseDataSkipsWhenModuleNotActive(): void
    {
        $baseData = [
            [
                'name' => 'ISO Controls',
                'required_modules' => ['core', 'compliance'],
                'command' => 'app:import-controls'
            ]
        ];

        $this->moduleConfigService->method('getBaseData')
            ->willReturn($baseData);

        // Only 'core' module is active, 'compliance' is not
        $result = $this->service->importBaseData(['core']);

        $this->assertCount(1, $result['results']);
        $this->assertSame('skipped', $result['results'][0]['status']);
        $this->assertStringContainsString('Erforderliche Module nicht aktiv', $result['results'][0]['message']);
    }

    public function testImportBaseDataWithCommandReturnsError(): void
    {
        // Skip command tests - they require full Symfony Application setup
        // with EventDispatcher which is not available in unit tests
        $this->markTestSkipped(
            'Command execution requires full Symfony kernel with EventDispatcher. ' .
            'This functionality is integration-tested elsewhere.'
        );
    }

    public function testImportBaseDataWithFile(): void
    {
        // Create test YAML file
        $testFile = 'data/test.yaml';
        $fullPath = $this->projectDir . '/' . $testFile;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, "assets:\n  - name: Test Asset\n    type: hardware");

        $baseData = [
            [
                'name' => 'Assets',
                'required_modules' => ['core'],
                'file' => $testFile
            ]
        ];

        $this->moduleConfigService->method('getBaseData')
            ->willReturn($baseData);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->service->importBaseData(['core']);

        $this->assertCount(1, $result['results']);
        $this->assertSame('Assets', $result['results'][0]['name']);
    }

    public function testImportSampleDataWithSelectedSamples(): void
    {
        $testFile = 'data/sample.yaml';
        $fullPath = $this->projectDir . '/' . $testFile;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, "assets:\n  - name: Sample Asset");

        $sampleData = [
            'sample1' => [
                'name' => 'Sample Assets',
                'required_modules' => ['core'],
                'file' => $testFile
            ],
            'sample2' => [
                'name' => 'Sample Risks',
                'required_modules' => ['risk'],
                'command' => 'test:command' // Use command instead to avoid file system
            ]
        ];

        $this->moduleConfigService->method('getSampleData')
            ->willReturn($sampleData);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $selectedSamples = [
            'sample1' => true,
            'sample2' => false
        ];

        $result = $this->service->importSampleData($selectedSamples, ['core', 'risk']);

        $this->assertCount(1, $result['results']);
        $this->assertSame('Sample Assets', $result['results'][0]['name']);
    }

    public function testImportSampleDataWithNonexistentSample(): void
    {
        $this->moduleConfigService->method('getSampleData')
            ->willReturn([]);

        $selectedSamples = [
            'nonexistent' => true
        ];

        $result = $this->service->importSampleData($selectedSamples, ['core']);

        $this->assertCount(1, $result['results']);
        $this->assertSame('error', $result['results'][0]['status']);
        $this->assertStringContainsString('nicht gefunden', $result['results'][0]['message']);
    }

    public function testImportSampleDataSkipsInactiveModules(): void
    {
        $sampleData = [
            'sample1' => [
                'name' => 'Sample',
                'required_modules' => ['inactive_module'],
                'command' => 'test:command'
            ]
        ];

        $this->moduleConfigService->method('getSampleData')
            ->willReturn($sampleData);

        $result = $this->service->importSampleData(['sample1' => true], ['core']);

        $this->assertCount(1, $result['results']);
        $this->assertSame('skipped', $result['results'][0]['status']);
    }

    public function testCheckDatabaseStatusWithExistingTables(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);

        $this->entityManager->method('getConnection')
            ->willReturn($connection);

        $connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $schemaManager->method('listTableNames')
            ->willReturn([
                'asset',
                'risk',
                'control',
                'incident',
                'internal_audit',
                'business_process',
                'compliance_framework',
                'user'
            ]);

        $result = $this->service->checkDatabaseStatus();

        $this->assertTrue($result['initialized']);
        $this->assertSame(8, $result['total_tables']);
        $this->assertEmpty($result['missing_tables']);
    }

    public function testCheckDatabaseStatusWithMissingTables(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);

        $this->entityManager->method('getConnection')
            ->willReturn($connection);

        $connection->method('createSchemaManager')
            ->willReturn($schemaManager);

        $schemaManager->method('listTableNames')
            ->willReturn(['asset', 'risk']);

        $result = $this->service->checkDatabaseStatus();

        $this->assertFalse($result['initialized']);
        $this->assertNotEmpty($result['missing_tables']);
        $this->assertContains('control', $result['missing_tables']);
    }

    public function testCheckDatabaseStatusWithException(): void
    {
        $this->entityManager->method('getConnection')
            ->willThrowException(new \Exception('Connection failed'));

        $result = $this->service->checkDatabaseStatus();

        $this->assertFalse($result['initialized']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Connection failed', $result['error']);
    }

    public function testRunMigrationsReturnsErrorWithoutKernel(): void
    {
        // Skip migration tests - they require full Symfony Application setup
        $this->markTestSkipped(
            'Migration execution requires full Symfony kernel with EventDispatcher. ' .
            'This functionality is integration-tested elsewhere.'
        );
    }

    public function testGetImportLogReturnsArray(): void
    {
        $log = $this->service->getImportLog();

        $this->assertIsArray($log);
    }

    public function testExportModuleDataWithNonexistentModule(): void
    {
        $this->moduleConfigService->method('getModule')
            ->with('nonexistent')
            ->willReturn(null);

        $result = $this->service->exportModuleData('nonexistent');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('nicht gefunden', $result['error']);
    }

    public function testExportModuleDataWithValidModule(): void
    {
        $module = [
            'name' => 'Risk Management',
            'entities' => ['Risk', 'Asset']
        ];

        $this->moduleConfigService->method('getModule')
            ->with('risk')
            ->willReturn($module);

        // Mock repositories to return empty arrays
        $this->entityManager->method('getRepository')
            ->willReturnCallback(function() {
                $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
                $repo->method('findAll')->willReturn([]);
                return $repo;
            });

        $result = $this->service->exportModuleData('risk');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertSame(0, $result['count']);
    }

    public function testImportLogIsPopulatedDuringImport(): void
    {
        // Create a test file
        $testFile = 'data/log-test.yaml';
        $fullPath = $this->projectDir . '/' . $testFile;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, "assets:\n  - name: Test");

        $baseData = [
            [
                'name' => 'Test',
                'required_modules' => ['core'],
                'file' => $testFile
            ]
        ];

        $this->moduleConfigService->method('getBaseData')
            ->willReturn($baseData);

        $this->entityManager->method('flush');

        $result = $this->service->importBaseData(['core']);

        $this->assertNotEmpty($result['log']);
        $this->assertArrayHasKey('timestamp', $result['log'][0]);
        $this->assertArrayHasKey('message', $result['log'][0]);
    }

    public function testImportBaseDataHandlesMultipleItems(): void
    {
        $testFile1 = 'data/test1.yaml';
        $testFile2 = 'data/test2.yaml';

        foreach ([$testFile1, $testFile2] as $testFile) {
            $fullPath = $this->projectDir . '/' . $testFile;
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($fullPath, "assets:\n  - name: Test");
        }

        $baseData = [
            [
                'name' => 'Data 1',
                'required_modules' => ['core'],
                'file' => $testFile1
            ],
            [
                'name' => 'Data 2',
                'required_modules' => ['core'],
                'file' => $testFile2
            ]
        ];

        $this->moduleConfigService->method('getBaseData')
            ->willReturn($baseData);

        $this->entityManager->method('flush');

        $result = $this->service->importBaseData(['core']);

        $this->assertCount(2, $result['results']);
    }

    public function testImportFromFileWithNonexistentFile(): void
    {
        $baseData = [
            [
                'name' => 'Test',
                'required_modules' => ['core'],
                'file' => 'nonexistent.yaml'
            ]
        ];

        $this->moduleConfigService->method('getBaseData')
            ->willReturn($baseData);

        $result = $this->service->importBaseData(['core']);

        $this->assertCount(1, $result['results']);
        $this->assertSame('error', $result['results'][0]['status']);
        $this->assertStringContainsString('nicht gefunden', $result['results'][0]['message']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
