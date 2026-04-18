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

        $issues = $this->integrityService->verifyChain();

        if ($issues === []) {
            $symfonyStyle->success('Audit-log chain intact — no tampering detected.');
            return Command::SUCCESS;
        }

        $symfonyStyle->error(sprintf('Integrity violations detected: %d', count($issues)));
        $symfonyStyle->table(
            ['AuditLog ID', 'Reason'],
            array_map(static fn(array $i): array => [$i['id'], $i['reason']], $issues)
        );
        return Command::FAILURE;
    }
}
