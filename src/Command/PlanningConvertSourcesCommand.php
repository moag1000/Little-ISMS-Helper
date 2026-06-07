<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Service\Planning\Source\ActionItemConversionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Convert actionable source items (CorrectiveAction, AuditFinding, …) into
 * ActionItems (Maßnahmen) for every tenant that has enabled the respective
 * source. Idempotent — safe to run on a cron schedule.
 */
#[AsCommand(
    name: 'app:planning:convert-sources',
    description: 'Auto-convert enabled source items into ActionItems (Maßnahmen)',
)]
final class PlanningConvertSourcesCommand extends Command
{
    public function __construct(
        private readonly ActionItemConversionService $conversionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Only this tenant id (default: all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantId = $input->getOption('tenant');

        $tenantRepo = $this->entityManager->getRepository(Tenant::class);
        $tenants = $tenantId !== null
            ? array_filter([$tenantRepo->find((int) $tenantId)])
            : $tenantRepo->findAll();

        if ($tenants === []) {
            $io->warning('No matching tenant found.');
            return Command::SUCCESS;
        }

        $grandTotal = 0;
        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            $created = $this->conversionService->convertForTenant($tenant);
            $sum = array_sum($created);
            $grandTotal += $sum;
            if ($sum > 0) {
                $io->writeln(sprintf(
                    'Tenant #%s: %d created (%s)',
                    (string) $tenant->getId(),
                    $sum,
                    implode(', ', array_map(
                        static fn (string $slug, int $n): string => "$slug=$n",
                        array_keys($created),
                        array_values($created),
                    )),
                ));
            }
        }

        $io->success(sprintf('Converted %d source item(s) into Maßnahmen across %d tenant(s).', $grandTotal, count($tenants)));

        return Command::SUCCESS;
    }
}
