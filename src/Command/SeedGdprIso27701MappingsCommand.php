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
 * Seed GDPR ↔ ISO/IEC 27701:2019 (PIMS) mappings.
 *
 * ISO 27701 ist als Privacy-Extension zu ISO 27001 konzipiert und
 * operationalisiert die DSGVO direkt. Anders als das GDPR ↔ ISO 27001
 * Mapping (S7-4, ~40 meist partielle Treffer) sind die Deckungen hier
 * überwiegend `full` — 27701 hat eigene Abschnitte für Controller-
 * Obligations (7.x), Processor-Obligations (8.x) und GDPR-Interop
 * (GDPR-1 bis GDPR-7).
 *
 * Quelle: ISO/IEC 27701:2019 Annex D (GDPR-Mapping-Tabelle) +
 * EDPB Guidelines 2/2023 + ENISA „Pseudonymisation Techniques".
 *
 * Idempotent: bestehende Quell/Ziel-Paare werden übersprungen.
 *
 * Sprint 8 / S8-1.
 */
#[AsCommand(
    name: 'app:seed-gdpr-iso27701-mappings',
    description: 'Seed GDPR ↔ ISO 27701:2019 PIMS mappings (Sprint 8 / S8-1).'
)]
class SeedGdprIso27701MappingsCommand extends Command
{
    public const SOURCE_FRAMEWORK = 'GDPR';
    public const TARGET_FRAMEWORK = 'ISO27701';

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
            $mapping->setMappingRationale($row['rationale'] ?? 'GDPR ↔ ISO 27701:2019 (Annex D) direct operationalization');
            $mapping->setVerifiedBy('app:seed-gdpr-iso27701-mappings');
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

