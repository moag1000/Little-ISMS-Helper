<?php

declare(strict_types=1);

namespace App\Command\Bsi;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Service\Bsi\IsoToBsiGapService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mapping quality report — tier distribution for a source↔target framework pair.
 *
 * Prints tier distribution (amtlich / amtlich_gestuetzt / ki_validiert / heuristisch /
 * needs_review / deprecated) + completeness note.
 *
 * ## Usage
 *   php bin/console app:bsi:mapping-quality-report --source=ISO27001 --target=BSI_GRUNDSCHUTZ
 *   php bin/console app:bsi:mapping-quality-report --source=NIS2 --target=BSI_GRUNDSCHUTZ
 */
#[AsCommand(
    name: 'app:bsi:mapping-quality-report',
    description: 'Print tier distribution (amtlich / ki_validiert / heuristisch / …) for a framework-pair mapping set.'
)]
final class MappingQualityReportCommand extends Command
{
    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly IsoToBsiGapService $gapService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Source framework code (e.g. ISO27001 or NIS2).',
            )
            ->addOption(
                'target',
                null,
                InputOption::VALUE_REQUIRED,
                'Target framework code (e.g. BSI_GRUNDSCHUTZ).',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $sourceCode */
        $sourceCode = $input->getOption('source');
        /** @var string|null $targetCode */
        $targetCode = $input->getOption('target');

        if ($sourceCode === null || $targetCode === null) {
            $io->error('Both --source and --target options are required.');
            return Command::FAILURE;
        }

        $source = $this->frameworkRepository->findOneBy(['code' => $sourceCode]);
        $target = $this->frameworkRepository->findOneBy(['code' => $targetCode]);

        if ($source === null) {
            $io->error(sprintf('Source framework not found: %s', $sourceCode));
            return Command::FAILURE;
        }

        if ($target === null) {
            $io->error(sprintf('Target framework not found: %s', $targetCode));
            return Command::FAILURE;
        }

        // Load all global mappings for the pair
        $allMappings = $this->mappingRepository->findAllGlobal();

        $tiers         = [];
        $operationalCount = 0;
        $total         = 0;

        foreach ($allMappings as $mapping) {
            $srcFw = $mapping->getSourceRequirement()?->getFramework();
            $tgtFw = $mapping->getTargetRequirement()?->getFramework();

            if ($srcFw === null || $tgtFw === null) {
                continue;
            }
            if ($srcFw->getId() !== $source->getId() || $tgtFw->getId() !== $target->getId()) {
                continue;
            }

            $total++;

            $lifecycleState = $mapping->getLifecycleState();

            // Deprecated: track separately (not operational)
            if ($lifecycleState === 'deprecated') {
                $tiers['deprecated'] = ($tiers['deprecated'] ?? 0) + 1;
                continue;
            }

            // Needs-review: flag operational-but-flagged
            if ($mapping->isRequiresReview() && $mapping->getReviewStatus() === 'needs_review') {
                $tiers['needs_review'] = ($tiers['needs_review'] ?? 0) + 1;
                $operationalCount++;
                continue;
            }

            // Use trustOf() for all others
            $tier = $this->gapService->trustOf($mapping);
            $tiers[$tier] = ($tiers[$tier] ?? 0) + 1;

            if ($mapping->isOperational()) {
                $operationalCount++;
            }
        }

        // Sort by trust-tier quality order
        $tierOrder = [
            IsoToBsiGapService::TIER_AMTLICH,
            IsoToBsiGapService::TIER_AMTLICH_GESTUETZT,
            IsoToBsiGapService::TIER_KI_VALIDIERT,
            IsoToBsiGapService::TIER_BESTAETIGT,
            IsoToBsiGapService::TIER_HEURISTISCH,
            'needs_review',
            'deprecated',
        ];

        $rows = [];
        foreach ($tierOrder as $tier) {
            $count = $tiers[$tier] ?? 0;
            if ($count > 0 || in_array($tier, [IsoToBsiGapService::TIER_KI_VALIDIERT, IsoToBsiGapService::TIER_HEURISTISCH], true)) {
                $rows[] = [$tier, $count, $total > 0 ? round($count / $total * 100, 1) . ' %' : '—'];
            }
        }
        // Any unexpected tiers
        foreach ($tiers as $tier => $count) {
            if (!in_array($tier, $tierOrder, true)) {
                $rows[] = [$tier, $count, $total > 0 ? round($count / $total * 100, 1) . ' %' : '—'];
            }
        }

        $io->title(sprintf('Mapping quality report: %s → %s', $sourceCode, $targetCode));

        $io->table(
            ['Trust tier', 'Count', 'Share'],
            $rows,
        );

        $io->table(
            ['Metric', 'Value'],
            [
                ['Total mappings (all states)',    $total],
                ['Operational mappings',           $operationalCount],
                ['amtlich_source',                 $sourceCode === 'NIS2'
                    ? 'none (BSI publishes no public NIS2↔Grundschutz crosswalk)'
                    : 'BSI Cross-Reference-Table (CRT)'],
            ]
        );

        // Completeness note
        $kiValidiert = $tiers[IsoToBsiGapService::TIER_KI_VALIDIERT] ?? 0;
        $heuristisch = $tiers[IsoToBsiGapService::TIER_HEURISTISCH] ?? 0;
        $panelDiscovered = 0;
        foreach ($allMappings as $mapping) {
            $srcFw = $mapping->getSourceRequirement()?->getFramework();
            $tgtFw = $mapping->getTargetRequirement()?->getFramework();
            if ($srcFw === null || $tgtFw === null) {
                continue;
            }
            if ($srcFw->getId() !== $source->getId() || $tgtFw->getId() !== $target->getId()) {
                continue;
            }
            if (
                $mapping->getProvenanceSource() === 'panel'
                && $mapping->getReviewNotes() === 'panel_discovered'
                && $mapping->getLifecycleState() === 'approved'
            ) {
                $panelDiscovered++;
            }
        }

        if ($panelDiscovered > 0) {
            $io->info(sprintf(
                'Completeness: %d panel_discovered mapping(s) exist (provenanceSource=panel, reviewNotes=panel_discovered).',
                $panelDiscovered,
            ));
        }

        if ($heuristisch > 0) {
            $io->warning(sprintf(
                '%d heuristic mapping(s) remain unreviewed. Run app:bsi:apply-panel-verdicts to elevate.',
                $heuristisch,
            ));
        } else {
            $io->success(sprintf(
                'No unreviewed heuristic mappings remain. '
                . '%d ki_validiert, %d panel_discovered, %d deprecated.',
                $kiValidiert,
                $panelDiscovered,
                $tiers['deprecated'] ?? 0,
            ));
        }

        return Command::SUCCESS;
    }
}
