<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SecurityAuditService
{
    public const EDITION_2025 = '2025';
    public const EDITION_2021 = '2021';

    /** Where scripts/generate-security-audit.php writes its output, relative to the project dir. */
    private const GENERATED_REPORTS = [
        self::EDITION_2025 => 'var/reports/security-audit-owasp-2025.md',
        self::EDITION_2021 => 'var/reports/security-audit-owasp-2021.md',
    ];

    /** Tracked fallback snapshots, relative to the project dir (there is none for 2021). */
    private const SNAPSHOT_REPORTS = [
        self::EDITION_2025 => 'docs/reports/security-audit-owasp-2025-rc1.md',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    /**
     * Single place that knows where a report lives: the generated file in var/reports if it
     * exists, otherwise the tracked snapshot if the edition has one, otherwise null.
     */
    public function reportPath(string $edition): ?string
    {
        foreach ([self::GENERATED_REPORTS, self::SNAPSHOT_REPORTS] as $candidates) {
            if (isset($candidates[$edition]) && is_file($this->projectDir . '/' . $candidates[$edition])) {
                return $this->projectDir . '/' . $candidates[$edition];
            }
        }

        return null;
    }

    /**
     * Generate security audit reports (both OWASP 2025 and 2021)
     */
    public function generateReports(): array
    {
        $scriptPath = $this->projectDir . '/scripts/generate-security-audit.php';

        if (!file_exists($scriptPath)) {
            throw new \App\Exception\Io\IoException('Security audit script not found at: ' . $scriptPath);
        }

        // Execute the PHP script
        $process = new Process(['php', $scriptPath], $this->projectDir, null, null, 300);
        $process->run();

        $exitCode = $process->getExitCode();

        return [
            'success' => $exitCode === 0 || $exitCode === 1, // Exit code 1 means critical findings, but report was generated
            'exit_code' => $exitCode,
            'output' => $process->getOutput(),
            'error_output' => $process->getErrorOutput(),
            'reports' => [
                'owasp_2025' => $this->describe(self::EDITION_2025, true),
                'owasp_2021' => $this->describe(self::EDITION_2021, true),
            ],
        ];
    }

    /**
     * Get the status of existing security reports
     */
    public function getReportsStatus(): array
    {
        return [
            'owasp_2025' => $this->describe(self::EDITION_2025, false),
            'owasp_2021' => $this->describe(self::EDITION_2021, false),
        ];
    }

    private function describe(string $edition, bool $detailed): array
    {
        $path = $this->reportPath($edition);
        $info = [
            'exists' => $path !== null,
            'updated_at' => $path !== null ? date('Y-m-d H:i:s', (int) filemtime($path)) : null,
        ];
        if ($detailed) {
            $info = [
                'exists' => $info['exists'],
                'path' => $path ?? $this->projectDir . '/' . self::GENERATED_REPORTS[$edition],
                'size' => $path !== null ? (int) filesize($path) : 0,
                'updated_at' => $info['updated_at'],
            ];
        }

        return $info;
    }
}
