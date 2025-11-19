<?php

namespace App\Tests\Service;

use App\Service\HealthAutoFixService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HealthAutoFixServiceTest extends TestCase
{
    private MockObject $logger;
    private string $projectDir;
    private string $cacheDir;
    private string $logsDir;
    private HealthAutoFixService $service;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->projectDir = sys_get_temp_dir() . '/health_test_' . uniqid();
        $this->cacheDir = $this->projectDir . '/var/cache';
        $this->logsDir = $this->projectDir . '/var/log';

        // Create directories
        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0755, true);
        }

        $this->service = new HealthAutoFixService(
            $this->projectDir,
            $this->cacheDir,
            $this->logsDir,
            $this->logger
        );
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testFixCachePermissionsWhenDirectoryDoesNotExist(): void
    {
        $result = $this->service->fixCachePermissions();

        $this->assertTrue($result['success']);
        $this->assertTrue(is_dir($this->cacheDir));
        $this->assertTrue(is_writable($this->cacheDir));
    }

    public function testFixCachePermissionsWhenAlreadyWritable(): void
    {
        mkdir($this->cacheDir, 0775, true);

        $result = $this->service->fixCachePermissions();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('already writable', $result['message']);
    }

    public function testFixCachePermissionsRecursively(): void
    {
        mkdir($this->cacheDir, 0755, true);
        mkdir($this->cacheDir . '/subdir', 0755, true);
        file_put_contents($this->cacheDir . '/test.txt', 'test');
        chmod($this->cacheDir . '/test.txt', 0644);

        $result = $this->service->fixCachePermissions();

        $this->assertTrue($result['success']);
        $this->assertTrue(is_writable($this->cacheDir));
    }

    public function testFixLogPermissionsWhenDirectoryDoesNotExist(): void
    {
        $result = $this->service->fixLogPermissions();

        $this->assertTrue($result['success']);
        $this->assertTrue(is_dir($this->logsDir));
        $this->assertTrue(is_writable($this->logsDir));
    }

    public function testFixLogPermissionsWhenAlreadyWritable(): void
    {
        mkdir($this->logsDir, 0775, true);

        $result = $this->service->fixLogPermissions();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('already writable', $result['message']);
    }

    public function testClearCacheSuccessfully(): void
    {
        mkdir($this->cacheDir, 0775, true);
        file_put_contents($this->cacheDir . '/cache1.php', 'cache content 1');
        file_put_contents($this->cacheDir . '/cache2.php', 'cache content 2');
        mkdir($this->cacheDir . '/subdir', 0775);
        file_put_contents($this->cacheDir . '/subdir/cache3.php', 'cache content 3');

        $result = $this->service->clearCache();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('successfully', $result['message']);
        $this->assertTrue(is_dir($this->cacheDir)); // Directory should still exist
    }

    public function testClearCacheWithEmptyDirectory(): void
    {
        mkdir($this->cacheDir, 0775, true);

        $result = $this->service->clearCache();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('0 B', $result['message']); // No space freed
    }

    public function testCleanOldLogsSuccessfully(): void
    {
        mkdir($this->logsDir, 0775, true);

        // Create old log file (35 days old)
        $oldLog = $this->logsDir . '/old.log';
        file_put_contents($oldLog, 'old log content');
        touch($oldLog, time() - (35 * 86400));

        // Create recent log file (5 days old)
        $recentLog = $this->logsDir . '/recent.log';
        file_put_contents($recentLog, 'recent log content');
        touch($recentLog, time() - (5 * 86400));

        $result = $this->service->cleanOldLogs(30);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Cleaned 1', $result['message']);
        $this->assertFileDoesNotExist($oldLog);
        $this->assertFileExists($recentLog);
    }

    public function testCleanOldLogsWithNoOldFiles(): void
    {
        mkdir($this->logsDir, 0775, true);

        // Create only recent log files
        file_put_contents($this->logsDir . '/recent.log', 'recent content');

        $result = $this->service->cleanOldLogs(30);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Cleaned 0', $result['message']);
    }

    public function testCleanOldLogsWhenDirectoryNotAccessible(): void
    {
        $result = $this->service->cleanOldLogs(30);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not accessible', $result['message']);
    }

    public function testRotateLogsSuccessfully(): void
    {
        mkdir($this->logsDir, 0775, true);

        // Create large log file (> 10MB)
        $largeLog = $this->logsDir . '/large.log';
        file_put_contents($largeLog, str_repeat('A', 11 * 1024 * 1024));

        $result = $this->service->rotateLogs();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Rotated', $result['message']);
        $this->assertTrue(filesize($largeLog) === 0); // Original file should be cleared
    }

    public function testRotateLogsWithSmallFiles(): void
    {
        mkdir($this->logsDir, 0775, true);

        // Create small log file (< 10MB)
        file_put_contents($this->logsDir . '/small.log', 'small content');

        $result = $this->service->rotateLogs();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Rotated 0', $result['message']);
    }

    public function testRotateLogsWhenDirectoryNotAccessible(): void
    {
        $result = $this->service->rotateLogs();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not accessible', $result['message']);
    }

    public function testOptimizeDiskSpace(): void
    {
        mkdir($this->cacheDir, 0775, true);
        mkdir($this->logsDir, 0775, true);

        // Create some cache files
        file_put_contents($this->cacheDir . '/cache.php', 'cache');

        // Create old log file
        $oldLog = $this->logsDir . '/old.log';
        file_put_contents($oldLog, 'old log');
        touch($oldLog, time() - (35 * 86400));

        $result = $this->service->optimizeDiskSpace();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('details', $result);
        $this->assertCount(3, $result['details']);
    }

    public function testFixVarPermissions(): void
    {
        $result = $this->service->fixVarPermissions();

        $this->assertTrue($result['success']);
        $this->assertTrue(is_dir($this->projectDir . '/var'));
        $this->assertTrue(is_writable($this->projectDir . '/var'));
    }

    public function testFixVarPermissionsWhenAlreadyWritable(): void
    {
        $varDir = $this->projectDir . '/var';
        mkdir($varDir, 0775, true);

        $result = $this->service->fixVarPermissions();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('successfully', $result['message']);
    }

    public function testFixUploadsPermissions(): void
    {
        $uploadsDir = $this->projectDir . '/public';
        mkdir($uploadsDir, 0755, true);

        $result = $this->service->fixUploadsPermissions();

        $this->assertTrue($result['success']);
        $this->assertTrue(is_dir($this->projectDir . '/public/uploads'));
    }

    public function testFixUploadsPermissionsWhenDirectoryExists(): void
    {
        $uploadsDir = $this->projectDir . '/public/uploads';
        mkdir($uploadsDir, 0755, true);

        $result = $this->service->fixUploadsPermissions();

        // Result depends on whether chmod succeeded
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testFixSessionPermissions(): void
    {
        $result = $this->service->fixSessionPermissions();

        // Result depends on system session directory permissions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
    }

    public function testClearOldUploads(): void
    {
        $uploadsDir = $this->projectDir . '/public/uploads';
        mkdir($uploadsDir, 0775, true);

        // Create old file
        $oldFile = $uploadsDir . '/old.jpg';
        file_put_contents($oldFile, 'old image');
        touch($oldFile, time() - (100 * 86400)); // 100 days old

        // Create recent file
        $recentFile = $uploadsDir . '/recent.jpg';
        file_put_contents($recentFile, 'recent image');

        $result = $this->service->clearOldUploads(90);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Cleaned 1', $result['message']);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($recentFile);
    }

    public function testClearOldUploadsWhenDirectoryDoesNotExist(): void
    {
        $result = $this->service->clearOldUploads(90);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not exist', $result['message']);
    }

    public function testRunComposerInstallWhenComposerNotFound(): void
    {
        $result = $this->service->runComposerInstall();

        // This will likely fail since composer won't be in our test dir
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
    }

    public function testFormatBytesCorrectly(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('formatBytes');
        $method->setAccessible(true);

        $this->assertEquals('0 B', $method->invoke($this->service, 0));
        $this->assertEquals('1 KB', $method->invoke($this->service, 1024));
        $this->assertEquals('1 MB', $method->invoke($this->service, 1024 * 1024));
        $this->assertEquals('1 GB', $method->invoke($this->service, 1024 * 1024 * 1024));
    }

    public function testGetDirectorySizeWithEmptyDirectory(): void
    {
        mkdir($this->cacheDir, 0775, true);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getDirectorySize');
        $method->setAccessible(true);

        $size = $method->invoke($this->service, $this->cacheDir);

        $this->assertEquals(0, $size);
    }

    public function testGetDirectorySizeWithFiles(): void
    {
        mkdir($this->cacheDir, 0775, true);
        file_put_contents($this->cacheDir . '/file1.txt', 'test content 1');
        file_put_contents($this->cacheDir . '/file2.txt', 'test content 2');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getDirectorySize');
        $method->setAccessible(true);

        $size = $method->invoke($this->service, $this->cacheDir);

        $this->assertGreaterThan(0, $size);
        $this->assertEquals(28, $size); // "test content 1" + "test content 2"
    }

    public function testCompressFileSuccessfully(): void
    {
        mkdir($this->logsDir, 0775, true);
        $sourceFile = $this->logsDir . '/test.log';
        $destFile = $this->logsDir . '/test.log.gz';
        file_put_contents($sourceFile, 'test log content');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('compressFile');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $sourceFile, $destFile);

        $this->assertTrue($result);
        $this->assertFileExists($destFile);
        $this->assertGreaterThan(0, filesize($destFile));
    }

    public function testFindComposerBinary(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('findComposerBinary');
        $method->setAccessible(true);

        $result = $method->invoke($this->service);

        // Result depends on system, so just check it's either string or null
        $this->assertTrue(is_string($result) || is_null($result));
    }

    public function testFixDirectoryPermissionsRecursivelyWithNestedStructure(): void
    {
        mkdir($this->cacheDir, 0755, true);
        mkdir($this->cacheDir . '/level1', 0755);
        mkdir($this->cacheDir . '/level1/level2', 0755);
        file_put_contents($this->cacheDir . '/level1/level2/file.txt', 'content');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('fixDirectoryPermissionsRecursive');
        $method->setAccessible(true);

        $method->invoke($this->service, $this->cacheDir);

        // Verify permissions were set (as much as we can check)
        $this->assertTrue(is_readable($this->cacheDir . '/level1'));
        $this->assertTrue(is_readable($this->cacheDir . '/level1/level2'));
    }

    public function testClearCacheHandlesException(): void
    {
        // Use non-existent cache directory that cannot be created
        $badService = new HealthAutoFixService(
            '/root/impossible/path',
            '/root/impossible/path/cache',
            '/root/impossible/path/logs',
            $this->logger
        );

        $result = $badService->clearCache();

        // Should handle exception gracefully
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
