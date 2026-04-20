<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed SOC 2 Trust Services Criteria ↔ ISO/IEC 27001:2022 mappings.
 *
 * Derived from the AICPA Trust Services Criteria Points of Focus that
 * explicitly reference ISO 27001 counterparts, and from the widely-used
 * Vanta / Drata / Secureframe cross-reference tables. The goal isn't
 * byte-exact fidelity to any single published table — it is to give a
 * new SOC-2-reviewing auditor a defensible cross-framework picture
 * without manual mapping work.
 *
 * Idempotent: existing source/target pairs are skipped.
 *
 * Sprint 3 / B5 (ship the SOC 2 seed that was missing from Sprint 1).
 */
#[AsCommand(
    name: 'app:seed-soc2-iso27001-mappings',
    description: 'Seed SOC 2 TSC ↔ ISO 27001:2022 Annex A mappings (Sprint 3 / B5).'
)]
class SeedSoc2Iso27001MappingsCommand extends Command
{
    public const SOURCE_FRAMEWORK = 'SOC2';
    public const TARGET_FRAMEWORK = 'ISO27001';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and report only — no database writes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $source = $this->frameworkRepository->findOneBy(['code' => self::SOURCE_FRAMEWORK]);
        $target = $this->frameworkRepository->findOneBy(['code' => self::TARGET_FRAMEWORK]);

        if (!$source instanceof ComplianceFramework) {
            $io->error(sprintf('Source framework %s not loaded. Run the SOC 2 loader first.', self::SOURCE_FRAMEWORK));
            return Command::FAILURE;
        }
        if (!$target instanceof ComplianceFramework) {
            $io->error(sprintf('Target framework %s not loaded. Run the ISO 27001 loader first.', self::TARGET_FRAMEWORK));
            return Command::FAILURE;
        }

        $io->info(sprintf('Seeding %s ↔ %s mappings (dry-run=%s)', self::SOURCE_FRAMEWORK, self::TARGET_FRAMEWORK, $dryRun ? 'yes' : 'no'));

        $seeded = 0;
        $skippedExisting = 0;
        $skippedMissing = 0;
        $warnings = [];

