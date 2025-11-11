<?php

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class SecurityAuditService
{
    private string $projectDir;
    private Filesystem $filesystem;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
        $this->filesystem = new Filesystem();
    }

    /**
     * Generate security audit reports (both OWASP 2025 and 2021)
     */
    public function generateReports(): array
    {
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';

        if (!file_exists($scriptPath)) {
            throw new \RuntimeException('Security audit script not found at: ' . $scriptPath);
        }

        // Execute the PHP script
        $process = new Process(['php', $scriptPath], $this->projectDir, null, null, 300);
        $process->run();

        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        // Check if reports were generated
        $report2025Path = $this->projectDir . '/docs/reports/security-audit-owasp-2025-rc1.md';
        $report2021Path = $this->projectDir . '/docs/reports/security-audit-owasp-2021.md';

        return [
            'success' => $exitCode === 0 || $exitCode === 1, // Exit code 1 means critical findings, but report was generated
            'exit_code' => $exitCode,
            'output' => $output,
            'error_output' => $errorOutput,
            'reports' => [
                'owasp_2025' => [
                    'exists' => file_exists($report2025Path),
                    'path' => $report2025Path,
                    'size' => file_exists($report2025Path) ? filesize($report2025Path) : 0,
                    'updated_at' => file_exists($report2025Path) ? date('Y-m-d H:i:s', filemtime($report2025Path)) : null,
                ],
                'owasp_2021' => [
                    'exists' => file_exists($report2021Path),
                    'path' => $report2021Path,
                    'size' => file_exists($report2021Path) ? filesize($report2021Path) : 0,
                    'updated_at' => file_exists($report2021Path) ? date('Y-m-d H:i:s', filemtime($report2021Path)) : null,
                ],
            ],
        ];
    }

    /**
     * Get the status of existing security reports
     */
    public function getReportsStatus(): array
    {
        $report2025Path = $this->projectDir . '/docs/reports/security-audit-owasp-2025-rc1.md';
        $report2021Path = $this->projectDir . '/docs/reports/security-audit-owasp-2021.md';

        return [
            'owasp_2025' => [
                'exists' => file_exists($report2025Path),
                'updated_at' => file_exists($report2025Path) ? date('Y-m-d H:i:s', filemtime($report2025Path)) : null,
            ],
            'owasp_2021' => [
                'exists' => file_exists($report2021Path),
                'updated_at' => file_exists($report2021Path) ? date('Y-m-d H:i:s', filemtime($report2021Path)) : null,
            ],
        ];
    }
}
