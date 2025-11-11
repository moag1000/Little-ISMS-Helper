<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceMapping;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:map-iso27701-versions',
    description: 'Create mappings between ISO 27701:2019 and ISO 27701:2025 requirements'
)]
class MapIso27701VersionsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ComplianceFrameworkRepository $frameworkRepository,
        private ComplianceRequirementRepository $requirementRepository,
        private ComplianceMappingRepository $mappingRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get both frameworks
        $framework2019 = $this->frameworkRepository->findOneBy(['code' => 'ISO27701']);
        $framework2025 = $this->frameworkRepository->findOneBy(['code' => 'ISO27701_2025']);

        if (!$framework2019) {
            $io->error('ISO 27701:2019 framework not found. Please load it first using app:load-iso27701-requirements');
            return Command::FAILURE;
        }

        if (!$framework2025) {
            $io->error('ISO 27701:2025 framework not found. Please load it first using app:load-iso27701v2025-requirements');
            return Command::FAILURE;
        }

        // Get all requirements
        $requirements2019 = $this->requirementRepository->findBy(['framework' => $framework2019]);
        $requirements2025 = $this->requirementRepository->findBy(['framework' => $framework2025]);

        if (empty($requirements2019)) {
            $io->error('No ISO 27701:2019 requirements found. Please load them first.');
            return Command::FAILURE;
        }

        if (empty($requirements2025)) {
            $io->error('No ISO 27701:2025 requirements found. Please load them first.');
            return Command::FAILURE;
        }

        // Build mapping index for 2019 requirements
        $index2019 = [];
        foreach ($requirements2019 as $req) {
            $index2019[$req->getRequirementId()] = $req;
        }

        // Define version mappings (2025 => 2019)
        $versionMappings = $this->getVersionMappings();

        $createdMappings = 0;
        $skippedMappings = 0;

        foreach ($requirements2025 as $req2025) {
            $reqId2025 = $req2025->getRequirementId();

            // Check if there's a corresponding 2019 requirement
            if (!isset($versionMappings[$reqId2025])) {
                // This is a new 2025 requirement (like AI-specific controls)
                continue;
            }

            $reqId2019 = $versionMappings[$reqId2025];

            if (!isset($index2019[$reqId2019])) {
                $io->warning("2019 requirement $reqId2019 not found in database");
                continue;
            }

            $req2019 = $index2019[$reqId2019];

            // Check if mapping already exists
            $existingMapping = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $req2025,
                'targetRequirement' => $req2019,
            ]);

            if ($existingMapping) {
                $skippedMappings++;
                continue;
            }

            // Create bidirectional mappings
            // 2025 → 2019
            $mapping1 = new ComplianceMapping();
            $mapping1->setSourceRequirement($req2025);
            $mapping1->setTargetRequirement($req2019);
            $mapping1->setMatchQuality(95); // High confidence - version evolution
            $mapping1->setMappingType('version_evolution');
            $mapping1->setNotes('Automatic mapping between ISO 27701:2025 and ISO 27701:2019 versions');
            $this->entityManager->persist($mapping1);

            // 2019 → 2025
            $mapping2 = new ComplianceMapping();
            $mapping2->setSourceRequirement($req2019);
            $mapping2->setTargetRequirement($req2025);
            $mapping2->setMatchQuality(95);
            $mapping2->setMappingType('version_evolution');
            $mapping2->setNotes('Automatic mapping between ISO 27701:2019 and ISO 27701:2025 versions');
            $this->entityManager->persist($mapping2);

            $createdMappings += 2;
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Created %d bidirectional mappings between ISO 27701:2019 and ISO 27701:2025 (skipped %d existing)',
            $createdMappings,
            $skippedMappings
        ));

        $newRequirements2025 = count($requirements2025) - (count($versionMappings));
        $io->info(sprintf(
            'ISO 27701:2025 has %d new requirements not present in 2019 version (AI, Digital Ecosystems, Enhanced Security)',
            $newRequirements2025
        ));

        return Command::SUCCESS;
    }

    private function getVersionMappings(): array
    {
        return [
            // Section 5: PIMS-specific requirements
            '27701:2025-5.2.1' => '27701-5.2.1',
            '27701:2025-5.2.2' => '27701-5.2.2',
            '27701:2025-5.3' => '27701-5.3',
            '27701:2025-5.4.1' => '27701-5.4.1',

            // Section 6: Planning
            '27701:2025-6.1.1' => '27701-6.1.1',
            '27701:2025-6.1.2' => '27701-6.1.2',
            '27701:2025-6.2' => '27701-6.2',

            // Section 7: Support
            '27701:2025-7.2.2' => '27701-7.2.2',
            '27701:2025-7.3' => '27701-7.3',
            '27701:2025-7.4.1' => '27701-7.4.1',
            '27701:2025-7.5.1' => '27701-7.5.1',

            // Section 8: Operation
            '27701:2025-8.2' => '27701-8.2',
            '27701:2025-8.3' => '27701-8.3',
            '27701:2025-8.4' => '27701-8.4',

            // Section 9: Performance evaluation
            '27701:2025-9.1' => '27701-9.1',
            '27701:2025-9.2' => '27701-9.2',
            '27701:2025-9.3' => '27701-9.3',

            // Section 10: Improvement
            '27701:2025-10.1' => '27701-10.1',
            '27701:2025-10.2' => '27701-10.2',

            // Annex A: Controller Requirements
            '27701:2025-A.7.2.1' => '27701-A.7.2.1',
            '27701:2025-A.7.2.2' => '27701-A.7.2.2',
            '27701:2025-A.7.2.3' => '27701-A.7.2.3',
            '27701:2025-A.7.2.4' => '27701-A.7.2.4',
            '27701:2025-A.7.2.5' => '27701-A.7.2.5',
            '27701:2025-A.7.3.1' => '27701-A.7.3.1',
            '27701:2025-A.7.3.2' => '27701-A.7.3.2',
            '27701:2025-A.7.3.3' => '27701-A.7.3.3',
            '27701:2025-A.7.3.4' => '27701-A.7.3.4',
            '27701:2025-A.7.3.5' => '27701-A.7.3.5',
            '27701:2025-A.7.3.6' => '27701-A.7.3.6',
            '27701:2025-A.7.3.9' => '27701-A.7.3.9',
            '27701:2025-A.7.4.1' => '27701-A.7.4.1',
            '27701:2025-A.7.4.2' => '27701-A.7.4.2',
            '27701:2025-A.7.4.3' => '27701-A.7.4.3',
            '27701:2025-A.7.4.4' => '27701-A.7.4.4',
            '27701:2025-A.7.5.1' => '27701-A.7.5.1',

            // Annex B: Processor Requirements
            '27701:2025-B.8.2.1' => '27701-B.8.2.1',
            '27701:2025-B.8.2.2' => '27701-B.8.2.2',
            '27701:2025-B.8.2.3' => '27701-B.8.2.3',
            '27701:2025-B.8.3.1' => '27701-B.8.3.1',
            '27701:2025-B.8.4.1' => '27701-B.8.4.1',
            '27701:2025-B.8.4.2' => '27701-B.8.4.2',
            '27701:2025-B.8.5.1' => '27701-B.8.5.1',
            '27701:2025-B.8.5.2' => '27701-B.8.5.2',
            '27701:2025-B.8.5.3' => '27701-B.8.5.3',

            // Note: The following 2025 requirements have no 2019 equivalent:
            // - A.7.3.7-A.7.3.8: Expanded PII principal rights
            // - A.7.3.10: PII disposal requirements (NEW)
            // - A.7.4.5-A.7.4.8: Expanded processing controls
            // - A.7.5.2-A.7.5.3: Enhanced incident response
            // - A.7.6.x: AI-specific requirements (NEW)
            // - A.7.7.x: Digital ecosystem requirements (NEW)
            // - A.7.8.x: Enhanced security controls (NEW)
            // - B.8.2.4-B.8.2.5: Enhanced processor requirements
            // - B.8.3.2-B.8.3.3: Enhanced sub-processor management
            // - B.8.4.3-B.8.4.4: Enhanced processor support
            // - B.8.5.4-B.8.5.6: Enhanced data protection
            // - B.8.6.x: Cloud & AI service requirements (NEW)
        ];
    }
}
