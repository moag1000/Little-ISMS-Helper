<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Repository\ComplianceCertificateRepository;
use App\Repository\TenantRepository;
use App\Service\Evidence\EvidenceCascadeInvalidationService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daily evidence housekeeping (ISO 27001 7.5.3): fulfilments whose evidence
 * passed its review date are flagged for re-review, and active certificates
 * whose validity lapsed are marked expired. Formerly part of
 * app:review:send-reminders; the reminders themselves are app:send-reminders.
 */
#[AsCommand(
    name: 'app:evidence:expire',
    description: 'Flag fulfilments with expired evidence for re-review and mark lapsed certificates expired',
)]
final class ExpireEvidenceCommand
{
    public function __construct(
        private readonly EvidenceCascadeInvalidationService $evidence,
        private readonly TenantRepository $tenants,
        private readonly TenantContext $tenantContext,
        private readonly ComplianceCertificateRepository $certificates,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Count without changing anything', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $io->title('Evidence Expiry (ISO 27001 7.5.3)');
        $now = new DateTimeImmutable();
        $flagged = 0;
        $expired = 0;
        // The audit log takes the tenant from TenantContext, which is empty in CLI:
        // set it per tenant so every row lands in its organisation's trail.
        try {
            foreach ($this->tenants->findActive() as $tenant) {
                $this->tenantContext->setCurrentTenant($tenant);
                if ($dryRun) {
                    $expired += count($this->certificates->findExpiredActive($tenant, $now));
                    continue;
                }
                $flagged += $this->evidence->flagExpiredEvidence($tenant);
                $expired += $this->expireLapsedCertificates($tenant, $now);
            }
        } finally {
            $this->tenantContext->setCurrentTenant(null);
        }

        $io->success(sprintf(
            '%s %d fulfilment(s) for re-review, %d certificate(s) expired.',
            $dryRun ? 'Would flag' : 'Flagged',
            $flagged,
            $expired,
        ));

        return Command::SUCCESS;
    }

    private function expireLapsedCertificates(Tenant $tenant, DateTimeImmutable $now): int
    {
        $lapsed = $this->certificates->findExpiredActive($tenant, $now);
        foreach ($lapsed as $certificate) {
            $certificate->setStatus('expired');
        }
        if ($lapsed !== []) {
            $this->entityManager->flush();
        }

        return count($lapsed);
    }
}
