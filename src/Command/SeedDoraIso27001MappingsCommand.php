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
 * Seed EU-DORA Art. 5-33 ↔ ISO/IEC 27001:2022 Annex A mappings.
 *
 * Cross-reference-Quellen:
 *  - EBA Guidelines on ICT Risk Assessment
 *  - ENISA "DORA Technical Guidance" (2024)
 *  - BaFin DORA-FAQ zur ISO 27001 Anwendbarkeit
 *
 * Fokus Art. 5-15 (ICT Risk Management) + Art. 17-20 (Incident Mgmt) +
 * Art. 24-26 (Testing) + Art. 28-31 (Third-Party Risk).
 * Goverance-Artikel (5) und Reporting (19/20) bekommen partielle
 * Mappings — DORA ist regulatorisch enger als 27001.
 *
 * Idempotent: bestehende Quell/Ziel-Paare werden übersprungen.
 *
 * Sprint 7 / S7-2.
 */
#[AsCommand(
    name: 'app:seed-dora-iso27001-mappings',
    description: 'Seed EU-DORA Art. 5-33 ↔ ISO 27001:2022 Annex A mappings (Sprint 7 / S7-2).'
)]
class SeedDoraIso27001MappingsCommand extends Command
{
    public const SOURCE_FRAMEWORK = 'DORA';
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
            $io->error(sprintf('Source framework %s not loaded.', self::SOURCE_FRAMEWORK));
            return Command::FAILURE;
        }
        if (!$target instanceof ComplianceFramework) {
            $io->error(sprintf('Target framework %s not loaded.', self::TARGET_FRAMEWORK));
            return Command::FAILURE;
        }

        $io->info(sprintf('Seeding %s ↔ %s mappings (dry-run=%s)', self::SOURCE_FRAMEWORK, self::TARGET_FRAMEWORK, $dryRun ? 'yes' : 'no'));

        $seeded = 0;
        $skippedExisting = 0;
        $skippedMissing = 0;
        $warnings = [];

        foreach ($this->mappings() as $row) {
            $src = $this->requirementRepository->findOneBy([
                'framework' => $source,
                'requirementId' => $row['source'],
            ]);
            $tgt = $this->requirementRepository->findOneBy([
                'framework' => $target,
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
            $mapping->setMappingRationale($row['rationale'] ?? 'DORA Art. 5-33 ↔ ISO 27001:2022 Annex A (EBA/ENISA cross-reference)');
            $mapping->setVerifiedBy('app:seed-dora-iso27001-mappings');
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

        $io->success(sprintf('DORA ↔ ISO 27001 seed complete. %d mapping(s) %s.', $seeded, $dryRun ? 'would be created' : 'created'));
        return Command::SUCCESS;
    }

    /** @return list<array{source: string, target: string, percentage: int, type: string, rationale?: string, confidence?: string}> */
    private function mappings(): array
    {
        return [
            // Art. 5 — Governance & management body
            ['source' => 'DORA-5.1', 'target' => 'A.5.4',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Mgmt body responsibility ↔ Management-responsibilities'],
            ['source' => 'DORA-5.1', 'target' => 'A.5.2',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Mgmt body responsibility ↔ Roles-and-responsibilities'],
            ['source' => 'DORA-5.2', 'target' => 'A.5.4',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'ICT risk oversight ↔ Management-responsibilities'],

            // Art. 6 — ICT risk management framework & BC policy
            ['source' => 'DORA-6.1', 'target' => 'A.5.1',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'ICT risk framework ↔ Policies-for-information-security'],
            ['source' => 'DORA-6.1', 'target' => 'A.5.7',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'ICT risk framework ↔ Threat-intelligence'],
            ['source' => 'DORA-6.2', 'target' => 'A.5.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'BC policy ↔ InfoSec-during-disruption'],
            ['source' => 'DORA-6.2', 'target' => 'A.5.30', 'percentage' => 100, 'type' => 'full',    'rationale' => 'BC policy ↔ ICT-readiness-for-BC'],

            // Art. 7 — ICT systems identification
            ['source' => 'DORA-7.1', 'target' => 'A.5.9',  'percentage' => 100, 'type' => 'full',    'rationale' => 'ICT systems identification ↔ Inventory-of-information-assets'],
            ['source' => 'DORA-7.1', 'target' => 'A.5.11', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Systems identification ↔ Return-of-assets'],

            // Art. 8 — ICT risk identification & asset inventory
            ['source' => 'DORA-8.1', 'target' => 'A.5.7',  'percentage' => 100, 'type' => 'full',    'rationale' => 'ICT risk identification ↔ Threat-intelligence'],
            ['source' => 'DORA-8.1', 'target' => 'A.8.8',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'ICT risk identification ↔ Technical-vulnerability-management'],
            ['source' => 'DORA-8.2', 'target' => 'A.5.9',  'percentage' => 100, 'type' => 'full',    'rationale' => 'ICT assets inventory ↔ Inventory-of-information-assets'],
            ['source' => 'DORA-8.3', 'target' => 'A.8.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Continuous monitoring ↔ Monitoring-activities'],

            // Art. 9 — Business continuity plans
            ['source' => 'DORA-9.1', 'target' => 'A.5.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'BC plans ↔ InfoSec-during-disruption'],
            ['source' => 'DORA-9.1', 'target' => 'A.5.30', 'percentage' => 100, 'type' => 'full',    'rationale' => 'BC plans ↔ ICT-readiness-for-BC'],
            ['source' => 'DORA-9.2', 'target' => 'A.5.30', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'BC testing ↔ ICT-readiness-for-BC'],
            ['source' => 'DORA-9.2', 'target' => 'A.8.14', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'BC testing ↔ Redundancy-of-processing'],
            ['source' => 'DORA-9.3', 'target' => 'A.5.30', 'percentage' => 100, 'type' => 'full',    'rationale' => 'RTO ↔ ICT-readiness-for-BC'],
            ['source' => 'DORA-9.4', 'target' => 'A.5.5',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Communication plans ↔ Contact-with-authorities'],

            // Art. 10 — Response & recovery
            ['source' => 'DORA-10.1', 'target' => 'A.5.26', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Response procedures ↔ Response-to-incidents'],
            ['source' => 'DORA-10.1', 'target' => 'A.5.29', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Recovery procedures ↔ InfoSec-during-disruption'],

            // Art. 11 — Simplified ICT risk framework (small entities)
            ['source' => 'DORA-11.1', 'target' => 'A.5.1',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Simplified framework ↔ Policies-for-information-security'],

            // Art. 12 — Backup & restoration
            ['source' => 'DORA-12.1', 'target' => 'A.8.13', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Backup policies ↔ Information-backup'],
            ['source' => 'DORA-12.2', 'target' => 'A.5.29', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Restoration procedures ↔ InfoSec-during-disruption'],
            ['source' => 'DORA-12.2', 'target' => 'A.8.13', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Restoration procedures ↔ Information-backup'],
            ['source' => 'DORA-12.3', 'target' => 'A.5.30', 'percentage' => 100, 'type' => 'full',    'rationale' => 'RTO/RPO ↔ ICT-readiness-for-BC'],

            // Art. 13 — ICT systems access, auth, crypto, logging, training
            ['source' => 'DORA-13.1', 'target' => 'A.5.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Access control ↔ Access-control'],
            ['source' => 'DORA-13.1', 'target' => 'A.5.18', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Access control ↔ Access-rights'],
            ['source' => 'DORA-13.2', 'target' => 'A.5.17', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Strong authentication ↔ Authentication-information'],
            ['source' => 'DORA-13.2', 'target' => 'A.8.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Strong authentication ↔ Secure-authentication'],
            ['source' => 'DORA-13.3', 'target' => 'A.5.12', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Data protection ↔ Classification-of-information'],
            ['source' => 'DORA-13.3', 'target' => 'A.5.34', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Data protection ↔ Privacy-and-PII-protection'],
            ['source' => 'DORA-13.4', 'target' => 'A.8.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Cryptographic controls ↔ Use-of-cryptography'],
            ['source' => 'DORA-13.5', 'target' => 'A.8.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Event logging ↔ Logging'],
            ['source' => 'DORA-13.5', 'target' => 'A.8.16', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Event logging ↔ Monitoring-activities'],
            ['source' => 'DORA-13.6', 'target' => 'A.6.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'ICT training ↔ Awareness-training'],

            // Art. 14 — Physical & environmental
            ['source' => 'DORA-14.1', 'target' => 'A.7.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Physical security ↔ Physical-entry'],
            ['source' => 'DORA-14.1', 'target' => 'A.7.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Environmental controls ↔ Physical-environment'],
            ['source' => 'DORA-14.1', 'target' => 'A.7.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Environmental controls ↔ Equipment-siting'],

            // Art. 15-16 — Oversight relationship / supervisory expectations
            ['source' => 'DORA-15.1', 'target' => 'A.5.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Oversight authorities ↔ Contact-with-authorities'],
            ['source' => 'DORA-16.1', 'target' => 'A.5.36', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Supervisory expectations ↔ Compliance-with-policies'],

            // Art. 17-18 — Incident management & classification
            ['source' => 'DORA-17.1', 'target' => 'A.5.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident mgmt process ↔ Incident-management-planning'],
            ['source' => 'DORA-17.1', 'target' => 'A.5.26', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident mgmt process ↔ Response-to-incidents'],
            ['source' => 'DORA-17.2', 'target' => 'A.5.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident classification ↔ Assessment-of-events'],
            ['source' => 'DORA-17.3', 'target' => 'A.5.28', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Incident register ↔ Collection-of-evidence'],
            ['source' => 'DORA-18.1', 'target' => 'A.5.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Major incident classification ↔ Assessment-of-events'],
            ['source' => 'DORA-18.2', 'target' => 'A.5.7',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Significant cyber threats ↔ Threat-intelligence'],

            // Art. 19-20 — Reporting
            ['source' => 'DORA-19.1', 'target' => 'A.5.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident notification ↔ Contact-with-authorities'],
            ['source' => 'DORA-20.1', 'target' => 'A.5.5',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Harmonized reporting ↔ Contact-with-authorities'],

            // Art. 24-26 — Testing
            ['source' => 'DORA-24.1', 'target' => 'A.8.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'ICT testing ↔ Security-testing-in-development'],
            ['source' => 'DORA-24.2', 'target' => 'A.8.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Testing programme ↔ Security-testing-in-development'],
            ['source' => 'DORA-25.1', 'target' => 'A.8.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Vulnerability assessments / pentest ↔ Technical-vulnerability-management'],
            ['source' => 'DORA-25.1', 'target' => 'A.8.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Pen-testing ↔ Security-testing-in-development'],
            ['source' => 'DORA-26.1', 'target' => 'A.8.29', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'TLPT ↔ Security-testing-in-development (advanced)'],

            // Art. 27-33 — Third-party risk management
            ['source' => 'DORA-27.1', 'target' => 'A.5.23', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Use of cloud ↔ InfoSec-for-cloud-services'],
            ['source' => 'DORA-28.1', 'target' => 'A.5.19', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Third-party risk mgmt ↔ InfoSec-in-supplier-relationships'],
            ['source' => 'DORA-28.1', 'target' => 'A.5.21', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Third-party risk mgmt ↔ ICT-supply-chain-security'],
            ['source' => 'DORA-28.2', 'target' => 'A.5.20', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Contractual arrangements ↔ Addressing-infosec-in-supplier-agreements'],
            ['source' => 'DORA-28.3', 'target' => 'A.5.19', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Register of information ↔ InfoSec-in-supplier-relationships'],
            ['source' => 'DORA-29.1', 'target' => 'A.5.21', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'ICT concentration risk ↔ ICT-supply-chain-security'],
            ['source' => 'DORA-30.1', 'target' => 'A.5.20', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Key contractual provisions ↔ Addressing-infosec-in-supplier-agreements'],
            ['source' => 'DORA-30a',  'target' => 'A.5.21', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Subcontracting critical ↔ ICT-supply-chain-security'],
            ['source' => 'DORA-31.1', 'target' => 'A.5.21', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Sub-contracting critical ↔ ICT-supply-chain-security'],
            ['source' => 'DORA-32.1', 'target' => 'A.5.1',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Group-level ICT risk ↔ Policies-for-information-security'],
            ['source' => 'DORA-33.1', 'target' => 'A.5.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Cooperation with authorities ↔ Contact-with-authorities'],

            // Art. 45 — Threat intel sharing
            ['source' => 'DORA-45',   'target' => 'A.5.5',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Threat intel sharing ↔ Contact-with-authorities'],
            ['source' => 'DORA-45',   'target' => 'A.5.6',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Threat intel sharing ↔ Contact-with-special-interest-groups'],
            ['source' => 'DORA-45',   'target' => 'A.5.7',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Threat intel sharing ↔ Threat-intelligence'],
        ];
    }
}
