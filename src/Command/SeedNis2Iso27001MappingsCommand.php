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
 * Seed NIS2 Directive (EU 2022/2555) Art. 20-25 ↔ ISO/IEC 27001:2022
 * Annex A mappings.
 *
 * Cross-reference-Quellen:
 *  - ENISA "NIS2 Technical Implementation Guidance" (Draft 2024)
 *  - BSI "NIS2 — Umsetzung mit ISO 27001" Handreichung
 *  - NIS2 Commission Implementing Regulation (EU) 2024/2690 Annex I
 *
 * Zielbild: ein 27001-zertifizierter Mandant kommt mit ~70 % Deckung
 * an NIS2 Art. 21.2 ohne zusätzliche Controls — diese Seed-Relation
 * macht das im Transitive-Coverage-Service sichtbar.
 *
 * Idempotent: bestehende Quell/Ziel-Paare werden übersprungen.
 *
 * Sprint 7 / S7-1.
 */
#[AsCommand(
    name: 'app:seed-nis2-iso27001-mappings',
    description: 'Seed NIS2 Art. 20-25 ↔ ISO 27001:2022 Annex A mappings (Sprint 7 / S7-1).'
)]
class SeedNis2Iso27001MappingsCommand extends Command
{
    public const SOURCE_FRAMEWORK = 'NIS2';
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
            $mapping->setMappingRationale($row['rationale'] ?? 'NIS2 Art. 21 ↔ ISO 27001:2022 Annex A (ENISA/BSI cross-reference)');
            $mapping->setVerifiedBy('app:seed-nis2-iso27001-mappings');
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

