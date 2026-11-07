<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\Compliance\Fulfillment\FulfillmentDerivationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nightly catch-up of the fulfilment rule (Paket 3): recomputes every
 * framework an organisation works with from its controls, approved mappings
 * and maturity. Control changes recompute live; this run picks up catalogue
 * and mapping changes and, on its first run after the update, turns old hand
 * values that differ from the rule into overrides "Altbestand".
 *
 * Runs from the scheduler at 00:45, before the KPI and portfolio snapshots.
 */
#[AsCommand(
    name: 'app:compliance:refresh-fulfillments',
    description: 'Recompute requirement fulfilment from controls, mappings and maturity',
)]
final class RefreshFulfillmentsCommand
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly FulfillmentDerivationService $derivation,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only this organisation (id)')] ?int $tenant = null,
    ): int {
        $tenants = $tenant !== null
            ? array_filter([$this->tenantRepository->find($tenant)])
            : $this->tenantRepository->findActive();

        $rows = [];
        foreach ($tenants as $t) {
            if (!$t instanceof Tenant) {
                continue;
            }
            $r = $this->derivation->refreshTenant($t);
            $rows[] = [$t->getId(), $t->getName(), $r['derived'], $r['overridden'], $r['manual'], $r['changed']];
        }

        $io->table(['Tenant', 'Name', 'Rule', 'Override', 'Manual', 'Changed'], $rows);
        $io->success('Fulfilment recomputed.');

        return Command::SUCCESS;
    }
}
