<?php

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class LicenseReportService
{
    private string $projectDir;
    private Filesystem $filesystem;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
        $this->filesystem = new Filesystem();
    }

    /**
     * Generate license report
     */
    public function generateReport(): array
    {
        $scriptPath = $this->projectDir . '/license-report.sh';

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException('License report script not found at: ' . $scriptPath);
        }

        // Execute the shell script
        $process = new Process(['bash', $scriptPath], $this->projectDir, null, null, 300);
        $process->run();

        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        // Check if report was generated
        $reportPath = $this->projectDir . '/docs/reports/license-report.md';

        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output,
            'error_output' => $errorOutput,
            'report' => [
                'exists' => file_exists($reportPath),
                'path' => $reportPath,
                'size' => file_exists($reportPath) ? filesize($reportPath) : 0,
                'updated_at' => file_exists($reportPath) ? date('Y-m-d H:i:s', filemtime($reportPath)) : null,
            ],
        ];
    }

    /**
     * Get the status of the existing license report
     */
    public function getReportStatus(): array
    {
        $reportPath = $this->projectDir . '/docs/reports/license-report.md';

        return [
            'exists' => file_exists($reportPath),
            'updated_at' => file_exists($reportPath) ? date('Y-m-d H:i:s', filemtime($reportPath)) : null,
        ];
    }
}
