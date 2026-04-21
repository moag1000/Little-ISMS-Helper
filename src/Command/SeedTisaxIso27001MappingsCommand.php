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
 * Seed TISAX (VDA-ISA 5.x Struktur) ↔ ISO/IEC 27001:2022 Annex A mappings.
 *
 * Cross-reference: TISAX/VDA-ISA ist historisch direkt aus ISO 27001:2013
 * Annex A abgeleitet. Die 2022er-Reorganisation des Annex A auf 4
 * Themen (5/6/7/8) ändert Nummern, nicht Inhalt — die inhaltlichen
 * Äquivalenzen sind hoch (meist full=100 %).
 *
 * Prototype-Protection-Anforderungen (PROT-*, EVENTS/PROTO/TEST)
 * sind TISAX-spezifisch und bekommen nur partielle Mappings gegen
 * A.5.12 (Classification) + A.5.13 (Labelling).
 *
 * Idempotent: bestehende Quell/Ziel-Paare werden übersprungen.
 *
 * Sprint 7 / S7-3.
 */
#[AsCommand(
    name: 'app:seed-tisax-iso27001-mappings',
    description: 'Seed TISAX / VDA-ISA ↔ ISO 27001:2022 Annex A mappings (Sprint 7 / S7-3).'
)]
class SeedTisaxIso27001MappingsCommand extends Command
{
    public const SOURCE_FRAMEWORK = 'TISAX';
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
            $mapping->setMappingRationale($row['rationale'] ?? 'TISAX VDA-ISA ↔ ISO 27001:2022 Annex A (direct derivation)');
            $mapping->setVerifiedBy('app:seed-tisax-iso27001-mappings');
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

