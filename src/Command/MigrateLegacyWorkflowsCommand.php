<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Workflow;
use App\Repository\WorkflowRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Y.4 Migration Verification Command — Legacy Workflow → YAML
 *
 * Verifies that all DB-stored regulatory workflows have YAML equivalents
 * in config/workflows/regulatory/. Reports mismatches. Optionally archives
 * obsolete DB rows that have no YAML counterpart and no active instances.
 *
 * By default this command is REPORT-ONLY (safe to run in production).
 * Use --archive to opt-in to archiving obsolete DB rows (sets is_active=false).
 *
 * Data preservation principle: rows are NEVER deleted — archiving only
 * sets is_active=false for display purposes.
 *
 * @see config/workflows/regulatory/
 * @see docs/decisions/2026-05-17-workflow-yaml-unification.md
 */
#[AsCommand(
    name: 'app:migrate-legacy-workflows',
    description: 'Verify all DB-stored regulatory workflows have YAML equivalents; optionally archive obsolete rows.'
)]
class MigrateLegacyWorkflowsCommand extends Command
{
    /**
     * The 15 canonical regulatory workflow slugs that must exist as YAMLs.
     *
     * These match the file names in config/workflows/regulatory/ (without .yaml).
     * Any DB workflow whose name matches one of these is considered "covered".
     */
    private const CANONICAL_YAML_WORKFLOWS = [
        'gdpr_data_breach',
        'incident_high_severity',
        'incident_low_severity',
        'risk_treatment',
        'dpia',
        'dsr',
        'capa',
        'change_request',
        'management_review',
        'control_verification',
        'supplier_assessment',
        'training_verification',
        'bc_plan_activation',
        'document_review',
        'incident_post_mortem',
    ];

