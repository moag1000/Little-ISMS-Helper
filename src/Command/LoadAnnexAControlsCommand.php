<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\AnnexAControlsBootstrapService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the 93 ISO 27001:2022 Annex A controls for each organisation.
 *
 * Controls are tenant-owned. This command used to create them without a tenant and to
 * update the first control with a matching id of ANY organisation (T2, Gate 72): the new
 * rows were invisible to everybody, the updates reset another organisation's controls to
 * "not applicable". It now delegates to AnnexAControlsBootstrapService, which upserts per
 * organisation and never touches an existing control.
 */
#[AsCommand(
    name: 'isms:load-annex-a-controls',
    description: 'Loads the ISO 27001:2022 Annex A controls for every organisation (or one with --tenant)',
)]
class LoadAnnexAControlsCommand
{
    public function __construct(
        private readonly AnnexAControlsBootstrapService $bootstrapService,
        private readonly TenantRepository $tenantRepository,
    ) {
    }

    public function __invoke(
        SymfonyStyle $symfonyStyle,
        #[Option(description: 'Code of one organisation; default: every organisation')]
        ?string $tenant = null,
    ): int {
        $symfonyStyle->title('Loading ISO 27001:2022 Annex A Controls');

        $tenants = $tenant !== null
            ? array_filter([$this->tenantRepository->findOneBy(['code' => $tenant])])
            : $this->tenantRepository->findAll();

        if ($tenants === []) {
            $symfonyStyle->note($tenant !== null
                ? sprintf('No organisation with code "%s".', $tenant)
                : 'No organisation yet. The controls are loaded when the first organisation opens the SoA.');

            return $tenant !== null ? Command::FAILURE : Command::SUCCESS;
        }

        foreach ($tenants as $org) {
            /** @var Tenant $org */
            $created = $this->bootstrapService->ensureLoadedForTenant($org);
            $symfonyStyle->writeln(sprintf('%s: %d new controls', $org->getName(), $created));
        }
        $symfonyStyle->success('Annex A controls loaded.');

        return Command::SUCCESS;
    }
}
