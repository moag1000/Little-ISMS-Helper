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
 * Seed BSI C5:2026 ↔ ISO/IEC 27001:2022 Annex A mappings.
 *
 * C5:2026 is largely cloud-native (AI, Confidential Computing, Containers,
 * Serverless) and therefore extends beyond 27001. The overlap is strongest
 * in the Supply-Chain-Security (SCS), Organisation (ORP) and ISO-tagged
 * categories — this seed covers those.
 *
 * Sprint 3 / B5 (ship the C5 seed that was missing from Sprint 1).
 */
#[AsCommand(
    name: 'app:seed-c52026-iso27001-mappings',
    description: 'Seed BSI C5:2026 ↔ ISO 27001:2022 Annex A mappings (Sprint 3 / B5).'
)]
class SeedC52026Iso27001MappingsCommand extends Command
{
    public const SOURCE_FRAMEWORK = 'BSI-C5-2026';
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
            $io->error(sprintf('Source framework %s not loaded. Run app:load-c5-2026-requirements first.', self::SOURCE_FRAMEWORK));
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
            $mapping->setConfidence('high');
            $mapping->setBidirectional(true);
            $mapping->setMappingRationale($row['rationale'] ?? 'BSI C5:2026 ↔ ISO 27001:2022 Annex A');
            $mapping->setVerifiedBy('app:seed-c52026-iso27001-mappings');
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
            $io->warning(sprintf('%d mapping row(s) skipped — missing source/target:', count($warnings)));
            foreach (array_slice($warnings, 0, 10) as $w) {
                $io->text('  - ' . $w);
            }
        }

        $io->success(sprintf('C5:2026 ↔ ISO 27001 seed complete. %d mapping(s) %s.', $seeded, $dryRun ? 'would be created' : 'created'));
        return Command::SUCCESS;
    }

    /** @return list<array{source: string, target: string, percentage: int, type: string, rationale?: string}> */
    private function mappings(): array
    {
        return [
            // ISO-tagged anchors (C5:2026 labels that already reference 27001:2022 controls)
            ['source' => 'C5-2026-ISO-1', 'target' => 'A.5.7',  'percentage' => 100, 'type' => 'full',    'rationale' => 'Threat-Intelligence ↔ Threat-intelligence'],
            ['source' => 'C5-2026-ISO-2', 'target' => 'A.5.23', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Cloud-Services-Security ↔ Information-security-for-use-of-cloud-services'],
            ['source' => 'C5-2026-ISO-3', 'target' => 'A.5.23', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Cloud-Computing-Security ↔ Information-security-for-use-of-cloud-services'],

            // Software Supply Chain Security (C5 SCS) — new in C5:2026, partially aligned with Annex A
            ['source' => 'C5-2026-SCS-1', 'target' => 'A.8.30', 'percentage' => 70,  'type' => 'partial', 'rationale' => 'SBOM + integrity ↔ Outsourced-development (loose)'],
            ['source' => 'C5-2026-SCS-2', 'target' => 'A.8.8',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'Third-party component vuln mgmt ↔ Management-of-technical-vulnerabilities'],
            ['source' => 'C5-2026-SCS-3', 'target' => 'A.5.19', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Supplier security assessment ↔ Info-sec-in-supplier-relationships'],
            ['source' => 'C5-2026-SCS-4', 'target' => 'A.8.25', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Secure SDLC ↔ Secure-development-life-cycle'],

            // Container Security (C5 CNT) — cloud-native controls with classic 27001 anchors
            ['source' => 'C5-2026-CNT-1', 'target' => 'A.8.9',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Container image security ↔ Configuration-management'],
            ['source' => 'C5-2026-CNT-2', 'target' => 'A.8.9',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Container runtime security ↔ Configuration-management'],
            ['source' => 'C5-2026-CNT-3', 'target' => 'A.8.9',  'percentage' => 60,  'type' => 'partial', 'rationale' => 'Container orchestration ↔ Configuration-management'],
            ['source' => 'C5-2026-CNT-6', 'target' => 'A.8.22', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Container network policies ↔ Segregation-of-networks'],

            // DevSecOps (C5 CSA)
            ['source' => 'C5-2026-CSA-1', 'target' => 'A.8.25', 'percentage' => 80,  'type' => 'partial', 'rationale' => 'DevSecOps integration ↔ Secure-development-life-cycle'],
            ['source' => 'C5-2026-CSA-2', 'target' => 'A.8.9',  'percentage' => 80,  'type' => 'partial', 'rationale' => 'IaC security ↔ Configuration-management'],
            ['source' => 'C5-2026-CSA-3', 'target' => 'A.8.25', 'percentage' => 60,  'type' => 'partial', 'rationale' => 'Serverless security ↔ Secure-development-life-cycle'],

            // Confidential Computing (C5 CFC) — new, partial alignment with encryption
            ['source' => 'C5-2026-CFC-2', 'target' => 'A.8.24', 'percentage' => 100, 'type' => 'full',    'rationale' => 'Memory Encryption ↔ Use-of-cryptography'],

            // Enterprise Cloud Services (C5 ECS)
            ['source' => 'C5-2026-ECS-3', 'target' => 'A.8.9',  'percentage' => 70,  'type' => 'partial', 'rationale' => 'Hypervisor security ↔ Configuration-management'],
        ];
    }
}
