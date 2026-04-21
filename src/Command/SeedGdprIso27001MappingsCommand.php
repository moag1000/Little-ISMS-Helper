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
 * Seed GDPR Art. 5/24/25/28/30/32-37/44-46 ↔ ISO/IEC 27001:2022 Annex A.
 *
 * Fokus: Technische und Organisatorische Maßnahmen (Art. 32) und die
 * Privacy-/Records-Anforderungen (Art. 30, 33, 34). Grundsätze
 * (Art. 5.1.a-f) werden nur partiell gemappt — ISO deckt sie indirekt
 * über A.5.34 + Klassifikationsregeln ab.
 *
 * Quellen: EDPB Guidelines, BSI „Datenschutz und IT-Sicherheit",
 * DIN ISO/IEC 27701 Cross-Reference zu 27001.
 *
 * Idempotent: bestehende Quell/Ziel-Paare werden übersprungen.
 *
 * Sprint 7 / S7-4.
 */
#[AsCommand(
    name: 'app:seed-gdpr-iso27001-mappings',
    description: 'Seed GDPR ↔ ISO 27001:2022 Annex A mappings (Sprint 7 / S7-4).'
)]
class SeedGdprIso27001MappingsCommand extends Command
{
    public const SOURCE_FRAMEWORK = 'GDPR';
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
            $mapping->setMappingRationale($row['rationale'] ?? 'GDPR ↔ ISO 27001:2022 Annex A (EDPB / ISO 27701 cross-reference)');
            $mapping->setVerifiedBy('app:seed-gdpr-iso27001-mappings');
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

