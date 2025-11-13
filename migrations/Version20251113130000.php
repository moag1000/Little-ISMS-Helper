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
        $this->write('Adding tenant_id columns to all tenant-scoped entities...');

        $addedCount = 0;
        $skippedCount = 0;
        $notFoundCount = 0;

        foreach ($this->tables as $table) {
            // Check if table exists
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );

            if (!$tableExists) {
                $this->write("⏭️  Skipping $table (table does not exist)");
                $notFoundCount++;
                continue;
            }

            // Check if column already exists
            $columnExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id'",
                [$table]
            );

            if ($columnExists) {
                $this->write("✅ Skipping $table (tenant_id already exists)");
                $skippedCount++;
                continue;
            }

            // Add the column
            $this->addSql("ALTER TABLE $table ADD tenant_id INT DEFAULT NULL");
            $this->write("✨ Added tenant_id to $table");
            $addedCount++;
        }

        $this->write("\n📊 Summary:");
        $this->write("   ✨ Added: $addedCount tables");
        $this->write("   ✅ Skipped (already exists): $skippedCount tables");
        $this->write("   ⏭️  Not found: $notFoundCount tables");
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
