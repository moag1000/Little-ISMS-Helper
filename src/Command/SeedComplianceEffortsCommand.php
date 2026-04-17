<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * WS-6: Seed consultant baseline effort (`baseEffortDays`) for the main
 * compliance frameworks (ISO 27001, NIS2, DORA).
 *
 * The per-priority defaults are intentionally conservative:
 *   critical => 12 person-days
 *   high     => 8
 *   medium   => 4
 *   low      => 2
 *
 * Requirements that already have a value are skipped so re-runs are safe.
 * A `--force` flag overrides existing values.
 */
#[AsCommand(
    name: 'app:compliance:seed-efforts',
    description: 'WS-6: Seed baseline effort (person-days) for ISO27001/NIS2/DORA requirements',
)]
class SeedComplianceEffortsCommand extends Command
{
    /**
     * Framework code => priority => default person-days.
     *
     * Values sourced from typical implementation projects; intended to be
     * reviewed by the consultant team once real customer data arrives.
     *
     * @var array<string, array<string, int>>
     */
    private const DEFAULT_EFFORTS = [
        'ISO27001' => [
            'critical' => 12,
            'high' => 8,
            'medium' => 4,
            'low' => 2,
        ],
        'NIS2' => [
            'critical' => 15,
            'high' => 10,
            'medium' => 5,
            'low' => 3,
        ],
        'DORA' => [
            'critical' => 15,
            'high' => 10,
            'medium' => 5,
            'low' => 3,
        ],
        'TISAX' => [
            'critical' => 10,
            'high' => 6,
            'medium' => 4,
            'low' => 2,
        ],
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'framework',
                'f',
                InputOption::VALUE_REQUIRED,
                'Only seed a single framework code (e.g. ISO27001)',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite existing baseEffortDays values (default: skip)',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would change without persisting',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $filter = $input->getOption('framework');

        $frameworks = self::DEFAULT_EFFORTS;
        if ($filter !== null) {
            if (!isset($frameworks[$filter])) {
                $io->error(sprintf('Unknown framework code: %s', $filter));
                return Command::FAILURE;
            }
            $frameworks = [$filter => $frameworks[$filter]];
        }

        $totalUpdated = 0;
        $totalSkipped = 0;
        $totalMissing = 0;

        foreach ($frameworks as $code => $priorityMap) {
            $framework = $this->frameworkRepository->findOneBy(['code' => $code]);
            if ($framework === null) {
                $io->warning(sprintf('Framework %s not loaded in DB — skipping.', $code));
                $totalMissing++;
                continue;
            }

            $io->section(sprintf('Seeding %s', $code));

            $updated = 0;
            $skipped = 0;
            $unknown = 0;

            foreach ($framework->requirements as $requirement) {
                /** @var ComplianceRequirement $requirement */
                $priority = strtolower((string) $requirement->getPriority());
                $default = $priorityMap[$priority] ?? null;

                if ($default === null) {
                    $unknown++;
                    continue;
                }

                if ($requirement->getBaseEffortDays() !== null && !$force) {
                    $skipped++;
                    continue;
                }

                if (!$dryRun) {
                    $requirement->setBaseEffortDays($default);
                }
                $updated++;
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }

            $io->text([
                sprintf('  updated : %d', $updated),
                sprintf('  skipped : %d (already set — use --force to overwrite)', $skipped),
                sprintf('  unknown-priority : %d', $unknown),
            ]);

            $totalUpdated += $updated;
            $totalSkipped += $skipped;
        }

        $io->success(sprintf(
            '%sDone. Updated %d requirements, skipped %d%s.',
            $dryRun ? '[DRY-RUN] ' : '',
            $totalUpdated,
            $totalSkipped,
            $totalMissing > 0 ? sprintf(' (%d frameworks missing)', $totalMissing) : '',
        ));

        return Command::SUCCESS;
    }
}
