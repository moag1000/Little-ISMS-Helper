<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Service\Tisax\TisaxFulfillmentSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Materialise ComplianceRequirementFulfillment from the imported TISAX maturity
 * for existing tenants, so catalogue coverage / SoA / inheritance reflect the
 * assessment. New imports + the consolidation do this automatically; this command
 * back-fills assessments imported before the fulfilment bridge existed.
 */
#[AsCommand(
    name: 'app:tisax:sync-fulfillment',
    description: 'Materialise ComplianceRequirementFulfillment from imported TISAX maturity',
)]
final class TisaxSyncFulfillmentCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TisaxFulfillmentSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('framework', null, InputOption::VALUE_REQUIRED, 'Framework code', 'TISAX');
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restrict to a tenant id (default: all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $framework = $this->em->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => (string) $input->getOption('framework')]);
        if ($framework === null) {
            $io->error('Framework not found: ' . $input->getOption('framework'));
            return Command::FAILURE;
        }

        $tenantOpt = $input->getOption('tenant');
        $tenants = $tenantOpt !== null
            ? array_filter([$this->em->getRepository(Tenant::class)->find((int) $tenantOpt)])
            : $this->tenantsWithUploads($framework);

        if ($tenants === []) {
            $io->warning('No tenants with TISAX assessments found.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            $r = $this->sync->sync($framework, $tenant);
            $rows[] = [$tenant->getId(), $tenant->getName(), $r['synced'], $r['covered']];
        }
        $io->table(['Tenant', 'Name', 'Synced', 'Covered'], $rows);
        $io->success('Fulfilment synced from TISAX maturity.');

        return Command::SUCCESS;
    }

    /**
     * @return list<Tenant>
     */
    private function tenantsWithUploads(ComplianceFramework $framework): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT upload_tenant_id FROM compliance_requirement '
            . 'WHERE framework_id = :fw AND upload_tenant_id IS NOT NULL',
            ['fw' => $framework->getId()],
        );
        $repo = $this->em->getRepository(Tenant::class);
        return array_values(array_filter(array_map(static fn ($id) => $repo->find((int) $id), $ids)));
    }
}
