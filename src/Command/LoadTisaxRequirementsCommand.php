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

/**
 * Loads the canonical VDA-ISA 6.0 control-NUMBER enumeration (80 controls) as the
 * single shared TISAX catalogue baseline.
 *
 * Licence premise: a control NUMBER is a non-copyrightable fact and IS shipped;
 * the ENX-licensed Volltext (Kontrollfrage / Ziel / Massnahme / Doku) is NEVER
 * shipped and arrives solely via the user's BYO workbook upload, matched onto
 * these rows by control number. These rows are therefore numbers + structure
 * only (placeholder titles) — they give a fresh tenant the full applicable
 * catalogue as Reifegrad-0 gaps, which the upload then fills.
 *
 * Source of truth: fixtures/library/frameworks/vda-isa-tisax-v6.yaml.
 *
 * This replaced the former hard-coded ACC-/INF- ISO-style seed (a parallel
 * pseudo-catalogue that polluted the framework and embedded copyrighted-looking
 * text). There is now ONE TISAX catalogue.
 */
#[AsCommand(
    name: 'app:load-tisax-requirements',
    description: 'Load the canonical VDA-ISA 6.0 control-number enumeration (80 controls, numbers only) as the TISAX catalogue baseline'
)]
class LoadTisaxRequirementsCommand extends Command
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/library/frameworks/vda-isa-tisax-v6.yaml';

    /** Human-readable dimension labels keyed by category code (publicly documented TISAX scope areas). */
    private const DIMENSION_LABEL = [
        'information_security' => 'Information Security',
        'prototype_protection' => 'Prototype Protection',
        'data_protection'      => 'Data Protection',
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('update', 'u', InputOption::VALUE_NONE, 'Update existing requirements instead of skipping them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $update = (bool) $input->getOption('update');

        if (!file_exists(self::FIXTURE_PATH)) {
            $io->error(sprintf('Fixture file not found: %s', self::FIXTURE_PATH));
            return Command::FAILURE;
        }

        $yaml = Yaml::parseFile(self::FIXTURE_PATH);
        $metadata = $yaml['metadata'] ?? [];
        $requirements = $yaml['requirements'] ?? [];

        if ($requirements === []) {
            $io->error('Fixture contains no requirements enumeration.');
            return Command::FAILURE;
        }

        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => $metadata['code'] ?? 'TISAX']);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
        }
        $framework->setCode($metadata['code'] ?? 'TISAX')
            ->setName($metadata['name'] ?? 'TISAX (VDA ISA 6.0)')
            ->setDescription('Information security assessment standard for the automotive industry (VDA ISA ' . ($metadata['version'] ?? '6.0') . '). Control numbers shipped; full text via licensed BYO workbook upload.')
            ->setVersion($metadata['version'] ?? '6.0')
            ->setApplicableIndustry('automotive')
            ->setRegulatoryBody($metadata['body'] ?? 'VDA / ENX Association')
            ->setMandatory(false)
            ->setScopeDescription('VDA-ISA 6.0 — Information Security, Prototype Protection, Data Protection')
            ->setActive(true);

        if ($isNew) {
            $this->entityManager->persist($framework);
        }
        $this->entityManager->flush();

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($requirements as $reqData) {
            $controlId = (string) $reqData['controlId'];
            $category = (string) ($reqData['category'] ?? 'information_security');
            $section = (string) ($reqData['section'] ?? '');
            // Numbers-only: title is a placeholder, NEVER the ENX Kontrollfrage.
            // description stays generic (no copyrighted content). The BYO import
            // overwrites title/description on the tenant-scoped row with the
            // licensed text. maturityTarget = 3 (established) is the uniform
            // VDA-ISA 6.0 target — a generic fact, not ENX content.
            $title = (string) ($reqData['title'] ?? ('VDA-ISA ' . $controlId));
            $description = sprintf(
                'VDA-ISA 6.0 %s (%s). Volltext (Kontrollfrage/Ziel/Massnahme) via lizenzierten Workbook-Upload.',
                $controlId,
                self::DIMENSION_LABEL[$category] ?? $category,
            );
            $dataSourceMapping = [
                'section'             => $section,
                'source'              => 'VDA-ISA 6.0 skeleton (numbers only, ENX-licence compliant)',
                'loaded_by'           => 'app:load-tisax-requirements',
                'maturityTargetSource' => 'vda_isa_default',
            ];

            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy([
                    'framework'     => $framework,
                    'requirementId' => $controlId,
                    'uploadTenant'  => null, // shared catalogue baseline only
                ]);

            if ($existing instanceof ComplianceRequirement) {
                if ($update) {
                    $existing->setTitle($title)
                        ->setDescription($description)
                        ->setCategory($category)
                        ->setRequirementType('core')
                        ->setPriority('medium')
                        ->setMaturityTarget('established')
                        ->setDataSourceMapping($dataSourceMapping);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
                continue;
            }

            $requirement = new ComplianceRequirement();
            $requirement->setFramework($framework)
                ->setRequirementId($controlId)
                ->setTitle($title)
                ->setDescription($description)
                ->setCategory($category)
                ->setRequirementType('core')
                ->setPriority('medium')
                ->setMaturityTarget('established')
                ->setDataSourceMapping($dataSourceMapping);
            $this->entityManager->persist($requirement);
            $stats['created']++;
        }

        $this->entityManager->flush();
        $io->success(sprintf(
            'TISAX catalogue (numbers only): %d created, %d updated, %d skipped (of %d).',
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
            count($requirements),
        ));
        return Command::SUCCESS;
    }
}
