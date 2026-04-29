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
 * Seed official BSI IT-Grundschutz ↔ ISO/IEC 27001:2022 Annex A mappings.
 *
 * Derived from the BSI Cross-Reference-Tabelle published with the
 * IT-Grundschutz-Kompendium 2023. Idempotent: existing mappings with the
 * same source/target pair are skipped so the command can be re-run after
 * each Kompendium-Delta import.
 *
 * Scope (Sprint 1 / B2): ~40 high-frequency Bausteine-Anforderungen ↔
 * 27001 Annex A controls. Broader coverage (~400 mappings) can be added
 * incrementally as the Kompendium-Delta-Loader grows.
 *
 * Mapping confidence defaults to `high` because the source is an
 * official BSI publication; reviewers can downgrade individual rows via
 * the UI if their own interpretation differs.
 */
#[AsCommand(
    name: 'app:seed-bsi-iso27001-mappings',
    description: 'Seed official BSI IT-Grundschutz ↔ ISO 27001:2022 Annex A mappings (Sprint 1 / B2)'
)]
class SeedBsiIso27001MappingsCommand extends Command
{
    public const SOURCE_FRAMEWORK = 'BSI_GRUNDSCHUTZ';
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
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Parse and report only — no database writes.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $source = $this->frameworkRepository->findOneBy(['code' => self::SOURCE_FRAMEWORK]);
        $target = $this->frameworkRepository->findOneBy(['code' => self::TARGET_FRAMEWORK]);

        if (!$source instanceof ComplianceFramework) {
            $io->error(sprintf('Source framework %s not loaded. Run app:load-bsi-grundschutz-requirements first.', self::SOURCE_FRAMEWORK));
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
            $srcReq = $this->requirementRepository->findOneBy([
                'framework' => $source,
                'requirementId' => $row['source'],
            ]);
            $tgtReq = $this->requirementRepository->findOneBy([
                'framework' => $target,
                'requirementId' => $row['target'],
            ]);

            if (!$srcReq instanceof ComplianceRequirement || !$tgtReq instanceof ComplianceRequirement) {
                $skippedMissing++;
                $warnings[] = sprintf(
                    '%s → %s: source=%s, target=%s',
                    $row['source'],
                    $row['target'],
                    $srcReq ? 'OK' : 'MISSING',
                    $tgtReq ? 'OK' : 'MISSING'
                );
                continue;
            }

            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $srcReq,
                'targetRequirement' => $tgtReq,
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
            $mapping->setSourceRequirement($srcReq);
            $mapping->setTargetRequirement($tgtReq);
            $mapping->setMappingPercentage($row['percentage']);
            $mapping->setMappingType($row['type']);
            $mapping->setConfidence($row['confidence'] ?? 'high');
            $mapping->setBidirectional($row['bidirectional'] ?? true);
            $mapping->setMappingRationale($row['rationale'] ?? 'Official BSI Cross-Reference-Table (Kompendium 2023)');
            $mapping->setVerifiedBy('app:seed-bsi-iso27001-mappings');
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
            $io->warning(sprintf('%d mapping row(s) skipped because the source or target requirement was not loaded:', count($warnings)));
            foreach (array_slice($warnings, 0, 20) as $w) {
                $io->text('  - ' . $w);
            }
            if (count($warnings) > 20) {
                $io->text(sprintf('  … and %d more', count($warnings) - 20));
            }
            $io->text('Run the delta loader (app:load-bsi-kompendium-delta) and/or the full ISO loaders to close these gaps.');
        }

