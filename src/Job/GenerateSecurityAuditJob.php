<?php

declare(strict_types=1);

namespace App\Job;

use App\Exception\Io\IoException;
use App\Service\SecurityAuditService;
use Psr\Log\LoggerInterface;

/**
 * Async job (S32): regenerates the instance-wide OWASP security reports (`scripts/generate-security-audit.php`,
 * up to 300 s). Started only by a SUPER_ADMIN; the script output stays in the application log — the status file
 * carries a fixed message, never output, paths or exception text (Lesson 11).
 *
 * Args: none.
 */
final class GenerateSecurityAuditJob implements AsyncJobInterface
{
    public function __construct(
        private readonly SecurityAuditService $securityAuditService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $ctx->message('Generating security reports…');
        try {
            $result = $this->securityAuditService->generateReports();
        } catch (\Throwable $e) {
            $this->logger->error('Security report generation failed', ['exception' => $e]);
            throw new IoException('Security report generation failed; details in the application log.');
        }
        if (!$result['success']) {
            $this->logger->error('Security report generation failed', [
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
                'error_output' => $result['error_output'],
            ]);
            throw new IoException('Security report generation failed; details in the application log.');
        }
        $ctx->message('Security reports generated.');
    }
}
