<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuditLogIntegrityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AUD-02: Verifies audit-log HMAC chain for tampering/deletion.
 * Return code 0 on success, 1 on any integrity violation.
 */
#[AsCommand(
    name: 'app:audit-log:verify',
    description: 'Verify HMAC chain of audit_log (AUD-02)'
)]
class AuditLogVerifyCommand
{
    public function __construct(private readonly AuditLogIntegrityService $integrityService)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        $symfonyStyle->title('Audit-Log Integrity Verification (AUD-02)');

        if (!$this->integrityService->isEnabled()) {
            $symfonyStyle->warning('APP_AUDIT_HMAC_KEY is not set — signing is disabled.');
            return Command::FAILURE;
        }

        // Every chain: one per organisation plus the instance chain (B3).
        $report = $this->integrityService->verifyChain(allChains: true);
        $issues = $report->issues;
        if ($report->unverifiable > 0) {
            $symfonyStyle->note(sprintf(
                '%d older rows were signed with microseconds the database never stored: their content cannot be '
                . 're-checked, their chain links were (B3).',
                $report->unverifiable,
            ));
        }

        if ($report->isIntact()) {
            $symfonyStyle->success(sprintf('Audit-log chain intact — %d rows checked, no tampering detected.', $report->checked));
            return Command::SUCCESS;
        }

        $symfonyStyle->error(sprintf('Integrity violations detected: %d', count($issues)));
        $symfonyStyle->table(
            ['AuditLog ID', 'Reason'],
            array_map(static fn (array $i): array => [$i['id'], $i['reason']], $issues)
        );
        return Command::FAILURE;
    }
}
