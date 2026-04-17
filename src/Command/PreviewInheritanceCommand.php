<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ComplianceFrameworkRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\ComplianceInheritanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:compliance:preview-inheritance',
    description: 'Dry-run the mapping-based inheritance suggestion generation for a tenant and framework.',
)]
final class PreviewInheritanceCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly UserRepository $userRepository,
        private readonly ComplianceInheritanceService $inheritanceService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant ID')
            ->addOption('framework', null, InputOption::VALUE_REQUIRED, 'Framework code (e.g. NIS2)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output format: table|json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantId = (int) $input->getOption('tenant');
        $frameworkCode = (string) $input->getOption('framework');
        if ($tenantId <= 0 || $frameworkCode === '') {
            $io->error('Both --tenant and --framework are required.');
            return Command::INVALID;
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            $io->error(sprintf('Tenant #%d not found.', $tenantId));
            return Command::FAILURE;
        }

        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if ($framework === null) {
            $io->error(sprintf('Framework %s not found.', $frameworkCode));
            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy([]);
        if ($user === null) {
            $io->error('No user available to attribute the preview run to.');
            return Command::FAILURE;
        }

        $io->title(sprintf('Dry-Run: %s inheritance for tenant #%d', $framework->getCode(), $tenant->getId()));

        $result = $this->inheritanceService->createInheritanceSuggestions($tenant, $framework, $user, dryRun: true);

        $rows = [];
        foreach ($result['logs'] as $log) {
            $req = $log->getFulfillment()?->getRequirement();
            $mapping = $log->getDerivedFromMapping();
            $source = $mapping?->getSourceRequirement();
            $rows[] = [
                $req?->getRequirementId() ?? '-',
                $source?->getRequirementId() ?? '-',
                $source?->getFramework()?->getCode() ?? '-',
                $mapping?->getMappingPercentage() ?? 0,
                $mapping?->getConfidence() ?? '-',
                $log->getSuggestedPercentage(),
            ];
        }

        if ($input->getOption('output') === 'json') {
            $output->writeln(json_encode([
                'created' => $result['created'],
                'skipped' => $result['skipped'],
                'suggestions' => array_map(
                    fn(array $r): array => [
                        'target' => $r[0],
                        'source' => $r[1],
                        'source_framework' => $r[2],
                        'mapping_percentage' => $r[3],
                        'confidence' => $r[4],
                        'suggested_percentage' => $r[5],
                    ],
                    $rows,
                ),
            ], JSON_PRETTY_PRINT));
        } else {
            $io->table(
                ['Target', 'Source', 'Src Framework', 'Mapping %', 'Confidence', 'Suggested %'],
                $rows,
            );
            $io->success(sprintf('Would create %d suggestions, skip %d (dry-run).', $result['created'], $result['skipped']));
        }

        return Command::SUCCESS;
    }
}
