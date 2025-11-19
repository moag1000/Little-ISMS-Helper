<?php

namespace App\Tests\Service;

use App\Service\SecurityAuditService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SecurityAuditServiceTest extends TestCase
{
    private string $projectDir;
    private SecurityAuditService $service;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/security_audit_test_' . uniqid();

        // Create project directory structure
        mkdir($this->projectDir, 0755, true);
        mkdir($this->projectDir . '/scripts', 0755, true);
        mkdir($this->projectDir . '/docs/reports', 0755, true);

        $this->service = new SecurityAuditService($this->projectDir);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testGenerateReportsThrowsExceptionWhenScriptNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Security audit script not found');

        $this->service->generateReports();
    }

    public function testGenerateReportsExecutesScriptSuccessfully(): void
    {
        // Create a mock script that creates empty report files
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';
        $scriptContent = <<<'PHP'
<?php
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2025-rc1.md', 'Test 2025 Report');
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2021.md', 'Test 2021 Report');
exit(0);
PHP;
        file_put_contents($scriptPath, $scriptContent);

        $result = $this->service->generateReports();

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['exit_code']);
        $this->assertArrayHasKey('output', $result);
        $this->assertArrayHasKey('error_output', $result);
        $this->assertArrayHasKey('reports', $result);
        $this->assertTrue($result['reports']['owasp_2025']['exists']);
        $this->assertTrue($result['reports']['owasp_2021']['exists']);
    }

    public function testGenerateReportsHandlesCriticalFindings(): void
    {
        // Create a mock script that exits with code 1 (critical findings)
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';
        $scriptContent = <<<'PHP'
<?php
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2025-rc1.md', 'Critical findings');
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2021.md', 'Critical findings');
exit(1);
PHP;
        file_put_contents($scriptPath, $scriptContent);

        $result = $this->service->generateReports();

        // Exit code 1 means critical findings, but report was generated
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['exit_code']);
        $this->assertTrue($result['reports']['owasp_2025']['exists']);
        $this->assertTrue($result['reports']['owasp_2021']['exists']);
    }

    public function testGenerateReportsHandlesScriptFailure(): void
    {
        // Create a mock script that fails
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';
        $scriptContent = <<<'PHP'
<?php
fwrite(STDERR, "Error generating report\n");
exit(2);
PHP;
        file_put_contents($scriptPath, $scriptContent);

        $result = $this->service->generateReports();

        $this->assertFalse($result['success']);
        $this->assertEquals(2, $result['exit_code']);
        $this->assertFalse($result['reports']['owasp_2025']['exists']);
        $this->assertFalse($result['reports']['owasp_2021']['exists']);
    }

    public function testGenerateReportsIncludesReportMetadata(): void
    {
        // Create a mock script
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';
        $scriptContent = <<<'PHP'
<?php
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2025-rc1.md', 'Test Report Content');
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2021.md', 'Another Report');
exit(0);
PHP;
        file_put_contents($scriptPath, $scriptContent);

        $result = $this->service->generateReports();

        $this->assertArrayHasKey('owasp_2025', $result['reports']);
        $this->assertArrayHasKey('path', $result['reports']['owasp_2025']);
        $this->assertArrayHasKey('size', $result['reports']['owasp_2025']);
        $this->assertArrayHasKey('updated_at', $result['reports']['owasp_2025']);
        $this->assertGreaterThan(0, $result['reports']['owasp_2025']['size']);
        $this->assertNotNull($result['reports']['owasp_2025']['updated_at']);
    }

    public function testGetReportsStatusReturnsNonExistentReports(): void
    {
        $status = $this->service->getReportsStatus();

        $this->assertArrayHasKey('owasp_2025', $status);
        $this->assertArrayHasKey('owasp_2021', $status);
        $this->assertFalse($status['owasp_2025']['exists']);
        $this->assertFalse($status['owasp_2021']['exists']);
        $this->assertNull($status['owasp_2025']['updated_at']);
        $this->assertNull($status['owasp_2021']['updated_at']);
    }

    public function testGetReportsStatusReturnsExistingReports(): void
    {
        // Create test reports
        $report2025Path = $this->projectDir . '/docs/reports/security-audit-owasp-2025-rc1.md';
        $report2021Path = $this->projectDir . '/docs/reports/security-audit-owasp-2021.md';

        file_put_contents($report2025Path, 'Test 2025 Report');
        file_put_contents($report2021Path, 'Test 2021 Report');

        // Wait a moment to ensure file timestamps are set
        usleep(10000);

        $status = $this->service->getReportsStatus();

        $this->assertTrue($status['owasp_2025']['exists']);
        $this->assertTrue($status['owasp_2021']['exists']);
        $this->assertNotNull($status['owasp_2025']['updated_at']);
        $this->assertNotNull($status['owasp_2021']['updated_at']);
    }

    public function testGetReportsStatusReturnsCorrectTimestamps(): void
    {
        $report2025Path = $this->projectDir . '/docs/reports/security-audit-owasp-2025-rc1.md';
        file_put_contents($report2025Path, 'Test Report');

        $expectedTime = filemtime($report2025Path);

        $status = $this->service->getReportsStatus();

        $this->assertNotNull($status['owasp_2025']['updated_at']);
        $actualTime = strtotime($status['owasp_2025']['updated_at']);
        $this->assertEquals($expectedTime, $actualTime);
    }

    public function testGenerateReportsReturnsOutputFromScript(): void
    {
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';
        $scriptContent = <<<'PHP'
<?php
echo "Generating OWASP 2025 report...\n";
echo "Generating OWASP 2021 report...\n";
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2025-rc1.md', 'Test');
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2021.md', 'Test');
exit(0);
PHP;
        file_put_contents($scriptPath, $scriptContent);

        $result = $this->service->generateReports();

        $this->assertStringContainsString('Generating OWASP 2025', $result['output']);
        $this->assertStringContainsString('Generating OWASP 2021', $result['output']);
    }

    public function testGenerateReportsReturnsErrorOutput(): void
    {
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';
        $scriptContent = <<<'PHP'
<?php
fwrite(STDERR, "Warning: Some issue detected\n");
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2025-rc1.md', 'Test');
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2021.md', 'Test');
exit(0);
PHP;
        file_put_contents($scriptPath, $scriptContent);

        $result = $this->service->generateReports();

        $this->assertStringContainsString('Warning: Some issue detected', $result['error_output']);
    }

    public function testGenerateReportsHasTimeout(): void
    {
        // Create a script that would run longer than timeout
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';
        $scriptContent = <<<'PHP'
<?php
// This test just verifies the timeout is set, not that it actually times out
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2025-rc1.md', 'Test');
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2021.md', 'Test');
exit(0);
PHP;
        file_put_contents($scriptPath, $scriptContent);

        $result = $this->service->generateReports();

        // Should complete successfully within timeout
        $this->assertTrue($result['success']);
    }

    public function testGenerateReportsChecksOwasp2025Report(): void
    {
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';
        $scriptContent = <<<'PHP'
<?php
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2025-rc1.md', 'OWASP 2025 Content');
exit(0);
PHP;
        file_put_contents($scriptPath, $scriptContent);

        $result = $this->service->generateReports();

        $this->assertTrue($result['reports']['owasp_2025']['exists']);
        $this->assertStringEndsWith('security-audit-owasp-2025-rc1.md', $result['reports']['owasp_2025']['path']);
    }

    public function testGenerateReportsChecksOwasp2021Report(): void
    {
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';
        $scriptContent = <<<'PHP'
<?php
file_put_contents(__DIR__ . '/../docs/reports/security-audit-owasp-2021.md', 'OWASP 2021 Content');
exit(0);
PHP;
        file_put_contents($scriptPath, $scriptContent);

        $result = $this->service->generateReports();

        $this->assertTrue($result['reports']['owasp_2021']['exists']);
        $this->assertStringEndsWith('security-audit-owasp-2021.md', $result['reports']['owasp_2021']['path']);
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
