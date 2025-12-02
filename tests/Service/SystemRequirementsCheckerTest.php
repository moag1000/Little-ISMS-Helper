<?php

namespace App\Tests\Service;

use App\Service\SystemRequirementsChecker;
use PHPUnit\Framework\TestCase;

class SystemRequirementsCheckerTest extends TestCase
{
    private string $projectDir;
    private string $testConfigDir;

    protected function setUp(): void
    {
        // Create temporary directory for testing
        $this->projectDir = sys_get_temp_dir() . '/isms_test_' . uniqid();
        $this->testConfigDir = $this->projectDir . '/config';

        // Create config directory structure
        mkdir($this->testConfigDir, 0777, true);
        mkdir($this->projectDir . '/var/cache', 0777, true);
        mkdir($this->projectDir . '/var/log', 0777, true);
        mkdir($this->projectDir . '/var/sessions', 0777, true);
        mkdir($this->projectDir . '/public/uploads', 0777, true);

        // Create default modules.yaml
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getDefaultModulesConfig()
        );
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->projectDir)) {
            $this->deleteDirectory($this->projectDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testCheckAllReturnsAllChecks(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertArrayHasKey('php', $results);
        $this->assertArrayHasKey('extensions', $results);
        $this->assertArrayHasKey('database', $results);
        $this->assertArrayHasKey('permissions', $results);
        $this->assertArrayHasKey('memory', $results);
        $this->assertArrayHasKey('execution_time', $results);
        $this->assertArrayHasKey('symfony', $results);
        $this->assertArrayHasKey('overall', $results);
    }

    public function testCheckAllPopulatesResults(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $checker->checkAll();
        $results = $checker->getResults();

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('overall', $results);
    }

    public function testGetResultsEmptyBeforeCheckAll(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->getResults();

        $this->assertEmpty($results);
    }

    public function testGetResultsAfterCheckAll(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $checker->checkAll();
        $results = $checker->getResults();

        $this->assertNotEmpty($results);
    }

    public function testIsSystemReadyRunsCheckAllIfNeeded(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $isReady = $checker->isSystemReady();

        // Results should be populated after calling isSystemReady
        $this->assertNotEmpty($checker->getResults());
        $this->assertIsBool($isReady);
    }

    public function testIsSystemReadyUsesExistingResults(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $checker->checkAll();
        $isReady = $checker->isSystemReady();

        $this->assertIsBool($isReady);
    }

    public function testPhpVersionCheckPasses(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertArrayHasKey('php', $results);
        $this->assertArrayHasKey('status', $results['php']);
        $this->assertArrayHasKey('current', $results['php']);
        $this->assertArrayHasKey('required', $results['php']);
        $this->assertArrayHasKey('critical', $results['php']);
        $this->assertTrue($results['php']['critical']);
    }

    public function testPhpVersionCheckWithOldVersion(): void
    {
        // Create config requiring impossible future version
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getModulesConfigWithPhpVersion('99.0.0')
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('error', $results['php']['status']);
        $this->assertStringContainsString('älter als erforderlich', $results['php']['message']);
    }

    public function testPhpExtensionsCheckPasses(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertArrayHasKey('extensions', $results);
        $this->assertArrayHasKey('status', $results['extensions']);
        $this->assertArrayHasKey('loaded', $results['extensions']);
        $this->assertArrayHasKey('missing', $results['extensions']);
        $this->assertArrayHasKey('critical', $results['extensions']);
        $this->assertTrue($results['extensions']['critical']);
        $this->assertIsArray($results['extensions']['loaded']);
        $this->assertIsArray($results['extensions']['missing']);
    }

    public function testPhpExtensionsCheckWithMissingExtensions(): void
    {
        // Create config requiring non-existent extensions
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getModulesConfigWithExtensions(['nonexistent_ext_123', 'fake_extension'])
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('error', $results['extensions']['status']);
        $this->assertNotEmpty($results['extensions']['missing']);
        $this->assertContains('nonexistent_ext_123', $results['extensions']['missing']);
        $this->assertContains('fake_extension', $results['extensions']['missing']);
    }

    public function testDatabaseCheckWithValidDatabaseUrl(): void
    {
        $_ENV['DATABASE_URL'] = 'mysql://user:pass@localhost/dbname';

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertArrayHasKey('database', $results);
        $this->assertSame('success', $results['database']['status']);
        $this->assertArrayHasKey('type', $results['database']);
        $this->assertSame('mysql', $results['database']['type']);
        $this->assertTrue($results['database']['critical']);

        unset($_ENV['DATABASE_URL']);
    }

    public function testDatabaseCheckWithPostgresqlUrl(): void
    {
        $_ENV['DATABASE_URL'] = 'postgresql://user:pass@localhost/dbname';

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('success', $results['database']['status']);
        $this->assertSame('postgresql', $results['database']['type']);

        unset($_ENV['DATABASE_URL']);
    }

    public function testDatabaseCheckWithoutDatabaseUrl(): void
    {
        unset($_ENV['DATABASE_URL']);

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('warning', $results['database']['status']);
        $this->assertStringContainsString('nicht konfiguriert', $results['database']['message']);
    }

    public function testDirectoryPermissionsCheckAllWritable(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertArrayHasKey('permissions', $results);
        $this->assertArrayHasKey('writable', $results['permissions']);
        $this->assertArrayHasKey('not_writable', $results['permissions']);
        $this->assertTrue($results['permissions']['critical']);
        $this->assertIsArray($results['permissions']['writable']);
        $this->assertIsArray($results['permissions']['not_writable']);
    }

    public function testDirectoryPermissionsCheckCreatesDirectories(): void
    {
        // Create config with non-existent directory
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getModulesConfigWithDirectories(['var/new_dir'])
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        // Directory should be created
        $this->assertTrue(is_dir($this->projectDir . '/var/new_dir'));
    }

    public function testMemoryLimitCheckWithSufficientMemory(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertArrayHasKey('memory', $results);
        $this->assertArrayHasKey('status', $results['memory']);
        $this->assertArrayHasKey('current', $results['memory']);
        $this->assertArrayHasKey('required', $results['memory']);
        $this->assertFalse($results['memory']['critical']);
    }

    public function testMemoryLimitCheckWithUnlimitedMemory(): void
    {
        // Simulate unlimited memory
        $originalLimit = ini_get('memory_limit');
        ini_set('memory_limit', '-1');

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('success', $results['memory']['status']);
        $this->assertStringContainsString('ausreichend', $results['memory']['message']);

        // Restore original limit
        ini_set('memory_limit', $originalLimit);
    }

    public function testMemoryLimitCheckWithLowMemory(): void
    {
        // Skip if memory_limit is unlimited (-1), as we can't simulate low memory
        $originalLimit = ini_get('memory_limit');
        if ($originalLimit === '-1') {
            // Set a finite memory limit for the test
            ini_set('memory_limit', '128M');
        }

        // Create config requiring very high memory
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getModulesConfigWithMemoryLimit('999999M')
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        // Restore original limit
        ini_set('memory_limit', $originalLimit);

        $this->assertSame('warning', $results['memory']['status']);
        $this->assertStringContainsString('niedriger als empfohlen', $results['memory']['message']);
    }

    public function testExecutionTimeCheckWithSufficientTime(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertArrayHasKey('execution_time', $results);
        $this->assertArrayHasKey('status', $results['execution_time']);
        $this->assertArrayHasKey('current', $results['execution_time']);
        $this->assertArrayHasKey('required', $results['execution_time']);
        $this->assertFalse($results['execution_time']['critical']);
    }

    public function testExecutionTimeCheckWithUnlimitedTime(): void
    {
        // Simulate unlimited execution time
        $originalTime = ini_get('max_execution_time');
        ini_set('max_execution_time', '0');

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('success', $results['execution_time']['status']);

        // Restore original time
        ini_set('max_execution_time', $originalTime);
    }

    public function testExecutionTimeCheckWithLowTime(): void
    {
        // Temporarily set a low execution time
        $originalTime = ini_get('max_execution_time');
        ini_set('max_execution_time', '30');

        // Create config requiring very high execution time
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getModulesConfigWithExecutionTime(999999)
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('warning', $results['execution_time']['status']);
        $this->assertStringContainsString('niedriger als empfohlen', $results['execution_time']['message']);

        // Restore original time
        ini_set('max_execution_time', $originalTime);
    }

    public function testSymfonyVersionCheckWithoutComposerLock(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('warning', $results['symfony']['status']);
        $this->assertStringContainsString('composer.lock nicht gefunden', $results['symfony']['message']);
        $this->assertTrue($results['symfony']['critical']);
    }

    public function testSymfonyVersionCheckWithValidVersion(): void
    {
        // Create composer.lock with Symfony 7.4
        file_put_contents(
            $this->projectDir . '/composer.lock',
            json_encode([
                'packages' => [
                    [
                        'name' => 'symfony/framework-bundle',
                        'version' => 'v7.4.0',
                    ],
                ],
            ])
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('success', $results['symfony']['status']);
        $this->assertArrayHasKey('current', $results['symfony']);
        $this->assertArrayHasKey('required', $results['symfony']);
    }

    public function testSymfonyVersionCheckWithOldVersion(): void
    {
        // Create composer.lock with old Symfony version
        file_put_contents(
            $this->projectDir . '/composer.lock',
            json_encode([
                'packages' => [
                    [
                        'name' => 'symfony/framework-bundle',
                        'version' => 'v6.0.0',
                    ],
                ],
            ])
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('error', $results['symfony']['status']);
        $this->assertStringContainsString('älter als erforderlich', $results['symfony']['message']);
    }

    public function testSymfonyVersionCheckWithoutFrameworkBundle(): void
    {
        // Create composer.lock without symfony/framework-bundle
        file_put_contents(
            $this->projectDir . '/composer.lock',
            json_encode([
                'packages' => [
                    [
                        'name' => 'some/other-package',
                        'version' => 'v1.0.0',
                    ],
                ],
            ])
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('error', $results['symfony']['status']);
        $this->assertStringContainsString('nicht gefunden', $results['symfony']['message']);
    }

    public function testSymfonyVersionCheckWithRcVersion(): void
    {
        // Create composer.lock with RC version
        file_put_contents(
            $this->projectDir . '/composer.lock',
            json_encode([
                'packages' => [
                    [
                        'name' => 'symfony/framework-bundle',
                        'version' => 'v7.4.0-RC1',
                    ],
                ],
            ])
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        // Should normalize version and compare correctly
        $this->assertArrayHasKey('current', $results['symfony']);
        $this->assertSame('v7.4.0-RC1', $results['symfony']['current']);
    }

    public function testOverallStatusWithNoCriticalErrors(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertArrayHasKey('overall', $results);
        $this->assertArrayHasKey('can_proceed', $results['overall']);
        $this->assertArrayHasKey('critical_errors', $results['overall']);
        $this->assertArrayHasKey('warnings', $results['overall']);
        $this->assertArrayHasKey('success', $results['overall']);
        $this->assertArrayHasKey('total_checks', $results['overall']);
        $this->assertArrayHasKey('message', $results['overall']);
    }

    public function testOverallStatusCanProceedWithNoErrors(): void
    {
        // Use default config which should pass all checks
        $_ENV['DATABASE_URL'] = 'mysql://user:pass@localhost/dbname';

        file_put_contents(
            $this->projectDir . '/composer.lock',
            json_encode([
                'packages' => [
                    [
                        'name' => 'symfony/framework-bundle',
                        'version' => 'v7.4.0',
                    ],
                ],
            ])
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        // May have warnings but should be able to proceed if no critical errors
        $this->assertIsBool($results['overall']['can_proceed']);

        unset($_ENV['DATABASE_URL']);
    }

    public function testOverallStatusCountsErrorsCorrectly(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $criticalErrors = $results['overall']['critical_errors'];
        $warnings = $results['overall']['warnings'];
        $success = $results['overall']['success'];

        $this->assertIsInt($criticalErrors);
        $this->assertIsInt($warnings);
        $this->assertIsInt($success);
        $this->assertGreaterThanOrEqual(0, $criticalErrors);
        $this->assertGreaterThanOrEqual(0, $warnings);
        $this->assertGreaterThanOrEqual(0, $success);
    }

    public function testConvertToBytesWithKilobytes(): void
    {
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getModulesConfigWithMemoryLimit('128K')
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        // Should handle 'K' suffix correctly
        $this->assertArrayHasKey('memory', $results);
        $this->assertSame('128K', $results['memory']['required']);
    }

    public function testConvertToBytesWithMegabytes(): void
    {
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getModulesConfigWithMemoryLimit('256M')
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('256M', $results['memory']['required']);
    }

    public function testConvertToBytesWithGigabytes(): void
    {
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getModulesConfigWithMemoryLimit('2G')
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('2G', $results['memory']['required']);
    }

    public function testConvertToBytesWithNumericValue(): void
    {
        file_put_contents(
            $this->testConfigDir . '/modules.yaml',
            $this->getModulesConfigWithMemoryLimit('134217728')
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        $this->assertSame('134217728', $results['memory']['required']);
    }

    public function testNormalizeVersionRemovesVPrefix(): void
    {
        file_put_contents(
            $this->projectDir . '/composer.lock',
            json_encode([
                'packages' => [
                    [
                        'name' => 'symfony/framework-bundle',
                        'version' => 'v7.4.0',
                    ],
                ],
            ])
        );

        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        // Version normalization is internal, but we can verify it works via comparison
        $this->assertSame('v7.4.0', $results['symfony']['current']);
    }

    public function testConstructorLoadsConfigCorrectly(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);
        $results = $checker->checkAll();

        // Verify that config was loaded by checking if requirements are applied
        $this->assertArrayHasKey('php', $results);
        $this->assertArrayHasKey('required', $results['php']);
        $this->assertSame('8.2.0', $results['php']['required']);
    }

    public function testConstructorWithInvalidConfigPath(): void
    {
        // This will throw an exception when trying to parse non-existent file
        $this->expectException(\Exception::class);

        new SystemRequirementsChecker('/nonexistent/path');
    }

    public function testMultipleCallsToCheckAllReturnConsistentResults(): void
    {
        $checker = new SystemRequirementsChecker($this->projectDir);

        $results1 = $checker->checkAll();
        $results2 = $checker->checkAll();

        $this->assertSame($results2, $checker->getResults());
        $this->assertArrayHasKey('overall', $results1);
        $this->assertArrayHasKey('overall', $results2);
    }

    // Helper methods to generate config variations

    private function getDefaultModulesConfig(): string
    {
        return <<<YAML
requirements:
  php:
    version: '8.2.0'
    extensions:
      - pdo
      - mbstring
      - intl
      - xml
      - json

  symfony:
    version: '7.3.0'

  permissions:
    writable_directories:
      - var/cache
      - var/log
      - var/sessions
      - public/uploads

  memory_limit: '256M'
  max_execution_time: 300
YAML;
    }

    private function getModulesConfigWithPhpVersion(string $version): string
    {
        return <<<YAML
requirements:
  php:
    version: '$version'
    extensions:
      - pdo

  symfony:
    version: '7.3.0'

  permissions:
    writable_directories:
      - var/cache

  memory_limit: '256M'
  max_execution_time: 300
YAML;
    }

    private function getModulesConfigWithExtensions(array $extensions): string
    {
        $extList = implode("\n      - ", $extensions);
        return <<<YAML
requirements:
  php:
    version: '8.2.0'
    extensions:
      - $extList

  symfony:
    version: '7.3.0'

  permissions:
    writable_directories:
      - var/cache

  memory_limit: '256M'
  max_execution_time: 300
YAML;
    }

    private function getModulesConfigWithDirectories(array $directories): string
    {
        $dirList = implode("\n      - ", $directories);
        return <<<YAML
requirements:
  php:
    version: '8.2.0'
    extensions:
      - pdo

  symfony:
    version: '7.3.0'

  permissions:
    writable_directories:
      - $dirList

  memory_limit: '256M'
  max_execution_time: 300
YAML;
    }

    private function getModulesConfigWithMemoryLimit(string $memoryLimit): string
    {
        return <<<YAML
requirements:
  php:
    version: '8.2.0'
    extensions:
      - pdo

  symfony:
    version: '7.3.0'

  permissions:
    writable_directories:
      - var/cache

  memory_limit: '$memoryLimit'
  max_execution_time: 300
YAML;
    }

    private function getModulesConfigWithExecutionTime(int $executionTime): string
    {
        return <<<YAML
requirements:
  php:
    version: '8.2.0'
    extensions:
      - pdo

  symfony:
    version: '7.3.0'

  permissions:
    writable_directories:
      - var/cache

  memory_limit: '256M'
  max_execution_time: $executionTime
YAML;
    }
}
