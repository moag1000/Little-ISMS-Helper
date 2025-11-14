<?php

namespace App\Command;

use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\MappingGapItemRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mapping-quality:sanity-check',
    description: 'Verify system prerequisites for mapping quality analysis'
)]
class MappingQualitySanityCheckCommand extends Command
{
    public function __construct(
        private ComplianceFrameworkRepository $frameworkRepository,
        private ComplianceRequirementRepository $requirementRepository,
        private ComplianceMappingRepository $mappingRepository,
        private MappingGapItemRepository $gapItemRepository,
        private Connection $connection
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Mapping Quality Analysis - Sanity Check');
        $io->text('Verifying system prerequisites...');
        $io->newLine();

        $allChecks = true;

        // Check 1: Database tables exist
        $io->section('1. Database Schema');
        $allChecks = $this->checkDatabaseSchema($io) && $allChecks;
        $io->newLine();

        // Check 2: Frameworks
        $io->section('2. Compliance Frameworks');
        $allChecks = $this->checkFrameworks($io) && $allChecks;
        $io->newLine();

        // Check 3: Requirements
        $io->section('3. Compliance Requirements');
        $allChecks = $this->checkRequirements($io) && $allChecks;
        $io->newLine();

        // Check 4: Mappings
        $io->section('4. Compliance Mappings');
        $allChecks = $this->checkMappings($io) && $allChecks;
        $io->newLine();

        // Check 5: Migration status
        $io->section('5. Quality Analysis Fields');
        $allChecks = $this->checkQualityFields($io) && $allChecks;
        $io->newLine();

        // Summary
        $io->section('Summary');
        if ($allChecks) {
            $io->success('✅ All checks passed! System is ready for mapping quality analysis.');
            $io->text([
                'Next steps:',
                '  1. Run analysis: php bin/console app:analyze-mapping-quality',
                '  2. Open dashboard: /compliance/mapping-quality/',
            ]);
            return Command::SUCCESS;
        } else {
            $io->error('❌ Some checks failed. Please resolve the issues above before running analysis.');
            $io->text([
                'Common fixes:',
                '  - Run migration: php bin/console doctrine:migrations:migrate',
                '  - Import frameworks: php bin/console app:import-framework ISO27001',
                '  - Create mappings: php bin/console app:create-cross-framework-mappings',
            ]);
            return Command::FAILURE;
        }
    }

    private function checkDatabaseSchema(SymfonyStyle $io): bool
    {
        $requiredTables = [
            'compliance_framework',
            'compliance_requirement',
            'compliance_mapping',
            'mapping_gap_item',
        ];

        $schemaManager = $this->connection->createSchemaManager();
        $existingTables = $schemaManager->listTableNames();

        $allTablesExist = true;
        foreach ($requiredTables as $table) {
            if (in_array($table, $existingTables)) {
                $io->writeln("  ✅ Table <info>$table</info> exists");
            } else {
                $io->writeln("  ❌ Table <error>$table</error> NOT FOUND");
                $allTablesExist = false;
            }
        }

        if (!$allTablesExist) {
            $io->warning('Missing tables detected. Run: php bin/console doctrine:migrations:migrate');
        }

        return $allTablesExist;
    }

    private function checkFrameworks(SymfonyStyle $io): bool
    {
        $count = $this->frameworkRepository->count([]);

        if ($count === 0) {
            $io->error("❌ No frameworks found (0)");
            $io->text('  Fix: Import frameworks with: php bin/console app:import-framework ISO27001');
            return false;
        }

        $io->success("✅ Frameworks found: $count");

        // Show available frameworks
        $frameworks = $this->frameworkRepository->findAll();
        $frameworkCodes = array_map(fn($f) => $f->getCode(), $frameworks);
        $io->text('  Available: ' . implode(', ', array_slice($frameworkCodes, 0, 10)));

        if (count($frameworkCodes) > 10) {
            $io->text('  ... and ' . (count($frameworkCodes) - 10) . ' more');
        }

        return true;
    }

