<?php

namespace App\Tests\Service;

use App\Service\LicenseReportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class LicenseReportServiceTest extends TestCase
{
    private string $projectDir;
    private LicenseReportService $service;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/license_test_' . uniqid();

        // Create project directory
        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0755, true);
        }

        $this->service = new LicenseReportService($this->projectDir);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testGenerateReportThrowsExceptionWhenScriptNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('License report script not found');

        $this->service->generateReport();
    }

    public function testGenerateReportExecutesScriptSuccessfully(): void
    {
        // Create a mock shell script
        $scriptPath = $this->projectDir . '/license-report.sh';
        $scriptContent = <<<'BASH'
#!/bin/bash
mkdir -p docs/reports
echo "# License Report" > docs/reports/license-report.md
echo "Generated successfully"
exit 0
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $result = $this->service->generateReport();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('exit_code', $result);
        $this->assertArrayHasKey('output', $result);
        $this->assertArrayHasKey('error_output', $result);
        $this->assertArrayHasKey('report', $result);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['exit_code']);
        $this->assertStringContainsString('Generated successfully', $result['output']);
    }

    public function testGenerateReportHandlesScriptFailure(): void
    {
        // Create a failing shell script
        $scriptPath = $this->projectDir . '/license-report.sh';
        $scriptContent = <<<'BASH'
#!/bin/bash
echo "Error occurred" >&2
exit 1
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $result = $this->service->generateReport();

        $this->assertFalse($result['success']);
        $this->assertEquals(1, $result['exit_code']);
        $this->assertStringContainsString('Error occurred', $result['error_output']);
    }

    public function testGenerateReportIncludesReportMetadata(): void
    {
        // Create mock script and report
        $scriptPath = $this->projectDir . '/license-report.sh';
        $reportDir = $this->projectDir . '/docs/reports';
        $reportPath = $reportDir . '/license-report.md';

        mkdir($reportDir, 0755, true);

        $scriptContent = <<<'BASH'
#!/bin/bash
echo "# License Report" > docs/reports/license-report.md
exit 0
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $result = $this->service->generateReport();

        $this->assertTrue($result['report']['exists']);
        $this->assertEquals($reportPath, $result['report']['path']);
        $this->assertGreaterThan(0, $result['report']['size']);
        $this->assertNotNull($result['report']['updated_at']);
    }

    public function testGenerateReportWhenReportFileNotCreated(): void
    {
        // Create script that doesn't create report
        $scriptPath = $this->projectDir . '/license-report.sh';
        $scriptContent = <<<'BASH'
#!/bin/bash
echo "Script ran but no report created"
exit 0
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $result = $this->service->generateReport();

        $this->assertTrue($result['success']); // Script succeeded
        $this->assertFalse($result['report']['exists']); // But no report
        $this->assertEquals(0, $result['report']['size']);
        $this->assertNull($result['report']['updated_at']);
    }

    public function testGetReportStatusWhenReportExists(): void
    {
        $reportDir = $this->projectDir . '/docs/reports';
        $reportPath = $reportDir . '/license-report.md';

        mkdir($reportDir, 0755, true);
        file_put_contents($reportPath, '# License Report');
        touch($reportPath, strtotime('2024-01-15 10:30:00'));

        $result = $this->service->getReportStatus();

        $this->assertTrue($result['exists']);
        $this->assertNotNull($result['updated_at']);
    }

    public function testGetReportStatusWhenReportDoesNotExist(): void
    {
        $result = $this->service->getReportStatus();

        $this->assertFalse($result['exists']);
        $this->assertNull($result['updated_at']);
    }

    public function testGetReportStatusReturnsCorrectFormat(): void
    {
        $result = $this->service->getReportStatus();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('exists', $result);
        $this->assertArrayHasKey('updated_at', $result);
        $this->assertIsBool($result['exists']);
    }

    public function testGenerateReportHandlesLongRunningScript(): void
    {
        // Create script that takes a bit of time
        $scriptPath = $this->projectDir . '/license-report.sh';
        $scriptContent = <<<'BASH'
#!/bin/bash
sleep 1
mkdir -p docs/reports
echo "# License Report" > docs/reports/license-report.md
exit 0
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $startTime = microtime(true);
        $result = $this->service->generateReport();
        $duration = microtime(true) - $startTime;

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(1, $duration); // Should take at least 1 second
        $this->assertLessThan(10, $duration); // But not too long
    }

    public function testGenerateReportCapturesStdout(): void
    {
        $scriptPath = $this->projectDir . '/license-report.sh';
        $scriptContent = <<<'BASH'
#!/bin/bash
echo "Processing dependencies..."
echo "Analyzing licenses..."
echo "Report generated"
exit 0
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $result = $this->service->generateReport();

        $this->assertStringContainsString('Processing dependencies', $result['output']);
        $this->assertStringContainsString('Analyzing licenses', $result['output']);
        $this->assertStringContainsString('Report generated', $result['output']);
    }

    public function testGenerateReportCapturesStderr(): void
    {
        $scriptPath = $this->projectDir . '/license-report.sh';
        $scriptContent = <<<'BASH'
#!/bin/bash
echo "Warning: deprecated package" >&2
echo "Error: missing dependency" >&2
exit 2
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $result = $this->service->generateReport();

        $this->assertFalse($result['success']);
        $this->assertEquals(2, $result['exit_code']);
        $this->assertStringContainsString('Warning:', $result['error_output']);
        $this->assertStringContainsString('Error:', $result['error_output']);
    }

    public function testGenerateReportWithComplexOutput(): void
    {
        $scriptPath = $this->projectDir . '/license-report.sh';
        $reportDir = $this->projectDir . '/docs/reports';
        mkdir($reportDir, 0755, true);

        $scriptContent = <<<'BASH'
#!/bin/bash
cat > docs/reports/license-report.md << 'EOF'
# License Report

## Dependencies
- Package A: MIT
- Package B: Apache-2.0
- Package C: GPL-3.0

## Summary
Total: 3 packages
EOF
exit 0
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $result = $this->service->generateReport();

        $this->assertTrue($result['success']);
        $this->assertTrue($result['report']['exists']);
        $this->assertGreaterThan(50, $result['report']['size']);
    }

    public function testGenerateReportHandlesPermissionErrors(): void
    {
        $scriptPath = $this->projectDir . '/license-report.sh';
        $scriptContent = <<<'BASH'
#!/bin/bash
echo "Cannot write to directory" >&2
exit 13
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $result = $this->service->generateReport();

        $this->assertFalse($result['success']);
        $this->assertEquals(13, $result['exit_code']);
    }

    public function testGetReportStatusMultipleTimes(): void
    {
        // First call - no report
        $result1 = $this->service->getReportStatus();
        $this->assertFalse($result1['exists']);

        // Create report
        $reportDir = $this->projectDir . '/docs/reports';
        mkdir($reportDir, 0755, true);
        file_put_contents($reportDir . '/license-report.md', '# Report');

        // Second call - report exists
        $result2 = $this->service->getReportStatus();
        $this->assertTrue($result2['exists']);
    }

    public function testGenerateReportRespectsTimeout(): void
    {
        $scriptPath = $this->projectDir . '/license-report.sh';
        $scriptContent = <<<'BASH'
#!/bin/bash
# This should complete within timeout (300s)
echo "Quick operation"
exit 0
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        $result = $this->service->generateReport();

        // Should complete without timeout
        $this->assertTrue($result['success']);
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
