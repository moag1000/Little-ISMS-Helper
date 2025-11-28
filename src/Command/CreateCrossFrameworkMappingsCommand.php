<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-cross-framework-mappings',
    description: 'Create comprehensive bidirectional cross-framework compliance mappings for data reuse'
)]
class CreateCrossFrameworkMappingsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all existing mappings before creating new ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $symfonyStyle->title('Creating Comprehensive Cross-Framework Compliance Mappings');

        if ($input->getOption('clear')) {
            $symfonyStyle->section('Clearing existing mappings');
            $this->clearExistingMappings();
            $symfonyStyle->success('Existing mappings cleared');
        }

        // Get all frameworks
        $frameworks = $this->entityManager->getRepository(ComplianceFramework::class)->findAll();
        $iso27001Framework = array_find($frameworks, fn($framework): bool => $framework->getCode() === 'ISO27001');

        if (!$iso27001Framework) {
            $symfonyStyle->error('ISO 27001 framework not found! Please run app:load-iso27001-requirements first.');
            return Command::FAILURE;
        }

        $symfonyStyle->section('Step 1: Creating mappings from other frameworks TO ISO 27001');
        $this->createMappingsToIso27001($iso27001Framework, $symfonyStyle);

        $symfonyStyle->section('Step 2: Creating reverse mappings FROM ISO 27001 to other frameworks');
        $this->createMappingsFromIso27001($iso27001Framework, $symfonyStyle);

        $symfonyStyle->section('Step 3: Creating transitive mappings between frameworks (via ISO 27001)');
        $this->createTransitiveMappings($symfonyStyle);

        $this->entityManager->flush();

        $totalMappings = $this->entityManager->getRepository(ComplianceMapping::class)->count([]);
        $symfonyStyle->success(sprintf('Successfully created %d cross-framework compliance mappings!', $totalMappings));
        $symfonyStyle->note('These mappings enable automatic data reuse across frameworks.');

        return Command::SUCCESS;
    }

    private function clearExistingMappings(): void
    {
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder->delete(ComplianceMapping::class, 'm');
        $queryBuilder->getQuery()->execute();
    }

    private function createMappingsToIso27001(ComplianceFramework $complianceFramework, SymfonyStyle $symfonyStyle): void
    {
        $frameworks = $this->entityManager->getRepository(ComplianceFramework::class)->findAll();
        $mappingCount = 0;

        foreach ($frameworks as $framework) {
            if ($framework->getCode() === 'ISO27001') {
                continue; // Skip ISO27001 itself
            }

            $symfonyStyle->writeln(sprintf('  Processing %s...', $framework->getName()));

            $requirements = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findBy(['framework' => $framework]);

            foreach ($requirements as $requirement) {
                $dataSourceMapping = $requirement->getDataSourceMapping();
                if (empty($dataSourceMapping)) {
                    continue;
                }
                if (empty($dataSourceMapping['iso_controls'])) {
                    continue;
                }

                $isoControls = $dataSourceMapping['iso_controls'];
                if (!is_array($isoControls)) {
                    $isoControls = [$isoControls];
                }

                foreach ($isoControls as $isoControl) {
                    // Normalize control ID (remove 'A.' prefix if present, then add it back)
                    $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $isoControl);

                    // Find corresponding ISO 27001 requirement
                    $isoRequirement = $this->entityManager->getRepository(ComplianceRequirement::class)
                        ->findOneBy([
                            'framework' => $complianceFramework,
                            'requirementId' => $normalizedId
                        ]);

                    if (!$isoRequirement instanceof ComplianceRequirement) {
                        // Try without the 'A.' prefix
                        $isoRequirement = $this->entityManager->getRepository(ComplianceRequirement::class)
                            ->findOneBy([
                                'framework' => $complianceFramework,
                                'requirementId' => $isoControl
                            ]);
                    }

                    if ($isoRequirement instanceof ComplianceRequirement) {
                        // Calculate mapping strength based on number of controls mapped
                        $mappingPercentage = $this->calculateMappingPercentage($requirement, $isoControls);
                        $mappingType = $this->getMappingType($mappingPercentage);

                        // Create mapping: Framework Requirement -> ISO 27001 Control
                        $mapping = new ComplianceMapping();
                        $mapping->setSourceRequirement($requirement)
                            ->setTargetRequirement($isoRequirement)
                            ->setMappingPercentage($mappingPercentage)
                            ->setMappingType($mappingType)
                            ->setBidirectional(true)
                            ->setConfidence($this->determineConfidence($framework->getCode()))
                            ->setMappingRationale(sprintf(
                                '%s requirement mapped to ISO 27001 %s based on data source mapping',
                                $framework->getCode(),
                                $normalizedId
                            ));

                        $this->entityManager->persist($mapping);
                        $mappingCount++;
                    }
                }
            }

            $symfonyStyle->writeln(sprintf('    Created %d mappings', $mappingCount));
        }
    }

    private function createMappingsFromIso27001(ComplianceFramework $complianceFramework, SymfonyStyle $symfonyStyle): void
    {
        $frameworks = $this->entityManager->getRepository(ComplianceFramework::class)->findAll();
        $mappingCount = 0;

        $isoRequirements = $this->entityManager->getRepository(ComplianceRequirement::class)
            ->findBy(['framework' => $complianceFramework]);

        foreach ($isoRequirements as $isoRequirement) {
            foreach ($frameworks as $framework) {
                if ($framework->getCode() === 'ISO27001') {
                    continue;
                }

                // Find all requirements in target framework that map to this ISO control
                $targetRequirements = $this->entityManager->getRepository(ComplianceRequirement::class)
                    ->findBy(['framework' => $framework]);

                foreach ($targetRequirements as $targetRequirement) {
                    $dataSourceMapping = $targetRequirement->getDataSourceMapping();
                    if (empty($dataSourceMapping)) {
                        continue;
                    }
                    if (empty($dataSourceMapping['iso_controls'])) {
                        continue;
                    }

                    $isoControls = $dataSourceMapping['iso_controls'];
                    if (!is_array($isoControls)) {
                        $isoControls = [$isoControls];
                    }

                    // Check if this ISO requirement is in the target's mapped controls
                    $isoReqId = $isoRequirement->getRequirementId();
                    $matched = false;
                    foreach ($isoControls as $isoControl) {
                        $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $isoControl);
                        if ($normalizedId === $isoReqId || $isoControl === $isoReqId || str_replace('A.', '', $isoControl) === str_replace('A.', '', $isoReqId)) {
                            $matched = true;
                            break;
                        }
                    }

                    if ($matched) {
                        // Check if reverse mapping already exists
                        $existingMapping = $this->entityManager->getRepository(ComplianceMapping::class)
                            ->findOneBy([
                                'sourceRequirement' => $isoRequirement,
                                'targetRequirement' => $targetRequirement
                            ]);

                        if (!$existingMapping instanceof ComplianceMapping) {
                            $mappingPercentage = $this->calculateReverseMappingPercentage(count($isoControls));
                            $mappingType = $this->getMappingType($mappingPercentage);

                            $mapping = new ComplianceMapping();
                            $mapping->setSourceRequirement($isoRequirement)
                                ->setTargetRequirement($targetRequirement)
                                ->setMappingPercentage($mappingPercentage)
                                ->setMappingType($mappingType)
                                ->setBidirectional(true)
                                ->setConfidence($this->determineConfidence($framework->getCode()))
                                ->setMappingRationale(sprintf(
                                    'ISO 27001 %s supports %s requirement %s',
                                    $isoReqId,
                                    $framework->getCode(),
                                    $targetRequirement->getRequirementId()
                                ));

                            $this->entityManager->persist($mapping);
                            $mappingCount++;
                        }
                    }
                }
            }
        }

        $symfonyStyle->writeln(sprintf('  Created %d reverse mappings', $mappingCount));
    }

    private function createTransitiveMappings(SymfonyStyle $symfonyStyle): void
    {
        $frameworks = $this->entityManager->getRepository(ComplianceFramework::class)->findAll();
        $mappingCount = 0;

        // Create transitive mappings: if Framework A -> ISO 27001 Control X, and Framework B -> ISO 27001 Control X
        // Then create Framework A <-> Framework B mapping

        foreach ($frameworks as $framework) {
            if ($framework->getCode() === 'ISO27001') {
                continue;
            }

            foreach ($frameworks as $targetFramework) {
                if ($targetFramework->getCode() === 'ISO27001') {
                    continue;
                }
                if ($framework->id === $targetFramework->id) {
                    continue;
                }
                $symfonyStyle->writeln(sprintf('  Processing %s <-> %s...', $framework->getCode(), $targetFramework->getCode()));

                $sourceRequirements = $this->entityManager->getRepository(ComplianceRequirement::class)
                    ->findBy(['framework' => $framework]);

                foreach ($sourceRequirements as $sourceRequirement) {
                    $sourceMapping = $sourceRequirement->getDataSourceMapping();
                    if (empty($sourceMapping)) {
                        continue;
                    }
                    if (empty($sourceMapping['iso_controls'])) {
                        continue;
                    }

                    $sourceIsoControls = $sourceMapping['iso_controls'];
                    if (!is_array($sourceIsoControls)) {
                        $sourceIsoControls = [$sourceIsoControls];
                    }

                    $targetRequirements = $this->entityManager->getRepository(ComplianceRequirement::class)
                        ->findBy(['framework' => $targetFramework]);

                    foreach ($targetRequirements as $targetRequirement) {
                        $targetMapping = $targetRequirement->getDataSourceMapping();
                        if (empty($targetMapping)) {
                            continue;
                        }
                        if (empty($targetMapping['iso_controls'])) {
                            continue;
                        }

                        $targetIsoControls = $targetMapping['iso_controls'];
                        if (!is_array($targetIsoControls)) {
                            $targetIsoControls = [$targetIsoControls];
                        }

                        // Find common ISO controls
                        $commonControls = array_intersect(
                            array_map(fn($c): string|array => str_replace('A.', '', $c), $sourceIsoControls),
                            array_map(fn($c): string|array => str_replace('A.', '', $c), $targetIsoControls)
                        );

                        if ($commonControls !== []) {
                            // Check if mapping already exists
                            $existingMapping = $this->entityManager->getRepository(ComplianceMapping::class)
                                ->findOneBy([
                                    'sourceRequirement' => $sourceRequirement,
                                    'targetRequirement' => $targetRequirement
                                ]);

                            if (!$existingMapping instanceof ComplianceMapping) {
                                // Calculate mapping strength based on overlap
                                $overlapPercentage = (count($commonControls) / max(count($sourceIsoControls), count($targetIsoControls))) * 100;
                                $mappingPercentage = min(100, (int)$overlapPercentage);
                                $mappingType = $this->getMappingType($mappingPercentage);

                                $mapping = new ComplianceMapping();
                                $mapping->setSourceRequirement($sourceRequirement)
                                    ->setTargetRequirement($targetRequirement)
                                    ->setMappingPercentage($mappingPercentage)
                                    ->setMappingType($mappingType)
                                    ->setBidirectional(true)
                                    ->setConfidence('medium')
                                    ->setMappingRationale(sprintf(
                                        'Transitive mapping via shared ISO 27001 controls: %s',
                                        implode(', ', array_map(fn($c): string => 'A.' . $c, $commonControls))
                                    ));

                                $this->entityManager->persist($mapping);
                                $mappingCount++;
                            }
                        }
                    }
                }
            }
        }

        $symfonyStyle->writeln(sprintf('  Created %d transitive mappings', $mappingCount));
    }

    private function calculateMappingPercentage(ComplianceRequirement $complianceRequirement, array $isoControls): int
    {
        // Base mapping percentage on:
        // - Number of ISO controls mapped (more = higher specificity but potentially lower per-control coverage)
        // - Priority of the requirement

        $basePercentage = 85; // Default high confidence mapping

        if ($complianceRequirement->getPriority() === 'critical') {
            $basePercentage = 95;
        } elseif ($complianceRequirement->getPriority() === 'high') {
            $basePercentage = 90;
        } elseif ($complianceRequirement->getPriority() === 'medium') {
            $basePercentage = 80;
        } else {
            $basePercentage = 70;
        }

        // Adjust based on number of controls - more controls might mean broader scope
        $controlCount = count($isoControls);
        if ($controlCount === 1) {
            // Single control mapping - very specific, high confidence
            $basePercentage = min(100, $basePercentage + 5);
        } elseif ($controlCount > 5) {
            // Many controls - broader requirement
            $basePercentage = max(60, $basePercentage - 10);
        }

        return $basePercentage;
    }

    private function calculateReverseMappingPercentage(int $targetControlCount): int
    {
        // When mapping FROM ISO 27001 TO other frameworks
        // One ISO control might partially satisfy a broader requirement

        if ($targetControlCount === 1) {
            return 100; // 1:1 mapping
        } elseif ($targetControlCount <= 3) {
            return 75; // ISO control partially satisfies multi-control requirement
        } else {
            return 50; // ISO control is one of many needed
        }
    }

    private function getMappingType(int $percentage): string
    {
        if ($percentage >= 100) {
            return 'full';
        }
        if ($percentage >= 75) {
            return 'partial';
        }
        else {
            return 'weak';
        }
    }

    private function determineConfidence(string $frameworkCode): string
    {
        // Confidence levels based on framework maturity and mapping quality
        $highConfidence = ['TISAX', 'NIS2', 'DORA', 'GDPR', 'NIST-CSF', 'CIS-CONTROLS', 'SOC2'];
        if (in_array($frameworkCode, $highConfidence)) {
            return 'high';
        }

        return 'medium';
    }
}