        $io->success(sprintf('NIS2 ↔ ISO 27001 seed complete. %d mapping(s) %s.', $seeded, $dryRun ? 'would be created' : 'created'));
        return Command::SUCCESS;
    }

    /** @return list<array{source: string, target: string, percentage: int, type: string, rationale?: string, confidence?: string}> */
    private function mappings(): array
    {
        return [
            // Art. 20 — Management body responsibilities
            ['source' => 'NIS2-20.1', 'target' => 'A.5.4',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Management body approval ↔ Management-responsibilities'],
            ['source' => 'NIS2-20.2', 'target' => 'A.5.4',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Management oversight ↔ Management-responsibilities'],
            ['source' => 'NIS2-20.3', 'target' => 'A.5.2',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Management accountability ↔ Information-security-roles'],
            ['source' => 'NIS2-20.3', 'target' => 'A.5.4',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Management accountability ↔ Management-responsibilities'],

            // Art. 21.1 — Risk analysis & security of information systems
            ['source' => 'NIS2-21.1', 'target' => 'A.5.7',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Risk analysis ↔ Threat-intelligence'],
            ['source' => 'NIS2-21.1', 'target' => 'A.8.8',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Information system security ↔ Technical-vulnerability-management'],

            // Art. 21.2.a — Policies on risk analysis
            ['source' => 'NIS2-21.2.a', 'target' => 'A.5.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Security policies ↔ Policies-for-information-security'],
            ['source' => 'NIS2-21.2.a', 'target' => 'A.5.7',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Risk analysis policy ↔ Threat-intelligence'],

            // Art. 21.2.b — Incident handling
            ['source' => 'NIS2-21.2.b', 'target' => 'A.5.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident handling ↔ Incident-management-planning'],
            ['source' => 'NIS2-21.2.b', 'target' => 'A.5.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident assessment ↔ Assessment-of-events'],
            ['source' => 'NIS2-21.2.b', 'target' => 'A.5.26', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident response ↔ Response-to-incidents'],
            ['source' => 'NIS2-21.2.b', 'target' => 'A.5.27', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Lessons learned ↔ Learning-from-incidents'],
            ['source' => 'NIS2-21.2.b', 'target' => 'A.5.28', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Evidence collection ↔ Collection-of-evidence'],

            // Art. 21.2.c — Business continuity & crisis management
            ['source' => 'NIS2-21.2.c', 'target' => 'A.5.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Business continuity ↔ InfoSec-during-disruption'],
            ['source' => 'NIS2-21.2.c', 'target' => 'A.5.30', 'percentage' => 100, 'type' => 'full',    'rationale' => 'ICT continuity ↔ ICT-readiness-for-BC'],

            // Art. 21.2.d — Vulnerability handling & disclosure
            ['source' => 'NIS2-21.2.d', 'target' => 'A.8.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Vulnerability handling ↔ Technical-vulnerability-management'],
            ['source' => 'NIS2-21.2.d', 'target' => 'A.8.9',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Vulnerability management ↔ Configuration-management'],

            // Art. 21.2.e — Secure development & acquisition
            ['source' => 'NIS2-21.2.e', 'target' => 'A.8.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure development ↔ Secure-development-life-cycle'],
            ['source' => 'NIS2-21.2.e', 'target' => 'A.8.26', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Application security ↔ Application-security-requirements'],
            ['source' => 'NIS2-21.2.e', 'target' => 'A.8.27', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure architecture ↔ Secure-system-architecture'],
            ['source' => 'NIS2-21.2.e', 'target' => 'A.8.28', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure coding ↔ Secure-coding'],
            ['source' => 'NIS2-21.2.e', 'target' => 'A.8.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Security testing ↔ Security-testing-in-development'],
            ['source' => 'NIS2-21.2.e', 'target' => 'A.8.30', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Outsourced development ↔ Outsourced-development'],

            // Art. 21.2.f — Cyber hygiene & training
            ['source' => 'NIS2-21.2.f', 'target' => 'A.6.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Cyber training ↔ Awareness-training'],
            ['source' => 'NIS2-21.2.f', 'target' => 'A.8.7',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Cyber hygiene ↔ Protection-against-malware'],

            // Art. 21.2.g — Cryptography
            ['source' => 'NIS2-21.2.g', 'target' => 'A.8.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Cryptography ↔ Use-of-cryptography'],

            // Art. 21.2.h — HR security
            ['source' => 'NIS2-21.2.h', 'target' => 'A.6.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'HR screening ↔ Screening'],
            ['source' => 'NIS2-21.2.h', 'target' => 'A.6.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Terms of employment ↔ Terms-and-conditions-of-employment'],
            ['source' => 'NIS2-21.2.h', 'target' => 'A.6.4',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Disciplinary process ↔ Disciplinary-process'],
            ['source' => 'NIS2-21.2.h', 'target' => 'A.6.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Termination responsibilities ↔ Responsibilities-after-termination'],
            ['source' => 'NIS2-21.2.h', 'target' => 'A.6.6',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Confidentiality agreements ↔ Confidentiality-agreements'],

            // Art. 21.2.i — Access control & asset management
            ['source' => 'NIS2-21.2.i', 'target' => 'A.5.9',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Asset inventory ↔ Inventory-of-information-assets'],
            ['source' => 'NIS2-21.2.i', 'target' => 'A.5.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Access control ↔ Access-control'],
            ['source' => 'NIS2-21.2.i', 'target' => 'A.5.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Identity management ↔ Identity-management'],
            ['source' => 'NIS2-21.2.i', 'target' => 'A.5.17', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Authentication ↔ Authentication-information'],
            ['source' => 'NIS2-21.2.i', 'target' => 'A.5.18', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Access rights ↔ Access-rights'],
            ['source' => 'NIS2-21.2.i', 'target' => 'A.8.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Access restriction ↔ Information-access-restriction'],

            // Art. 21.2.j — MFA & secured communications
            ['source' => 'NIS2-21.2.j', 'target' => 'A.5.14', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Secured communications ↔ Information-transfer'],
            ['source' => 'NIS2-21.2.j', 'target' => 'A.5.17', 'percentage' => 100, 'type' => 'full',    'rationale' => 'MFA ↔ Authentication-information (MFA aspect)'],
            ['source' => 'NIS2-21.2.j', 'target' => 'A.8.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure authentication ↔ Secure-authentication'],

            // Art. 21.3 — Supply chain security
            ['source' => 'NIS2-21.3.a', 'target' => 'A.5.19', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Supplier assessment ↔ InfoSec-in-supplier-relationships'],
            ['source' => 'NIS2-21.3.a', 'target' => 'A.5.20', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Supplier agreements ↔ Addressing-infosec-in-supplier-agreements'],
            ['source' => 'NIS2-21.3.b', 'target' => 'A.5.21', 'percentage' => 100, 'type' => 'full',    'rationale' => 'ICT supply chain ↔ ICT-supply-chain-security'],
            ['source' => 'NIS2-21.3.b', 'target' => 'A.5.22', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Supplier monitoring ↔ Monitoring-supplier-services'],
            ['source' => 'NIS2-21.3.c', 'target' => 'A.5.23', 'percentage' => 100, 'type' => 'full',    'rationale' => 'SLA ↔ InfoSec-for-cloud-services'],

            // Art. 21.4 — Network security
            ['source' => 'NIS2-21.4.a', 'target' => 'A.8.22', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Network segmentation ↔ Segregation-of-networks'],
            ['source' => 'NIS2-21.4.b', 'target' => 'A.8.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Network monitoring ↔ Monitoring-activities'],
            ['source' => 'NIS2-21.4.c', 'target' => 'A.8.9',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure configuration ↔ Configuration-management'],

            // Art. 21.5 — Incident response (detail layer)
            ['source' => 'NIS2-21.5.a', 'target' => 'A.5.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'IR plan ↔ Incident-management-planning'],
            ['source' => 'NIS2-21.5.b', 'target' => 'A.5.24', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'IR team ↔ Incident-management-planning (roles aspect)'],
            ['source' => 'NIS2-21.5.c', 'target' => 'A.5.29', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'IR testing ↔ InfoSec-during-disruption (testing aspect)'],

            // Art. 21.6 — Vulnerability management
            ['source' => 'NIS2-21.6.a', 'target' => 'A.8.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Vulnerability scanning ↔ Technical-vulnerability-management'],
            ['source' => 'NIS2-21.6.b', 'target' => 'A.8.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Patch management ↔ Technical-vulnerability-management'],
            ['source' => 'NIS2-21.6.c', 'target' => 'A.5.5',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Vulnerability disclosure ↔ Contact-with-authorities'],

            // Art. 21.7 — Backup & DR
            ['source' => 'NIS2-21.7.a', 'target' => 'A.8.13', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Data backup ↔ Information-backup'],
            ['source' => 'NIS2-21.7.b', 'target' => 'A.8.13', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Backup testing ↔ Information-backup (testing aspect)'],
            ['source' => 'NIS2-21.7.c', 'target' => 'A.5.30', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Disaster recovery ↔ ICT-readiness-for-BC'],

            // Art. 21.8 — Physical security
            ['source' => 'NIS2-21.8.a', 'target' => 'A.7.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Physical access control ↔ Physical-entry'],
            ['source' => 'NIS2-21.8.a', 'target' => 'A.7.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure offices ↔ Secure-offices'],
            ['source' => 'NIS2-21.8.b', 'target' => 'A.7.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Environmental controls ↔ Physical-environment'],
            ['source' => 'NIS2-21.8.b', 'target' => 'A.7.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Equipment siting ↔ Equipment-siting'],

            // Art. 21.9 — Logging & monitoring
            ['source' => 'NIS2-21.9.a', 'target' => 'A.8.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Event logging ↔ Logging'],
            ['source' => 'NIS2-21.9.b', 'target' => 'A.5.33', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Log retention ↔ Protection-of-records'],
            ['source' => 'NIS2-21.9.b', 'target' => 'A.8.15', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Log retention ↔ Logging (retention aspect)'],
            ['source' => 'NIS2-21.9.c', 'target' => 'A.8.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Log analysis ↔ Monitoring-activities'],

            // Art. 21.10 — Security testing
            ['source' => 'NIS2-21.10.a', 'target' => 'A.8.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Security testing ↔ Security-testing-in-development'],
            ['source' => 'NIS2-21.10.b', 'target' => 'A.8.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Penetration testing ↔ Security-testing-in-development'],

            // Art. 21.11 — Documentation & records
            ['source' => 'NIS2-21.11.a', 'target' => 'A.5.37', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Security documentation ↔ Documented-operating-procedures'],
            ['source' => 'NIS2-21.11.b', 'target' => 'A.5.33', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Record keeping ↔ Protection-of-records'],

            // Art. 22 — Information sharing
            ['source' => 'NIS2-22.1', 'target' => 'A.5.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Information sharing ↔ Contact-with-authorities'],
            ['source' => 'NIS2-22.1', 'target' => 'A.5.6',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Info sharing ↔ Contact-with-special-interest-groups'],

            // Art. 23 — Reporting obligations
            ['source' => 'NIS2-23.1', 'target' => 'A.5.5',  'percentage' => 80,  'type' => 'partial', 'rationale' => '24h early warning ↔ Contact-with-authorities'],
            ['source' => 'NIS2-23.2', 'target' => 'A.5.5',  'percentage' => 100, 'type' => 'full',    'rationale' => '72h notification ↔ Contact-with-authorities'],
            ['source' => 'NIS2-23.3', 'target' => 'A.5.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Final report ↔ Contact-with-authorities'],
            ['source' => 'NIS2-23.4', 'target' => 'A.5.5',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Significant threat notif ↔ Contact-with-authorities'],
            ['source' => 'NIS2-23.5', 'target' => 'A.5.34', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Recipients notif (PII) ↔ Privacy-and-PII-protection'],

            // Art. 24-25 — Certification & standards
            ['source' => 'NIS2-24.1', 'target' => 'A.5.36', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Certification ↔ Compliance-with-policies'],
            ['source' => 'NIS2-25.1', 'target' => 'A.5.1',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Use of standards ↔ Policies-for-information-security'],
            ['source' => 'NIS2-25.1', 'target' => 'A.5.36', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Use of standards ↔ Compliance-with-policies'],
        ];
    }
}