    private function checkRequirements(SymfonyStyle $io): bool
    {
        $count = $this->requirementRepository->count([]);

        if ($count === 0) {
            $io->error("❌ No requirements found (0)");
            $io->text('  Fix: Requirements are usually imported with frameworks');
            return false;
        }

        if ($count < 50) {
            $io->warning("⚠️  Only $count requirements found (minimum 50 recommended)");
            $io->text('  Consider importing more frameworks for better analysis');
        } else {
            $io->success("✅ Requirements found: $count");
        }

        // Show distribution by framework
        $sql = "
            SELECT f.code, COUNT(r.id) as req_count
            FROM compliance_framework f
            LEFT JOIN compliance_requirement r ON f.id = r.framework_id
            GROUP BY f.id, f.code
            ORDER BY req_count DESC
            LIMIT 5
        ";
        $result = $this->connection->fetchAllAssociative($sql);

        if (!empty($result)) {
            $io->text('  Top frameworks by requirement count:');
            foreach ($result as $row) {
                $io->text(sprintf('    - %s: %d requirements', $row['code'], $row['req_count']));
            }
        }

        return $count > 0;
    }

    private function checkMappings(SymfonyStyle $io): bool
    {
        $count = $this->mappingRepository->count([]);

        if ($count === 0) {
            $io->error("❌ No mappings found (0)");
            $io->text('  Fix: Create mappings with: php bin/console app:create-cross-framework-mappings');
            return false;
        }

        if ($count < 10) {
            $io->warning("⚠️  Only $count mappings found (minimum 10 recommended)");
        } else {
            $io->success("✅ Mappings found: $count");
        }

        // Show mapping statistics
        $sql = "
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN calculated_percentage IS NOT NULL THEN 1 END) as analyzed,
                COUNT(CASE WHEN calculated_percentage IS NULL THEN 1 END) as unanalyzed
            FROM compliance_mapping
        ";
        $stats = $this->connection->fetchAssociative($sql);

        $io->text(sprintf(
            '  Status: %d analyzed, %d unanalyzed',
            $stats['analyzed'] ?? 0,
            $stats['unanalyzed'] ?? 0
        ));

        return $count > 0;
    }

    private function checkQualityFields(SymfonyStyle $io): bool
    {
        // Check if quality analysis fields exist in compliance_mapping
        $requiredColumns = [
            'calculated_percentage',
            'manual_percentage',
            'analysis_confidence',
            'quality_score',
            'textual_similarity',
            'keyword_overlap',
            'structural_similarity',
            'requires_review',
            'review_status',
            'analysis_algorithm_version',
        ];

        try {
            $schemaManager = $this->connection->createSchemaManager();
            $columns = $schemaManager->listTableColumns('compliance_mapping');
            $columnNames = array_keys($columns);

            $allColumnsExist = true;
            $missingColumns = [];

            foreach ($requiredColumns as $column) {
                if (!in_array($column, $columnNames)) {
                    $missingColumns[] = $column;
                    $allColumnsExist = false;
                }
            }

            if (!$allColumnsExist) {
                $io->error('❌ Quality analysis fields missing from compliance_mapping table');
                $io->text('  Missing columns: ' . implode(', ', $missingColumns));
                $io->warning('  Fix: Run migration: php bin/console doctrine:migrations:migrate');
                return false;
            }

            $io->success('✅ All quality analysis fields exist in compliance_mapping');

            // Check gap_item table
            if (!in_array('mapping_gap_item', $schemaManager->listTableNames())) {
                $io->error('❌ mapping_gap_item table not found');
                $io->warning('  Fix: Run migration: php bin/console doctrine:migrations:migrate');
                return false;
            }

            $io->success('✅ mapping_gap_item table exists');

            // Check if any gaps have been created
            $gapCount = $this->gapItemRepository->count([]);
            if ($gapCount > 0) {
                $io->text("  Gap items found: $gapCount");
            } else {
                $io->text('  Gap items: 0 (will be created after first analysis)');
            }

            return true;

        } catch (\Exception $e) {
            $io->error('❌ Error checking database schema: ' . $e->getMessage());
            return false;
        }
    }
}