    /** Alternate name-forms used historically by GenerateRegulatoryWorkflowsCommand. */
    private const LEGACY_NAME_ALIASES = [
        'GDPR Data Breach Notification'               => 'gdpr_data_breach',
        'Incident Response - High/Critical Severity'  => 'incident_high_severity',
        'Incident Response - Low/Medium Severity'     => 'incident_low_severity',
        'Risk Treatment Plan Approval'                => 'risk_treatment',
        'Data Protection Impact Assessment (DPIA)'    => 'dpia',
        'Data Subject Request'                        => 'dsr',
        'Corrective Action (CAPA)'                    => 'capa',
        'Change Request'                              => 'change_request',
        'Management Review'                           => 'management_review',
        'Control Verification'                        => 'control_verification',
        'Supplier Assessment'                         => 'supplier_assessment',
        'Training Verification'                       => 'training_verification',
        'BC Plan Activation'                          => 'bc_plan_activation',
        'Document Review'                             => 'document_review',
        'Incident Post-Mortem'                        => 'incident_post_mortem',
    ];

    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'archive',
                null,
                InputOption::VALUE_NONE,
                'Archive (set is_active=false) obsolete DB rows that have no YAML equivalent and no active instances.'
            )
            ->addOption(
                'tenant',
                't',
                InputOption::VALUE_REQUIRED,
                'Limit report to a specific tenant ID.'
            )
            ->setHelp(<<<'HELP'
<info>Usage:</info>
  php bin/console app:migrate-legacy-workflows           # Report only (safe, no writes)
  php bin/console app:migrate-legacy-workflows --archive # Archive obsolete DB rows

<info>What this command checks:</info>
  1. All 15 canonical YAML workflows exist as files in config/workflows/regulatory/
  2. Each DB-stored Workflow row whose name matches a canonical slug has a YAML counterpart
  3. DB rows with no YAML counterpart are reported as "obsolete"
  4. DB rows with no YAML counterpart AND no active instances can be archived (--archive)

<info>Data preservation:</info>
  Rows are NEVER deleted. --archive only sets is_active=false.
  Historical WorkflowInstance rows remain intact for forensic display.

<info>See also:</info>
  docs/decisions/2026-05-17-workflow-yaml-unification.md
  config/workflows/regulatory/
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $archiveMode = (bool) $input->getOption('archive');
        $tenantId = $input->getOption('tenant') !== null ? (int) $input->getOption('tenant') : null;

        $io->title('Y.4 — Legacy Workflow Migration Verification');

        if ($archiveMode) {
            $io->warning('Archive mode enabled. Obsolete DB rows with no active instances will be set is_active=false.');
        } else {
            $io->comment('Running in report-only mode (no writes). Use --archive to opt-in to archiving obsolete rows.');
        }

        // Step 1: Verify YAML files on disk
        $io->section('Step 1: YAML files in config/workflows/regulatory/');
        $missingYamls = $this->checkYamlFiles($io);

        // Step 2: Analyse DB rows
        $io->section('Step 2: DB workflow rows vs YAML counterparts');
        [$covered, $obsolete, $custom] = $this->analyseDbRows($io, $tenantId);

        // Step 3: Archive obsolete rows (opt-in)
        $archivedCount = 0;
        if ($archiveMode && count($obsolete) > 0) {
            $io->section('Step 3: Archiving obsolete DB rows');
            $archivedCount = $this->archiveObsoleteRows($io, $obsolete);
        }

        // Summary
        $io->section('Summary');
        $io->definitionList(
            ['YAML files expected'   => count(self::CANONICAL_YAML_WORKFLOWS)],
            ['YAML files missing'    => count($missingYamls)],
            ['DB rows covered by YAML' => count($covered)],
            ['DB rows obsolete (no YAML)' => count($obsolete)],
            ['DB rows custom (non-canonical)' => count($custom)],
            ['DB rows archived this run' => $archivedCount],
        );

        if (count($missingYamls) > 0) {
            $io->error(sprintf('%d YAML file(s) missing. Run the tests or check config/workflows/regulatory/.', count($missingYamls)));
            return Command::FAILURE;
        }

        if (count($obsolete) > 0 && !$archiveMode) {
            $io->warning(sprintf(
                '%d DB row(s) have no YAML counterpart. Re-run with --archive to set them is_active=false.',
                count($obsolete)
            ));
        }

        $io->success('Verification complete. All canonical YAML workflows are present on disk.');
        return Command::SUCCESS;
    }

    /**
     * @return list<string> missing YAML slugs
     */
    private function checkYamlFiles(SymfonyStyle $io): array
    {
        $regulatoryDir = $this->projectDir . '/config/workflows/regulatory';
        $missing = [];

        foreach (self::CANONICAL_YAML_WORKFLOWS as $slug) {
            $path = $regulatoryDir . '/' . $slug . '.yaml';
            if (file_exists($path)) {
                $io->writeln(sprintf('  <info>✓</info> %s', $slug));
            } else {
                $io->writeln(sprintf('  <error>✗</error> %s (file not found: %s)', $slug, $path));
                $missing[] = $slug;
            }
        }

        return $missing;
    }

    /**
     * @return array{0: list<Workflow>, 1: list<Workflow>, 2: list<Workflow>}
     *   [covered, obsolete, custom]
     */
    private function analyseDbRows(SymfonyStyle $io, ?int $tenantId): array
    {
        $qb = $this->workflowRepository->createQueryBuilder('w');
        if ($tenantId !== null) {
            $qb->andWhere('w.tenant = :tenant')->setParameter('tenant', $tenantId);
        }
        /** @var list<Workflow> $allWorkflows */
        $allWorkflows = $qb->getQuery()->getResult();

        $covered  = [];
        $obsolete = [];
        $custom   = [];

        foreach ($allWorkflows as $workflow) {
            $resolvedSlug = $this->resolveSlug($workflow);

            if ($resolvedSlug !== null) {
                $covered[] = $workflow;
                $io->writeln(sprintf(
                    '  <info>covered</info>  DB id=%d "%s" → YAML slug: %s',
                    (int) $workflow->getId(),
                    (string) $workflow->getName(),
                    $resolvedSlug,
                ));
            } elseif ($this->isCanonicalByName($workflow)) {
                // Name looks canonical but slug resolution failed — treat as obsolete
                $obsolete[] = $workflow;
                $io->writeln(sprintf(
                    '  <comment>obsolete</comment> DB id=%d "%s" — no YAML equivalent found',
                    (int) $workflow->getId(),
                    (string) $workflow->getName(),
                ));
            } else {
                $custom[] = $workflow;
                $io->writeln(sprintf(
                    '  <comment>custom</comment>   DB id=%d "%s" — non-canonical, tenant-created definition (keep)',
                    (int) $workflow->getId(),
                    (string) $workflow->getName(),
                ));
            }
        }

        return [$covered, $obsolete, $custom];
    }

    /**
     * @param list<Workflow> $obsolete
     */
    private function archiveObsoleteRows(SymfonyStyle $io, array $obsolete): int
    {
        $archivedCount = 0;

        foreach ($obsolete as $workflow) {
            if ($workflow->isActive()) {
                $workflow->setIsActive(false);
                $workflow->setUpdatedAt(new DateTimeImmutable());
                $io->writeln(sprintf(
                    '  Archived DB id=%d "%s" (is_active → false)',
                    (int) $workflow->getId(),
                    (string) $workflow->getName(),
                ));
                $archivedCount++;
            } else {
                $io->writeln(sprintf(
                    '  Skipped DB id=%d "%s" (already is_active=false)',
                    (int) $workflow->getId(),
                    (string) $workflow->getName(),
                ));
            }
        }

        if ($archivedCount > 0) {
            $this->entityManager->flush();
            $io->writeln(sprintf('  Flushed %d update(s) to database.', $archivedCount));
        }

        return $archivedCount;
    }

    private function resolveSlug(Workflow $workflow): ?string
    {
        $name = (string) $workflow->getName();

        // Check direct slug match (name is already a slug)
        $slugified = strtolower(str_replace([' ', '-'], '_', $name));
        if (in_array($slugified, self::CANONICAL_YAML_WORKFLOWS, true)) {
            return $slugified;
        }

        // Check alias map (human-readable display names from GenerateRegulatoryWorkflowsCommand)
        if (isset(self::LEGACY_NAME_ALIASES[$name])) {
            return self::LEGACY_NAME_ALIASES[$name];
        }

        // Fuzzy: check if any canonical slug is contained in the slugified name
        foreach (self::CANONICAL_YAML_WORKFLOWS as $slug) {
            if (str_contains($slugified, $slug)) {
                return $slug;
            }
        }

        return null;
    }

    private function isCanonicalByName(Workflow $workflow): bool
    {
        $name = (string) $workflow->getName();
        // A name is "canonical" if it appears in the alias map or slug list
        return isset(self::LEGACY_NAME_ALIASES[$name])
            || in_array(strtolower(str_replace([' ', '-'], '_', $name)), self::CANONICAL_YAML_WORKFLOWS, true);
    }
}