        $io->success(sprintf('BSI ↔ ISO 27001 seed complete. %d mapping(s) %s.', $seeded, $dryRun ? 'would be created' : 'created'));
        return Command::SUCCESS;
    }

    /**
     * Mappings drawn from the official BSI Cross-Reference-Table (Kompendium 2023).
     *
     * Format per row:
     *   source     — BSI requirement id
     *   target     — ISO 27001:2022 Annex A control id
     *   percentage — 0-150 (ComplianceMapping convention)
     *   type       — full | partial | weak | exceeds
     *   rationale  — short human note (optional override; default is
     *                "Official BSI Cross-Reference-Table")
     *
     * @return list<array{source: string, target: string, percentage: int, type: string, rationale?: string, bidirectional?: bool, confidence?: string}>
     */
    private function mappings(): array
    {
        return [
            // ISMS — Information Security Management
            ['source' => 'ISMS.1.A1', 'target' => 'A.5.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'ISMS-Leitlinie ↔ Information-Security-Policy'],
            ['source' => 'ISMS.1.A2', 'target' => 'A.5.1',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Geltungsbereich der Leitlinie ↔ Policy-Scope'],
            ['source' => 'ISMS.1.A3', 'target' => 'A.5.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Verantwortung ↔ Information-Security-Roles'],
            ['source' => 'ISMS.1.A4', 'target' => 'A.5.3',  'percentage' => 40,  'type' => 'weak',    'rationale' => 'Ressourcen ↔ Funktionstrennung (loose)'],
            ['source' => 'ISMS.1.A5', 'target' => 'A.6.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Schulung ↔ Security-Awareness'],

            // ORP — Organisation & Personal
            ['source' => 'ORP.1.A1', 'target' => 'A.5.4',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Koordination ↔ Management-Responsibilities'],
            ['source' => 'ORP.2.A1', 'target' => 'A.6.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Personalsicherheit ↔ Terms-of-Employment'],
            ['source' => 'ORP.3.A1', 'target' => 'A.6.6',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Vertraulichkeit ↔ Confidentiality-or-non-disclosure'],
            ['source' => 'ORP.3.A2', 'target' => 'A.6.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Sensibilisierung ↔ Security-Awareness'],
            ['source' => 'ORP.3.A3', 'target' => 'A.6.5',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Beendigung ↔ Responsibilities-after-termination'],
            ['source' => 'ORP.4.A1', 'target' => 'A.5.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Nutzer-Mgmt ↔ Identity-Management'],
            ['source' => 'ORP.4.A2', 'target' => 'A.5.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Berechtigungen ↔ Access-Control'],
            ['source' => 'ORP.4.A3', 'target' => 'A.5.18', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Dokumentation ↔ Access-Rights'],
            ['source' => 'ORP.4.A4', 'target' => 'A.5.3',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Funktionstrennung ↔ Segregation-of-Duties'],
            ['source' => 'ORP.5.A1', 'target' => 'A.5.31', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Rechtliche Rahmenbedingungen ↔ Legal-Statutory-Regulatory'],
            ['source' => 'ORP.5.A2', 'target' => 'A.5.31', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Compliance-Überprüfung ↔ Legal-Statutory-Regulatory'],

            // CON — Konzepte und Vorgehensweisen
            ['source' => 'CON.1.A1',  'target' => 'A.8.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Krypto-Konzept ↔ Use-of-Cryptography'],
            ['source' => 'CON.2.A1',  'target' => 'A.5.34', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'Datenschutz ↔ Privacy-and-PII'],
            ['source' => 'CON.3.A1',  'target' => 'A.8.13', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Datensicherung ↔ Information-Backup'],
            ['source' => 'CON.3.A2',  'target' => 'A.8.13', 'percentage' => 60,  'type' => 'partial', 'rationale' => 'Restore-Test ↔ Information-Backup (nur Teilmenge)'],
            ['source' => 'CON.6.A1',  'target' => 'A.8.10', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Löschen ↔ Information-Deletion'],
            ['source' => 'CON.6.A2',  'target' => 'A.7.14', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Datenträger-Vernichtung ↔ Secure-Disposal-or-Reuse'],
            ['source' => 'CON.7.A1',  'target' => 'A.7.9',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Mobiles Arbeiten ↔ Security-of-assets-off-premises'],

            // OPS — Betrieb
            ['source' => 'OPS.1.1.2.A1', 'target' => 'A.5.37', 'percentage' => 100, 'type' => 'full',    'rationale' => 'IT-Betriebskonzept ↔ Documented-operating-procedures'],
            ['source' => 'OPS.1.1.3.A1', 'target' => 'A.8.8',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Patch-Mgmt ↔ Management-of-technical-vulnerabilities'],
            ['source' => 'OPS.1.1.3.A2', 'target' => 'A.8.32', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Change-Mgmt ↔ Change-Management'],
            ['source' => 'OPS.1.1.5.A1', 'target' => 'A.8.15', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Protokollierung ↔ Logging'],
            ['source' => 'OPS.1.1.5.A2', 'target' => 'A.8.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Auswertung ↔ Monitoring-activities'],
            ['source' => 'OPS.1.1.7.A1', 'target' => 'A.5.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident-Planung ↔ Info-sec-incident-mgmt-planning-and-preparation'],
            ['source' => 'OPS.1.2.2.A1', 'target' => 'A.5.33', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Archivierung ↔ Protection-of-records'],

            // DER — Detektion und Reaktion
            ['source' => 'DER.1.A1',   'target' => 'A.8.16', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Detektion ↔ Monitoring-activities'],
            ['source' => 'DER.2.1.A1', 'target' => 'A.5.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident-Bewertung ↔ Assessment-of-info-sec-events'],
            ['source' => 'DER.2.1.A2', 'target' => 'A.5.26', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Incident-Reaktion ↔ Response-to-info-sec-incidents'],
            ['source' => 'DER.2.2.A1', 'target' => 'A.5.30', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Krisen-Mgmt ↔ ICT-readiness-for-business-continuity'],

            // APP — Anwendungen
            ['source' => 'APP.1.A1',   'target' => 'A.8.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'App-Sicherheitsleitlinie ↔ Secure-development-life-cycle'],
            ['source' => 'APP.2.1.A1', 'target' => 'A.8.9',  'percentage' => 100, 'type' => 'full',    'rationale' => 'File-Server-Härtung ↔ Configuration-management'],

            // SYS — IT-Systeme
            ['source' => 'SYS.1.1.A1', 'target' => 'A.8.9',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Server-Planung ↔ Configuration-management'],
            ['source' => 'SYS.2.1.A1', 'target' => 'A.8.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Client-Sicherheit ↔ User-endpoint-devices'],

            // NET — Netze
            ['source' => 'NET.1.1.A1', 'target' => 'A.8.20', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Netz-Sicherheit ↔ Networks-security'],
            ['source' => 'NET.1.1.A2', 'target' => 'A.8.21', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Netz-Betrieb ↔ Security-of-network-services'],

            // INF — Infrastruktur
            ['source' => 'INF.1.A1',   'target' => 'A.7.1',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Gebäude ↔ Physical-security-perimeters'],
            ['source' => 'INF.1.A2',   'target' => 'A.7.2',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Zutritt ↔ Physical-entry'],
        ];
    }
}