        $io->success(sprintf('GDPR ↔ ISO 27701 seed complete. %d mapping(s) %s.', $seeded, $dryRun ? 'would be created' : 'created'));
        return Command::SUCCESS;
    }

    /** @return list<array{source: string, target: string, percentage: int, type: string, rationale?: string, confidence?: string}> */
    private function mappings(): array
    {
        return [
            // Art. 5 — Principles
            ['source' => 'GDPR-5.1.a', 'target' => '27701-A.7.2.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Lawfulness ↔ Identify lawful basis'],
            ['source' => 'GDPR-5.1.b', 'target' => '27701-A.7.2.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Purpose limitation ↔ Identify and document purpose'],
            ['source' => 'GDPR-5.1.c', 'target' => '27701-A.7.3.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Data minimisation ↔ Limit collection'],
            ['source' => 'GDPR-5.1.d', 'target' => '27701-A.7.3.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Accuracy ↔ Accuracy and quality'],
            ['source' => 'GDPR-5.1.e', 'target' => '27701-A.7.2.5', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Storage limitation ↔ Retention period'],
            ['source' => 'GDPR-5.1.e', 'target' => '27701-A.7.4.8', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Storage limitation ↔ Disposal of PII'],
            ['source' => 'GDPR-5.1.f', 'target' => '27701-B.7.2.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Integrity & confidentiality ↔ Security of PII processing'],

            // Art. 6 — Lawfulness
            ['source' => 'GDPR-6',     'target' => '27701-A.7.2.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Lawfulness of processing ↔ Identify lawful basis'],

            // Art. 9 — Special categories
            ['source' => 'GDPR-9',     'target' => '27701-GDPR-5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Special categories ↔ Special Categories of PII'],

            // Art. 12 — Transparent communication
            ['source' => 'GDPR-12',    'target' => '27701-A.7.3.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Transparent information ↔ Privacy notices'],

            // Art. 13/14 — Information obligations
            ['source' => 'GDPR-13',    'target' => '27701-A.7.2.4', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Information obligations (direct) ↔ Provide privacy notices'],
            ['source' => 'GDPR-13',    'target' => '27701-A.7.3.3', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Information obligations ↔ Obligation to inform'],
            ['source' => 'GDPR-14',    'target' => '27701-A.7.2.4', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Information obligations (indirect) ↔ Provide privacy notices'],

            // Art. 15 — Right of access
            ['source' => 'GDPR-15',    'target' => '27701-A.7.3.5', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Right of access ↔ Provide access to PII'],
            ['source' => 'GDPR-15',    'target' => '27701-A.7.4.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Right of access ↔ Right of Access'],

            // Art. 16 — Right to rectification
            ['source' => 'GDPR-16',    'target' => '27701-A.7.3.6', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Right to rectification ↔ Correction and erasure'],
            ['source' => 'GDPR-16',    'target' => '27701-A.7.4.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Right to rectification ↔ Right to Rectification'],

            // Art. 17 — Right to erasure
            ['source' => 'GDPR-17',    'target' => '27701-A.7.4.3', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Right to erasure ↔ Right to Erasure'],
            ['source' => 'GDPR-17',    'target' => '27701-A.7.3.6', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Right to erasure ↔ Correction and erasure'],

            // Art. 18 — Right to restriction
            ['source' => 'GDPR-18',    'target' => '27701-A.7.4.4', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Right to restriction ↔ Right to Restriction of Processing'],

            // Art. 20 — Data portability
            ['source' => 'GDPR-20',    'target' => '27701-A.7.4.5', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Data portability ↔ Right to Data Portability'],
            ['source' => 'GDPR-20',    'target' => '27701-A.7.4.4', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Data portability ↔ Data portability (original A.7.4.4)'],

            // Art. 21 — Right to object
            ['source' => 'GDPR-21',    'target' => '27701-A.7.4.6', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Right to object ↔ Right to Object'],

            // Art. 22 — Automated decision-making
            ['source' => 'GDPR-22',    'target' => '27701-A.7.4.7', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Automated decisions ↔ Automated Decision Making'],
            ['source' => 'GDPR-22',    'target' => '27701-A.7.4.3', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Automated decisions ↔ Automated decision making (original 27701 A.7.4.3)'],

            // Art. 24 — Controller responsibility
            ['source' => 'GDPR-24',    'target' => '27701-5.4.1',   'percentage' => 100, 'type' => 'full',    'rationale' => 'Controller responsibility ↔ PIMS'],
            ['source' => 'GDPR-24',    'target' => '27701-6.1.1',   'percentage' => 100, 'type' => 'full',    'rationale' => 'Controller responsibility ↔ Risks & opportunities (Privacy)'],

            // Art. 25 — Privacy by Design/Default
            ['source' => 'GDPR-25',    'target' => '27701-A.7.5.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Privacy by design ↔ Data Protection by Design'],
            ['source' => 'GDPR-25',    'target' => '27701-A.7.5.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Privacy by default ↔ Data Protection by Default'],

            // Art. 26 — Joint controllers
            ['source' => 'GDPR-26',    'target' => '27701-GDPR-3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Joint controllers ↔ Joint Controllers Agreement'],

            // Art. 28 — Processor
            ['source' => 'GDPR-28',    'target' => '27701-A.8.2.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Processor requirements ↔ Contracts with PII Processors (Controller side)'],
            ['source' => 'GDPR-28',    'target' => '27701-B.8.2.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Processor requirements ↔ Customer agreements (Processor side)'],
            ['source' => 'GDPR-28',    'target' => '27701-B.8.2.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Processor instructions ↔ Process only on instructions'],
            ['source' => 'GDPR-28',    'target' => '27701-B.8.2.3', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Processor disposal ↔ Return, transfer or disposal of PII'],
            ['source' => 'GDPR-28',    'target' => '27701-B.8.3.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Subprocessor requirements ↔ Subcontractor relationships'],
            ['source' => 'GDPR-28',    'target' => '27701-8.3',     'percentage' => 100, 'type' => 'full',    'rationale' => 'Processor requirements ↔ Working with PII processors'],

            // Art. 30 — Records of processing
            ['source' => 'GDPR-30',    'target' => '27701-A.8.3.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Records of processing ↔ RoPA (Controller)'],
            ['source' => 'GDPR-30',    'target' => '27701-B.8.5.3', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Records of processing ↔ RoPA (Processor)'],
            ['source' => 'GDPR-30',    'target' => '27701-A.7.3.4', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Records of processing ↔ Records related to processing PII'],

            // Art. 32 — Security of processing (core)
            ['source' => 'GDPR-32',       'target' => '27701-B.7.2.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Security of processing ↔ Security of PII Processing'],
            ['source' => 'GDPR-32',       'target' => '27701-B.7.2.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Security of processing ↔ Confidentiality of PII'],
            ['source' => 'GDPR-32.1.a',   'target' => '27701-GDPR-6',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Pseudonymisation/Encryption ↔ Pseudonymization and Anonymization'],
            ['source' => 'GDPR-32.1.b',   'target' => '27701-B.7.2.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Ongoing CIA ↔ Security of PII Processing'],
            ['source' => 'GDPR-32.1.c',   'target' => '27701-B.8.4.1', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Restore availability ↔ Provide assistance to customer (restore aspect)'],
            ['source' => 'GDPR-32.1.d',   'target' => '27701-9.1',     'percentage' => 100, 'type' => 'full',    'rationale' => 'Regular testing & evaluation ↔ Monitoring, measurement, analysis'],
            ['source' => 'GDPR-32.1.d',   'target' => '27701-9.2',     'percentage' => 80,  'type' => 'partial', 'rationale' => 'Regular evaluation ↔ Internal audit (PIMS)'],

            // Art. 33 — Breach notification (to supervisory authority)
            ['source' => 'GDPR-33',    'target' => '27701-A.7.5.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Breach notification ↔ Notification of PII breach'],
            ['source' => 'GDPR-33',    'target' => '27701-GDPR-2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Breach notification ↔ Breach Notification to Authorities'],
            ['source' => 'GDPR-33',    'target' => '27701-B.8.4.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Breach notification (processor → controller) ↔ Notification of PII breach to customer'],

            // Art. 34 — Communication to data subject
            ['source' => 'GDPR-34',    'target' => '27701-A.7.5.1', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'Data subject breach communication ↔ Notification of PII breach'],

            // Art. 35 — DPIA
            ['source' => 'GDPR-35',    'target' => '27701-6.1.2',   'percentage' => 100, 'type' => 'full',    'rationale' => 'DPIA ↔ PII protection impact assessment (Clause 6)'],
            ['source' => 'GDPR-35',    'target' => '27701-8.2',     'percentage' => 100, 'type' => 'full',    'rationale' => 'DPIA ↔ PII protection impact assessment (Clause 8)'],
            ['source' => 'GDPR-35',    'target' => '27701-A.7.5.3', 'percentage' => 100, 'type' => 'full',    'rationale' => 'DPIA ↔ Privacy Impact Assessment'],

            // Art. 37 — DPO
            ['source' => 'GDPR-37',    'target' => '27701-A.8.2.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'DPO designation ↔ Data Protection Officer Appointment'],

            // Art. 44 — International transfers (general)
            ['source' => 'GDPR-44',    'target' => '27701-8.4',     'percentage' => 100, 'type' => 'full',    'rationale' => 'International transfers ↔ Third country transfers'],
            ['source' => 'GDPR-44',    'target' => '27701-A.8.4.1', 'percentage' => 100, 'type' => 'full',    'rationale' => 'International transfers ↔ International Data Transfers'],

            // Art. 46 — Transfers with safeguards
            ['source' => 'GDPR-46',    'target' => '27701-A.8.4.2', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Appropriate safeguards ↔ Standard Contractual Clauses'],

            // Supervisory Authority cooperation (implicit in GDPR-33 but also broader)
            ['source' => 'GDPR-33',    'target' => '27701-GDPR-1',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Breach notification workflow ↔ Supervisory Authority Cooperation'],

            // Art. 83 — Administrative fines (no direct 27701 counterpart — weak)
            ['source' => 'GDPR-83',    'target' => '27701-9.3',     'percentage' => 30,  'type' => 'weak',    'rationale' => 'Fines framework ↔ Management review (PIMS) — indirectly via governance oversight'],

            // Art. 88 — Employment context
            ['source' => 'GDPR-88',    'target' => '27701-A.7.3.3', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Employment processing ↔ Specify obligations in contracts'],
        ];
    }
}
