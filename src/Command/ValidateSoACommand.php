<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Service\SoAReportService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * M-02: Validate SoA completeness. Exits non-zero on errors (missing
 * justifications), zero on pass / warnings only.
 */
#[AsCommand(
    name: 'app:soa:validate',
    description: 'Validate Statement of Applicability completeness for the current tenant'
)]
class ValidateSoACommand
{
    public function __construct(
        private readonly SoAReportService $soaReportService,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        #[Argument(description: 'Tenant code to validate (optional, defaults to current context)')] ?string $tenant = null,
        ?SymfonyStyle $symfonyStyle = null,
    ): int {
        if ($tenant !== null) {
            $tenantEntity = $this->entityManager->getRepository(Tenant::class)->findOneBy(['code' => $tenant]);
            if (!$tenantEntity instanceof Tenant) {
                $symfonyStyle?->error(sprintf('Tenant with code "%s" not found.', $tenant));
                return Command::FAILURE;
            }
            $this->tenantContext->setCurrentTenant($tenantEntity);
        }

        $issues = $this->soaReportService->validateSoACompleteness();

        if ($issues === []) {
            $symfonyStyle?->success('SoA is complete — no issues detected.');
            return Command::SUCCESS;
        }

        $errors = array_values(array_filter($issues, static fn(array $i): bool => $i['severity'] === 'error'));
        $warnings = array_values(array_filter($issues, static fn(array $i): bool => $i['severity'] === 'warning'));

        $symfonyStyle?->section(sprintf('Issues: %d error(s), %d warning(s)', count($errors), count($warnings)));
        $symfonyStyle?->table(
            ['Control', 'Rule', 'Severity'],
            array_map(
                static fn(array $i): array => [
                    $i['control_id'] . ' — ' . $i['control_name'],
                    $i['rule'],
                    $i['severity'],
                ],
                $issues
            )
        );

        return count($errors) > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
