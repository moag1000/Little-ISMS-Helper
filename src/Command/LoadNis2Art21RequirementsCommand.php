<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:load-nis2art21-requirements',
    description: 'Load NIS2 Art. 21(2)(a)-(j) as first-class ComplianceRequirement rows under the NIS2 framework'
)]
class LoadNis2Art21RequirementsCommand extends Command
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/library/frameworks/nis2-art21_v1.0.yaml';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'Update existing requirements instead of skipping them')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove all NIS2 Art. 21(2) requirements (rollback)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing to the database');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $update = (bool) $input->getOption('update');
        $remove = (bool) $input->getOption('remove');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('NIS2 Art. 21(2)(a)-(j) First-Class Catalogue Loader');

        if ($dryRun) {
            $io->note('DRY-RUN mode — no database writes will occur.');
        }

        if (!file_exists(self::FIXTURE_PATH)) {
            $io->error(sprintf('Fixture file not found: %s', self::FIXTURE_PATH));
            return Command::FAILURE;
        }

        $yaml = Yaml::parseFile(self::FIXTURE_PATH);
        $metadata = $yaml['metadata'] ?? [];
        $requirements = $yaml['requirements'] ?? [];

        $io->text(sprintf(
            'Fixture: %s (version %s, %d requirements)',
            $metadata['name'] ?? 'NIS2',
            $metadata['version'] ?? '1.0',
            count($requirements)
        ));

        $frameworkCode = $metadata['code'] ?? 'NIS2';
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => $frameworkCode]);

        if ($remove) {
            return $this->doRemove($io, $framework, $requirements, $dryRun);
        }

        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
            $io->text('Framework does not exist yet — will create.');
        } else {
            $io->text('Framework already exists — reusing.');
        }

        if (!$dryRun) {
            $framework->setCode($frameworkCode)
                ->setName($metadata['name'] ?? 'NIS-2-Richtlinie (EU) 2022/2555')
                ->setDescription(sprintf(
                    'EU-Richtlinie %s. Transpositionsfrist: %s. Art. 21 (Cybersicherheitsmassnahmen), Art. 23 (Meldepflichten).',
                    $metadata['code'] ?? 'NIS2',
                    $metadata['transpositionDeadline'] ?? '2024-10-17'
                ))
                ->setVersion($metadata['version'] ?? '1.0')
                ->setApplicableIndustry('critical_infrastructure')
                ->setRegulatoryBody($metadata['body'] ?? 'EU Parlament und Rat')
                ->setMandatory(true)
                ->setScopeDescription(implode('; ', $metadata['applicableTo'] ?? []))
                ->setActive(true);

            if ($isNew) {
                $this->entityManager->persist($framework);
            }
            $this->entityManager->flush();
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $rows = [];

        foreach ($requirements as $reqData) {
            $controlId = $reqData['controlId'];
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy([
                    'framework' => $framework,
                    'requirementId' => $controlId,
                ]);

            $action = 'skip';
            if ($existing instanceof ComplianceRequirement) {
                if ($update) {
                    $action = 'update';
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } else {
                $action = 'create';
                $stats['created']++;
            }

            $rows[] = [
                $controlId,
                $reqData['clauseReference'],
                mb_substr($reqData['title'], 0, 60),
                $reqData['priority'],
                ucfirst($action),
            ];

            if ($dryRun) {
                continue;
            }

            if ($action === 'create') {
                $req = new ComplianceRequirement();
                $req->setFramework($framework)
                    ->setRequirementId($controlId)
                    ->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping([
                        'clause_reference'   => $reqData['clauseReference'],
                        'iso27001_anchors'   => $reqData['iso27001Anchors'] ?? [],
                        'audit_evidence_hint' => $reqData['auditEvidenceHint'] ?? null,
                        'source'             => 'NIS2-RL Art. 21(2)',
                        'loaded_by'          => 'app:load-nis2art21-requirements',
                    ]);
                $this->entityManager->persist($req);
            } elseif ($action === 'update' && $existing instanceof ComplianceRequirement) {
                $existing->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping([
                        'clause_reference'   => $reqData['clauseReference'],
                        'iso27001_anchors'   => $reqData['iso27001Anchors'] ?? [],
                        'audit_evidence_hint' => $reqData['auditEvidenceHint'] ?? null,
                        'source'             => 'NIS2-RL Art. 21(2)',
                        'loaded_by'          => 'app:load-nis2art21-requirements',
                    ]);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(
            ['Control ID', 'Clause', 'Title (truncated)', 'Priority', 'Action'],
            $rows
        );

        if ($dryRun) {
            $io->success(sprintf(
                'DRY-RUN complete. Would create %d, update %d, skip %d of %d requirements.',
                $stats['created'],
                $stats['updated'],
                $stats['skipped'],
                count($requirements)
            ));
        } else {
            $io->success(sprintf(
                'Done. Created: %d | Updated: %d | Skipped: %d | Total: %d',
                $stats['created'],
                $stats['updated'],
                $stats['skipped'],
                count($requirements)
            ));
        }

        return Command::SUCCESS;
    }

    private function doRemove(SymfonyStyle $io, ?ComplianceFramework $framework, array $requirements, bool $dryRun): int
    {
        $io->section('Roll-back: removing NIS2 Art. 21(2) requirements');

        if (!$framework instanceof ComplianceFramework) {
            $io->warning('NIS2 framework not found — nothing to remove.');
            return Command::SUCCESS;
        }

        $removed = 0;
        foreach ($requirements as $reqData) {
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy([
                    'framework' => $framework,
                    'requirementId' => $reqData['controlId'],
                ]);

            if (!$existing instanceof ComplianceRequirement) {
                continue;
            }

            $io->text(sprintf('  Removing %s — %s', $reqData['controlId'], $reqData['title']));
            if (!$dryRun) {
                $this->entityManager->remove($existing);
            }
            $removed++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $prefix = $dryRun ? '[DRY-RUN] Would remove' : 'Removed';
        $io->success(sprintf('%s %d NIS2 Art. 21(2) requirements.', $prefix, $removed));
        return Command::SUCCESS;
    }
}
