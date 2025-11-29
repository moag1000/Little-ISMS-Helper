<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use Exception;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\MappingGapItemRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mapping-quality:sanity-check',
    description: 'Verify system prerequisites for mapping quality analysis'
)]
class MappingQualitySanityCheckCommand
{
    public function __construct(private readonly ComplianceFrameworkRepository $complianceFrameworkRepository, private readonly ComplianceRequirementRepository $complianceRequirementRepository, private readonly ComplianceMappingRepository $complianceMappingRepository, private readonly MappingGapItemRepository $mappingGapItemRepository, private readonly Connection $connection)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        $symfonyStyle->title('Mapping Quality Analysis - Sanity Check');
        $symfonyStyle->text('Verifying system prerequisites...');
        $symfonyStyle->newLine();
        $allChecks = true;
        // Check 1: Database tables exist
        $symfonyStyle->section('1. Database Schema');
        $allChecks = $this->checkDatabaseSchema($symfonyStyle) && $allChecks;
        $symfonyStyle->newLine();
        // Check 2: Frameworks
        $symfonyStyle->section('2. Compliance Frameworks');
        $allChecks = $this->checkFrameworks($symfonyStyle) && $allChecks;
        $symfonyStyle->newLine();
        // Check 3: Requirements
        $symfonyStyle->section('3. Compliance Requirements');
        $allChecks = $this->checkRequirements($symfonyStyle) && $allChecks;
        $symfonyStyle->newLine();
        // Check 4: Mappings
        $symfonyStyle->section('4. Compliance Mappings');
        $allChecks = $this->checkMappings($symfonyStyle) && $allChecks;
        $symfonyStyle->newLine();
        // Check 5: Migration status
        $symfonyStyle->section('5. Quality Analysis Fields');
        $allChecks = $this->checkQualityFields($symfonyStyle) && $allChecks;
        $symfonyStyle->newLine();
        // Summary
        $symfonyStyle->section('Summary');
        if ($allChecks) {
            $symfonyStyle->success('✅ All checks passed! System is ready for mapping quality analysis.');
            $symfonyStyle->text([
                'Next steps:',
                '  1. Run analysis: php bin/console app:analyze-mapping-quality',
                '  2. Open dashboard: /compliance/mapping-quality/',
            ]);
            return Command::SUCCESS;
        }
        $symfonyStyle->error('❌ Some checks failed. Please resolve the issues above before running analysis.');
        $symfonyStyle->text([
            'Common fixes:',
            '  - Run migration: php bin/console doctrine:migrations:migrate',
            '  - Import frameworks: php bin/console app:import-framework ISO27001',
            '  - Create mappings: php bin/console app:create-cross-framework-mappings',
        ]);
        return Command::FAILURE;
    }

    private function checkDatabaseSchema(SymfonyStyle $symfonyStyle): bool
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
        foreach ($requiredTables as $requiredTable) {
            if (in_array($requiredTable, $existingTables)) {
                $symfonyStyle->writeln("  ✅ Table <info>{$requiredTable}</info> exists");
            } else {
                $symfonyStyle->writeln("  ❌ Table <error>{$requiredTable}</error> NOT FOUND");
                $allTablesExist = false;
            }
        }

        if (!$allTablesExist) {
            $symfonyStyle->warning('Missing tables detected. Run: php bin/console doctrine:migrations:migrate');
        }

        return $allTablesExist;
    }

    private function checkFrameworks(SymfonyStyle $symfonyStyle): bool
    {
        $count = $this->complianceFrameworkRepository->count([]);

        if ($count === 0) {
            $symfonyStyle->error("❌ No frameworks found (0)");
            $symfonyStyle->text('  Fix: Import frameworks with: php bin/console app:import-framework ISO27001');
            return false;
        }

        $symfonyStyle->success("✅ Frameworks found: $count");

        // Show available frameworks
        $frameworks = $this->complianceFrameworkRepository->findAll();
        $frameworkCodes = array_map(fn(ComplianceFramework $f): ?string => $f->getCode(), $frameworks);
        $symfonyStyle->text('  Available: ' . implode(', ', array_slice($frameworkCodes, 0, 10)));

        if (count($frameworkCodes) > 10) {
            $symfonyStyle->text('  ... and ' . (count($frameworkCodes) - 10) . ' more');
        }

        return true;
    }

    private function checkRequirements(SymfonyStyle $symfonyStyle): bool
    {
        $count = $this->complianceRequirementRepository->count([]);

        if ($count === 0) {
            $symfonyStyle->error("❌ No requirements found (0)");
            $symfonyStyle->text('  Fix: Requirements are usually imported with frameworks');
            return false;
        }

        if ($count < 50) {
            $symfonyStyle->warning("⚠️  Only $count requirements found (minimum 50 recommended)");
            $symfonyStyle->text('  Consider importing more frameworks for better analysis');
        } else {
            $symfonyStyle->success("✅ Requirements found: $count");
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

        if ($result !== []) {
            $symfonyStyle->text('  Top frameworks by requirement count:');
            foreach ($result as $row) {
                $symfonyStyle->text(sprintf('    - %s: %d requirements', $row['code'], $row['req_count']));
            }
        }

        return $count > 0;
    }

    private function checkMappings(SymfonyStyle $symfonyStyle): bool
    {
        $count = $this->complianceMappingRepository->count([]);

        if ($count === 0) {
            $symfonyStyle->error("❌ No mappings found (0)");
            $symfonyStyle->text('  Fix: Create mappings with: php bin/console app:create-cross-framework-mappings');
            return false;
        }

        if ($count < 10) {
            $symfonyStyle->warning("⚠️  Only $count mappings found (minimum 10 recommended)");
        } else {
            $symfonyStyle->success("✅ Mappings found: $count");
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

        $symfonyStyle->text(sprintf(
            '  Status: %d analyzed, %d unanalyzed',
            $stats['analyzed'] ?? 0,
            $stats['unanalyzed'] ?? 0
        ));

        return $count > 0;
    }

    private function checkQualityFields(SymfonyStyle $symfonyStyle): bool
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

            foreach ($requiredColumns as $requiredColumn) {
                if (!in_array($requiredColumn, $columnNames)) {
                    $missingColumns[] = $requiredColumn;
                    $allColumnsExist = false;
                }
            }

            if (!$allColumnsExist) {
                $symfonyStyle->error('❌ Quality analysis fields missing from compliance_mapping table');
                $symfonyStyle->text('  Missing columns: ' . implode(', ', $missingColumns));
                $symfonyStyle->warning('  Fix: Run migration: php bin/console doctrine:migrations:migrate');
                return false;
            }

            $symfonyStyle->success('✅ All quality analysis fields exist in compliance_mapping');

            // Check gap_item table
            if (!in_array('mapping_gap_item', $schemaManager->listTableNames())) {
                $symfonyStyle->error('❌ mapping_gap_item table not found');
                $symfonyStyle->warning('  Fix: Run migration: php bin/console doctrine:migrations:migrate');
                return false;
            }

            $symfonyStyle->success('✅ mapping_gap_item table exists');

            // Check if any gaps have been created
            $gapCount = $this->mappingGapItemRepository->count([]);
            if ($gapCount > 0) {
                $symfonyStyle->text("  Gap items found: $gapCount");
            } else {
                $symfonyStyle->text('  Gap items: 0 (will be created after first analysis)');
            }

            return true;

        } catch (Exception $e) {
            $symfonyStyle->error('❌ Error checking database schema: ' . $e->getMessage());
            return false;
        }
    }
}