        $io->success(sprintf('GDPR ↔ ISO 27001 seed complete. %d mapping(s) %s.', $seeded, $dryRun ? 'would be created' : 'created'));
        return Command::SUCCESS;
    }

    /** @return list<array{source: string, target: string, percentage: int, type: string, rationale?: string, confidence?: string}> */
    private function mappings(): array
    {
        return [
            // Art. 5 — Principles
            ['source' => 'GDPR-5.1.a', 'target' => 'A.5.34', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Lawfulness/Transparency ↔ Privacy-and-PII-protection'],
            ['source' => 'GDPR-5.1.b', 'target' => 'A.5.34', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Purpose limitation ↔ Privacy-and-PII-protection'],
            ['source' => 'GDPR-5.1.c', 'target' => 'A.5.34', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Data minimisation ↔ Privacy-and-PII-protection'],
            ['source' => 'GDPR-5.1.d', 'target' => 'A.5.34', 'percentage' => 60,  'type' => 'partial', 'rationale' => 'Accuracy ↔ Privacy-and-PII-protection'],
            ['source' => 'GDPR-5.1.e', 'target' => 'A.5.33', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Storage limitation ↔ Protection-of-records'],
            ['source' => 'GDPR-5.1.f', 'target' => 'A.8.24', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Integrity/Confidentiality ↔ Use-of-cryptography'],
            ['source' => 'GDPR-5.1.f', 'target' => 'A.5.34', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Integrity/Confidentiality ↔ Privacy-and-PII-protection'],

            // Art. 24 — Controller responsibility
            ['source' => 'GDPR-24', 'target' => 'A.5.4',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Controller responsibility ↔ Management-responsibilities'],
            ['source' => 'GDPR-24', 'target' => 'A.5.34', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Controller responsibility ↔ Privacy-and-PII-protection'],

            // Art. 25 — Privacy by Design & by Default
            ['source' => 'GDPR-25', 'target' => 'A.8.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Privacy by design ↔ Secure-development-life-cycle'],
            ['source' => 'GDPR-25', 'target' => 'A.8.26', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Privacy by design ↔ Application-security-requirements'],
            ['source' => 'GDPR-25', 'target' => 'A.8.27', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Privacy by default ↔ Secure-system-architecture'],

            // Art. 28 — Processor
            ['source' => 'GDPR-28', 'target' => 'A.5.19', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Processor requirements ↔ InfoSec-in-supplier-relationships'],
            ['source' => 'GDPR-28', 'target' => 'A.5.20', 'percentage' => 100, 'type' => 'full',    'rationale' => 'DPA contract ↔ Addressing-infosec-in-supplier-agreements'],
            ['source' => 'GDPR-28', 'target' => 'A.5.21', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Sub-processor chain ↔ ICT-supply-chain-security'],
            ['source' => 'GDPR-28', 'target' => 'A.5.22', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Processor monitoring ↔ Monitoring-supplier-services'],

            // Art. 30 — Records of processing
            ['source' => 'GDPR-30', 'target' => 'A.5.33', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Records of processing ↔ Protection-of-records'],
            ['source' => 'GDPR-30', 'target' => 'A.5.9',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'RoPA ↔ Inventory-of-information-assets (PII inventory aspect)'],

            // Art. 32 — Security of processing (core TOM)
            ['source' => 'GDPR-32',     'target' => 'A.5.1',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Security of processing ↔ Policies-for-information-security'],
            ['source' => 'GDPR-32',     'target' => 'A.5.34', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Art. 32 ↔ Privacy-and-PII-protection (direct)'],
            ['source' => 'GDPR-32.1.a', 'target' => 'A.8.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Pseudonymisation & encryption ↔ Use-of-cryptography'],
            ['source' => 'GDPR-32.1.b', 'target' => 'A.5.12', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Ongoing CIA ↔ Classification-of-information'],
            ['source' => 'GDPR-32.1.b', 'target' => 'A.8.3',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Ongoing CIA ↔ Information-access-restriction'],
            ['source' => 'GDPR-32.1.c', 'target' => 'A.8.13', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Restore availability ↔ Information-backup'],
            ['source' => 'GDPR-32.1.c', 'target' => 'A.5.29', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Restore availability ↔ InfoSec-during-disruption'],
            ['source' => 'GDPR-32.1.d', 'target' => 'A.8.29', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Regular testing ↔ Security-testing-in-development'],
            ['source' => 'GDPR-32.1.d', 'target' => 'A.5.35', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Regular evaluation ↔ Independent-review-of-infosec'],

            // Art. 33 — Breach notification to supervisory authority
            ['source' => 'GDPR-33', 'target' => 'A.5.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Breach notification ↔ Contact-with-authorities'],
            ['source' => 'GDPR-33', 'target' => 'A.5.24', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Breach handling ↔ Incident-management-planning'],
            ['source' => 'GDPR-33', 'target' => 'A.5.25', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Breach assessment ↔ Assessment-of-events'],
            ['source' => 'GDPR-33', 'target' => 'A.5.26', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Breach response ↔ Response-to-incidents'],

            // Art. 34 — Communication to data subject
            ['source' => 'GDPR-34', 'target' => 'A.5.26', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Data subject communication ↔ Response-to-incidents'],
            ['source' => 'GDPR-34', 'target' => 'A.5.34', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Data subject notification ↔ Privacy-and-PII-protection'],

            // Art. 35 — DPIA
            ['source' => 'GDPR-35', 'target' => 'A.5.7',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'DPIA ↔ Threat-intelligence'],
            ['source' => 'GDPR-35', 'target' => 'A.8.25', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'DPIA at design time ↔ Secure-development-life-cycle'],

            // Art. 37 — DPO
            ['source' => 'GDPR-37', 'target' => 'A.5.2',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'DPO role ↔ InfoSec-roles-and-responsibilities'],

            // Art. 44/46 — International transfers
            ['source' => 'GDPR-44', 'target' => 'A.5.14', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'International transfers ↔ Information-transfer'],
            ['source' => 'GDPR-46', 'target' => 'A.5.20', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Appropriate safeguards ↔ Addressing-infosec-in-supplier-agreements'],
            ['source' => 'GDPR-46', 'target' => 'A.5.14', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'SCC-guarded transfers ↔ Information-transfer'],

            // Art. 88 — HR context
            ['source' => 'GDPR-88', 'target' => 'A.6.2',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Employment processing ↔ Terms-and-conditions-of-employment'],
        ];
    }
}