        $io->success(sprintf('TISAX ↔ ISO 27001 seed complete. %d mapping(s) %s.', $seeded, $dryRun ? 'would be created' : 'created'));
        return Command::SUCCESS;
    }

    /** @return list<array{source: string, target: string, percentage: int, type: string, rationale?: string, confidence?: string}> */
    private function mappings(): array
    {
        return [
            // Access control (ACC-*)
            ['source' => 'ACC-1.1', 'target' => 'A.5.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Access-control policy ↔ Access-control'],
            ['source' => 'ACC-2.1', 'target' => 'A.5.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'User registration ↔ Identity-management'],
            ['source' => 'ACC-2.2', 'target' => 'A.5.18', 'percentage' => 100, 'type' => 'full',    'rationale' => 'User access provisioning ↔ Access-rights'],
            ['source' => 'ACC-2.3', 'target' => 'A.8.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Privileged access ↔ Privileged-access-rights'],
            ['source' => 'ACC-2.4', 'target' => 'A.5.18', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Access review ↔ Access-rights'],
            ['source' => 'ACC-2.5', 'target' => 'A.5.18', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Access removal ↔ Access-rights'],
            ['source' => 'ACC-3.1', 'target' => 'A.5.17', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Secret auth info ↔ Authentication-information'],
            ['source' => 'ACC-3.2', 'target' => 'A.8.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure log-on ↔ Secure-authentication'],
            ['source' => 'ACC-3.3', 'target' => 'A.5.17', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Password mgmt ↔ Authentication-information'],
            ['source' => 'ACC-3.4', 'target' => 'A.8.18', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Privileged utilities ↔ Use-of-privileged-utility-programs'],
            ['source' => 'ACC-3.5', 'target' => 'A.8.4',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Source code access ↔ Access-to-source-code'],

            // BCM
            ['source' => 'BCM-1.1', 'target' => 'A.5.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Planning IS continuity ↔ InfoSec-during-disruption'],
            ['source' => 'BCM-1.2', 'target' => 'A.5.30', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Implementing IS continuity ↔ ICT-readiness-for-BC'],

            // Compliance (CMP-*)
            ['source' => 'CMP-1.1', 'target' => 'A.5.31', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Legal requirements ↔ Legal-statutory-regulatory-contractual'],
            ['source' => 'CMP-1.2', 'target' => 'A.5.32', 'percentage' => 100, 'type' => 'full',    'rationale' => 'IP rights ↔ Intellectual-property-rights'],
            ['source' => 'CMP-1.3', 'target' => 'A.5.33', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Protection of records ↔ Protection-of-records'],
            ['source' => 'CMP-1.4', 'target' => 'A.5.34', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Privacy & PII ↔ Privacy-and-PII-protection'],
            ['source' => 'CMP-2.1', 'target' => 'A.5.35', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Independent review ↔ Independent-review-of-infosec'],
            ['source' => 'CMP-2.2', 'target' => 'A.5.36', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Compliance with policies ↔ Compliance-with-policies'],
            ['source' => 'CMP-2.3', 'target' => 'A.8.8',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Technical compliance review ↔ Technical-vulnerability-management'],

            // Communications (COM-*)
            ['source' => 'COM-1.1', 'target' => 'A.8.20', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Network controls ↔ Networks-security'],
            ['source' => 'COM-1.2', 'target' => 'A.8.21', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Network services ↔ Security-of-network-services'],
            ['source' => 'COM-1.3', 'target' => 'A.8.22', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Network segregation ↔ Segregation-of-networks'],
            ['source' => 'COM-2.1', 'target' => 'A.5.14', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Information transfer ↔ Information-transfer'],
            ['source' => 'COM-2.2', 'target' => 'A.5.14', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Transfer agreements ↔ Information-transfer'],
            ['source' => 'COM-2.3', 'target' => 'A.5.14', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Electronic messaging ↔ Information-transfer'],
            ['source' => 'COM-2.4', 'target' => 'A.6.6',  'percentage' => 100, 'type' => 'full',    'rationale' => 'NDA ↔ Confidentiality-agreements'],

            // Cryptography (CRY-*)
            ['source' => 'CRY-1.1', 'target' => 'A.8.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Crypto policy ↔ Use-of-cryptography'],
            ['source' => 'CRY-1.2', 'target' => 'A.8.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Key management ↔ Use-of-cryptography'],

            // Development (DEV-*)
            ['source' => 'DEV-1.1', 'target' => 'A.8.26', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Security requirements ↔ Application-security-requirements'],
            ['source' => 'DEV-1.2', 'target' => 'A.8.27', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure architecture ↔ Secure-system-architecture'],
            ['source' => 'DEV-2.1', 'target' => 'A.8.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure dev policy ↔ Secure-development-life-cycle'],
            ['source' => 'DEV-2.2', 'target' => 'A.8.32', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Change control ↔ Change-management'],
            ['source' => 'DEV-2.3', 'target' => 'A.8.32', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Technical review after platform change ↔ Change-management'],
            ['source' => 'DEV-3.1', 'target' => 'A.8.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Security testing ↔ Security-testing-in-development'],
            ['source' => 'DEV-3.2', 'target' => 'A.8.33', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Test data protection ↔ Test-information'],

            // HR (HRS-*)
            ['source' => 'HRS-1.1', 'target' => 'A.6.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Screening ↔ Screening'],
            ['source' => 'HRS-1.2', 'target' => 'A.6.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Terms of employment ↔ Terms-and-conditions-of-employment'],
            ['source' => 'HRS-2.1', 'target' => 'A.5.4',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Mgmt responsibilities ↔ Management-responsibilities'],
            ['source' => 'HRS-2.2', 'target' => 'A.6.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Awareness & training ↔ Awareness-training'],
            ['source' => 'HRS-2.3', 'target' => 'A.6.4',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Disciplinary process ↔ Disciplinary-process'],
            ['source' => 'HRS-3.1', 'target' => 'A.6.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Termination responsibilities ↔ Responsibilities-after-termination'],

            // Incidents (INC-*)
            ['source' => 'INC-1.1', 'target' => 'A.5.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'IR responsibilities ↔ Incident-management-planning'],
            ['source' => 'INC-1.2', 'target' => 'A.6.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Reporting IS events ↔ Reporting-information-security-events'],
            ['source' => 'INC-1.3', 'target' => 'A.5.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Event assessment ↔ Assessment-of-events'],
            ['source' => 'INC-1.4', 'target' => 'A.5.26', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Response to incidents ↔ Response-to-incidents'],
            ['source' => 'INC-1.5', 'target' => 'A.5.27', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Learning from incidents ↔ Learning-from-incidents'],
            ['source' => 'INC-1.6', 'target' => 'A.5.28', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Collection of evidence ↔ Collection-of-evidence'],

            // Info-sec foundation (INF-*)
            ['source' => 'INF-1.1', 'target' => 'A.5.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'InfoSec policy ↔ Policies-for-information-security'],
            ['source' => 'INF-1.2', 'target' => 'A.5.1',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Policy review ↔ Policies-for-information-security'],
            ['source' => 'INF-2.1', 'target' => 'A.5.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Mgmt responsibility ↔ InfoSec-roles'],
            ['source' => 'INF-3.1', 'target' => 'A.5.9',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Inventory of assets ↔ Inventory-of-information-assets'],
            ['source' => 'INF-3.2', 'target' => 'A.5.9',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Ownership ↔ Inventory-of-information-assets'],
            ['source' => 'INF-3.3', 'target' => 'A.5.10', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Acceptable use ↔ Acceptable-use-of-information'],
            ['source' => 'INF-3.4', 'target' => 'A.5.11', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Return of assets ↔ Return-of-assets'],
            ['source' => 'INF-4.1', 'target' => 'A.5.12', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Classification ↔ Classification-of-information'],
            ['source' => 'INF-4.2', 'target' => 'A.5.13', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Labelling ↔ Labelling-of-information'],
            ['source' => 'INF-4.3', 'target' => 'A.5.10', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Handling ↔ Acceptable-use-of-information'],
            ['source' => 'INF-5.1', 'target' => 'A.5.7',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Risk assessment ↔ Threat-intelligence'],
            ['source' => 'INF-5.2', 'target' => 'A.8.8',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Risk treatment ↔ Technical-vulnerability-management'],

            // Mobile (MOB-*)
            ['source' => 'MOB-1.1', 'target' => 'A.8.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Mobile device policy ↔ User-endpoint-devices'],
            ['source' => 'MOB-1.2', 'target' => 'A.6.7',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Teleworking ↔ Remote-working'],

            // Operations (OPS-*)
            ['source' => 'OPS-1.1', 'target' => 'A.5.37', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Documented procedures ↔ Documented-operating-procedures'],
            ['source' => 'OPS-1.2', 'target' => 'A.8.32', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Change management ↔ Change-management'],
            ['source' => 'OPS-1.3', 'target' => 'A.8.6',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Capacity management ↔ Capacity-management'],
            ['source' => 'OPS-1.4', 'target' => 'A.8.31', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Dev/test/prod separation ↔ Separation-of-environments'],
            ['source' => 'OPS-2.1', 'target' => 'A.8.7',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Malware protection ↔ Protection-against-malware'],
            ['source' => 'OPS-3.1', 'target' => 'A.8.13', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Information backup ↔ Information-backup'],
            ['source' => 'OPS-4.1', 'target' => 'A.8.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Event logging ↔ Logging'],
            ['source' => 'OPS-4.2', 'target' => 'A.8.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Log protection ↔ Logging (protection aspect)'],
            ['source' => 'OPS-4.3', 'target' => 'A.8.15', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Admin logs ↔ Logging'],
            ['source' => 'OPS-4.4', 'target' => 'A.8.17', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Clock sync ↔ Clock-synchronization'],
            ['source' => 'OPS-5.1', 'target' => 'A.8.19', 'percentage' => 100, 'type' => 'full',    'rationale' => 'SW installation ↔ Installation-of-software-on-operational-systems'],
            ['source' => 'OPS-6.1', 'target' => 'A.8.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Technical vuln mgmt ↔ Technical-vulnerability-management'],
            ['source' => 'OPS-7.1', 'target' => 'A.8.34', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Audit controls ↔ Protection-of-IS-during-audit'],

            // Physical (PHY-*)
            ['source' => 'PHY-1.1', 'target' => 'A.7.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Physical perimeter ↔ Physical-security-perimeters'],
            ['source' => 'PHY-1.2', 'target' => 'A.7.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Physical entry ↔ Physical-entry'],
            ['source' => 'PHY-1.3', 'target' => 'A.7.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure offices ↔ Securing-offices'],
            ['source' => 'PHY-1.4', 'target' => 'A.7.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Env threats ↔ Physical-environment'],
            ['source' => 'PHY-1.5', 'target' => 'A.7.6',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Working in secure areas ↔ Working-in-secure-areas'],
            ['source' => 'PHY-1.6', 'target' => 'A.7.2',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Delivery/loading ↔ Physical-entry'],
            ['source' => 'PHY-2.1', 'target' => 'A.7.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Equipment siting ↔ Equipment-siting'],
            ['source' => 'PHY-2.2', 'target' => 'A.7.11', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Supporting utilities ↔ Supporting-utilities'],
            ['source' => 'PHY-2.3', 'target' => 'A.7.12', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Cabling security ↔ Cabling-security'],
            ['source' => 'PHY-2.4', 'target' => 'A.7.13', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Equipment maintenance ↔ Equipment-maintenance'],
            ['source' => 'PHY-2.5', 'target' => 'A.7.10', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Removal of assets ↔ Storage-media'],
            ['source' => 'PHY-2.6', 'target' => 'A.7.9',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Assets off-premises ↔ Security-of-assets-off-premises'],
            ['source' => 'PHY-2.7', 'target' => 'A.7.14', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure disposal ↔ Secure-disposal-or-re-use'],
            ['source' => 'PHY-2.8', 'target' => 'A.8.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Unattended equipment ↔ User-endpoint-devices'],
            ['source' => 'PHY-2.9', 'target' => 'A.7.7',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Clear desk/screen ↔ Clear-desk-and-clear-screen'],

            // Prototype protection (TISAX-spezifisch)
            ['source' => 'PROT-1.1', 'target' => 'A.5.12', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Prototype classification ↔ Classification-of-information'],
            ['source' => 'PROT-1.2', 'target' => 'A.7.3',  'percentage' => 60,  'type' => 'partial', 'rationale' => 'Physical prototype protection ↔ Secure-offices'],
            ['source' => 'PROT-1.3', 'target' => 'A.5.14', 'percentage' => 60,  'type' => 'partial', 'rationale' => 'Prototype transport ↔ Information-transfer'],
            ['source' => 'PROT-2.1', 'target' => 'A.5.12', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Prototype data ↔ Classification-of-information'],

            // Supplier (SUP-*)
            ['source' => 'SUP-1.1', 'target' => 'A.5.19', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Supplier policy ↔ InfoSec-in-supplier-relationships'],
            ['source' => 'SUP-1.2', 'target' => 'A.5.20', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Supplier agreements ↔ Addressing-infosec-in-supplier-agreements'],
            ['source' => 'SUP-2.1', 'target' => 'A.5.22', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Supplier monitoring ↔ Monitoring-supplier-services'],
            ['source' => 'SUP-2.2', 'target' => 'A.5.22', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Supplier changes ↔ Monitoring-supplier-services'],
        ];
    }
}