        foreach ($this->mappings() as $row) {
            $src = $this->requirementRepository->findOneBy([
                'complianceFramework' => $source,
                'requirementId' => $row['source'],
            ]);
            $tgt = $this->requirementRepository->findOneBy([
                'complianceFramework' => $target,
                'requirementId' => $row['target'],
            ]);
            if (!$src instanceof ComplianceRequirement || !$tgt instanceof ComplianceRequirement) {
                $skippedMissing++;
                $warnings[] = sprintf('%s → %s: source=%s target=%s', $row['source'], $row['target'], $src ? 'OK' : 'MISSING', $tgt ? 'OK' : 'MISSING');
                continue;
            }

            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $src,
                'targetRequirement' => $tgt,
            ]);
            if ($existing instanceof ComplianceMapping) {
                $skippedExisting++;
                continue;
            }

            if ($dryRun) {
                $seeded++;
                continue;
            }

            $mapping = new ComplianceMapping();
            $mapping->setSourceRequirement($src);
            $mapping->setTargetRequirement($tgt);
            $mapping->setMappingPercentage($row['percentage']);
            $mapping->setMappingType($row['type']);
            $mapping->setConfidence($row['confidence'] ?? 'high');
            $mapping->setBidirectional(true);
            $mapping->setMappingRationale($row['rationale'] ?? 'AICPA Trust Services Criteria cross-reference to ISO 27001:2022');
            $mapping->setVerifiedBy('app:seed-soc2-iso27001-mappings');
            $mapping->setVerificationDate(new DateTimeImmutable());
            $this->entityManager->persist($mapping);
            $seeded++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(
            ['Seeded', 'Skipped (existing)', 'Skipped (missing req)', 'Total rows'],
            [[$seeded, $skippedExisting, $skippedMissing, count($this->mappings())]]
        );

        if ($warnings !== []) {
            $io->warning(sprintf('%d mapping row(s) skipped — missing source/target requirement:', count($warnings)));
            foreach (array_slice($warnings, 0, 15) as $w) {
                $io->text('  - ' . $w);
            }
            if (count($warnings) > 15) {
                $io->text(sprintf('  … %d more', count($warnings) - 15));
            }
        }

        $io->success(sprintf('SOC 2 ↔ ISO 27001 seed complete. %d mapping(s) %s.', $seeded, $dryRun ? 'would be created' : 'created'));
        return Command::SUCCESS;
    }

    /** @return list<array{source: string, target: string, percentage: int, type: string, rationale?: string, confidence?: string}> */
    private function mappings(): array
    {
        return [
            // Common Criteria 1 — Control Environment
            ['source' => 'CC1.1', 'target' => 'A.5.4',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'COSO ↔ Management-Responsibilities'],
            ['source' => 'CC1.2', 'target' => 'A.5.4',  'percentage' => 60,  'type' => 'partial', 'rationale' => 'Board oversight ↔ Management commitment'],
            ['source' => 'CC1.3', 'target' => 'A.5.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Org structure ↔ Information-Security-Roles'],
            ['source' => 'CC1.4', 'target' => 'A.6.3',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Competence ↔ Awareness-Training'],
            ['source' => 'CC1.5', 'target' => 'A.5.2',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Accountability ↔ Roles-and-Responsibilities'],

            // CC2 — Communication
            ['source' => 'CC2.1', 'target' => 'A.5.33', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Information quality ↔ Protection-of-records'],
            ['source' => 'CC2.2', 'target' => 'A.5.4',  'percentage' => 60,  'type' => 'partial', 'rationale' => 'Internal communication ↔ Management direction'],
            ['source' => 'CC2.3', 'target' => 'A.5.36', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'External communication ↔ Compliance with policies'],

            // CC3 — Risk Assessment
            ['source' => 'CC3.1', 'target' => 'A.5.8',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Objectives ↔ Information-security-in-project-mgmt'],
            ['source' => 'CC3.2', 'target' => 'A.5.7',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Risk identification ↔ Threat-intelligence'],
            ['source' => 'CC3.3', 'target' => 'A.5.7',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Fraud risk ↔ Threat-intelligence (fraud subset)'],
            ['source' => 'CC3.4', 'target' => 'A.8.32', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Change risk assessment ↔ Change-Management'],

            // CC4 — Monitoring Activities
            ['source' => 'CC4.1', 'target' => 'A.5.35', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Evaluations ↔ Independent-review'],
            ['source' => 'CC4.2', 'target' => 'A.5.27', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Communication of deficiencies ↔ Learning-from-incidents'],

            // CC5 — Control Activities
            ['source' => 'CC5.1', 'target' => 'A.5.1',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Control activities ↔ Policies-for-information-security'],
            ['source' => 'CC5.2', 'target' => 'A.5.8',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Technology controls ↔ Information-security-in-project-mgmt'],
            ['source' => 'CC5.3', 'target' => 'A.5.37', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Policies and procedures ↔ Documented-operating-procedures'],

            // CC6 — Logical and Physical Access Controls
            ['source' => 'CC6.1', 'target' => 'A.5.17', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Logical access authentication ↔ Authentication-information'],
            ['source' => 'CC6.2', 'target' => 'A.5.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Access authorization ↔ Access-control'],
            ['source' => 'CC6.3', 'target' => 'A.5.18', 'percentage' => 100, 'type' => 'full',    'rationale' => 'User access removal ↔ Access-rights'],
            ['source' => 'CC6.4', 'target' => 'A.7.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Physical access ↔ Physical-entry'],
            ['source' => 'CC6.5', 'target' => 'A.5.18', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Logical access removal ↔ Access-rights'],
            ['source' => 'CC6.6', 'target' => 'A.5.17', 'percentage' => 100, 'type' => 'full',    'rationale' => 'MFA ↔ Authentication-information (MFA subset)'],
            ['source' => 'CC6.7', 'target' => 'A.8.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Access restriction ↔ Information-access-restriction'],
            ['source' => 'CC6.8', 'target' => 'A.5.18', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Access review ↔ Access-rights (periodic review aspect)'],

            // CC7 — System Operations
            ['source' => 'CC7.1', 'target' => 'A.8.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'System monitoring ↔ Monitoring-activities'],
            ['source' => 'CC7.2', 'target' => 'A.8.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Detection ↔ Monitoring-activities'],
            ['source' => 'CC7.3', 'target' => 'A.5.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Security event evaluation ↔ Assessment-of-events'],
            ['source' => 'CC7.4', 'target' => 'A.5.26', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident response ↔ Response-to-incidents'],
            ['source' => 'CC7.5', 'target' => 'A.5.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Recovery from incidents ↔ Information-security-during-disruption'],

            // CC8 — Change Management
            ['source' => 'CC8.1', 'target' => 'A.8.32', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Change management ↔ Change-Management'],

            // CC9 — Risk Mitigation (Vendor / Business Disruption)
            ['source' => 'CC9.1', 'target' => 'A.5.19', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Vendor risk ↔ Info-sec-in-supplier-relationships'],
            ['source' => 'CC9.2', 'target' => 'A.5.22', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Vendor monitoring ↔ Monitoring-and-review-of-supplier-services'],

            // Availability (A)
            ['source' => 'A1.1', 'target' => 'A.8.6',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Capacity & performance ↔ Capacity-management'],
            ['source' => 'A1.2', 'target' => 'A.8.13', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Backup & recovery ↔ Information-backup'],
            ['source' => 'A1.3', 'target' => 'A.5.30', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Business continuity ↔ ICT-readiness-for-BC'],

            // Confidentiality (C)
            ['source' => 'C1.1', 'target' => 'A.5.12', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Confidentiality ↔ Classification-of-information'],
            ['source' => 'C1.2', 'target' => 'A.8.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Data classification + encryption ↔ Use-of-cryptography'],
        ];
    }
}
