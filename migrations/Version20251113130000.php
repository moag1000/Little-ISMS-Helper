<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add tenant_id columns to ALL tenant-scoped entities
 * This is a comprehensive migration that handles all 31+ entities with tenant associations
 */
final class Version20251113130000 extends AbstractMigration
{
    private array $tables = [
        'asset',
        'audit_checklist',
        'bc_exercise',  // BCExercise has custom table name
        'business_continuity_plan',
        'business_process',
        'change_request',
        'control',
        'crisis_teams',
        'cryptographic_operation',
        'document',
        'incident',
        'interested_party',
        'internal_audit',
        'isms_context',
        'isms_objective',
        'location',
        'management_review',
        'patches',
        'person',
        'physical_access_log',
        'risk',
        'risk_appetite',
        'risk_treatment_plan',
        'supplier',
        'threat_intelligence',
        'training',
        'users',  // User entity uses 'users' table (plural)
        'vulnerabilities',
        'workflows',
        'workflow_instances',
        'workflow_steps',
    ];

    public function getDescription(): string
    {
        return 'Add tenant_id columns to all tenant-scoped entities (comprehensive migration)';
    }

    public function up(Schema $schema): void
    {
        $this->write('Adding tenant_id columns, indexes, and foreign keys to all tenant-scoped entities...');

        $addedColumns = 0;
        $addedIndexes = 0;
        $addedForeignKeys = 0;
        $skippedCount = 0;
        $notFoundCount = 0;

        // First pass: Add columns
        foreach ($this->tables as $table) {
            if (!$this->tableExists($table)) {
                $this->write("⏭️  Skipping $table (table does not exist)");
                $notFoundCount++;
                continue;
            }

            if ($this->columnExists($table, 'tenant_id')) {
                $this->write("✅ Column exists: $table.tenant_id");
                $skippedCount++;
            } else {
                $this->addSql("ALTER TABLE `$table` ADD `tenant_id` INT DEFAULT NULL");
                $this->write("✨ Added column: $table.tenant_id");
                $addedColumns++;
            }
        }

        // Second pass: Add indexes
        foreach ($this->tables as $table) {
            if (!$this->tableExists($table) || !$this->columnExists($table, 'tenant_id')) {
                continue;
            }

            $indexName = "IDX_{$table}_tenant";
            if (!$this->indexExists($table, $indexName)) {
                $this->addSql("CREATE INDEX `$indexName` ON `$table` (`tenant_id`)");
                $this->write("🔍 Added index: $indexName");
                $addedIndexes++;
            }
        }

        // Third pass: Add foreign keys (only if tenant table exists)
        if ($this->tableExists('tenant')) {
            foreach ($this->tables as $table) {
                if (!$this->tableExists($table) || !$this->columnExists($table, 'tenant_id')) {
                    continue;
                }

                $fkName = "FK_{$table}_tenant";
                if (!$this->foreignKeyExists($table, $fkName)) {
                    $this->addSql("ALTER TABLE `$table` ADD CONSTRAINT `$fkName` FOREIGN KEY (`tenant_id`) REFERENCES `tenant` (`id`)");
                    $this->write("🔗 Added foreign key: $fkName");
                    $addedForeignKeys++;
                }
            }
        } else {
            $this->write("⚠️  Skipping foreign keys (tenant table does not exist)");
        }

        $this->write("\n📊 Summary:");
        $this->write("   ✨ Added columns: $addedColumns");
        $this->write("   🔍 Added indexes: $addedIndexes");
        $this->write("   🔗 Added foreign keys: $addedForeignKeys");
        $this->write("   ✅ Skipped (already exists): $skippedCount");
        $this->write("   ⏭️  Tables not found: $notFoundCount");
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        );
    }

    private function foreignKeyExists(string $table, string $fkName): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'",
            [$table, $fkName]
        );
    }

    public function down(Schema $schema): void
    {
        $this->write('Removing tenant_id columns from all tenant-scoped entities...');

        foreach ($this->tables as $table) {
            // Check if table exists
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );

            if (!$tableExists) {
                continue;
            }

            // Check if column exists
            $columnExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id'",
                [$table]
            );

            if (!$columnExists) {
                continue;
            }

            // Check for foreign key and remove it first
            $foreignKeys = $this->connection->fetchAllAssociative(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ?
                 AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                 AND CONSTRAINT_NAME LIKE '%tenant%'",
                [$table]
            );

            foreach ($foreignKeys as $fk) {
                $this->addSql("ALTER TABLE $table DROP FOREIGN KEY {$fk['CONSTRAINT_NAME']}");
            }

            // Remove the column
            $this->addSql("ALTER TABLE $table DROP tenant_id");
            $this->write("Removed tenant_id from $table");
        }
    }
}
