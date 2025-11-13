<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add tenant_id foreign keys to all tenant-scoped entities for complete multi-tenancy
 * This migration checks if tables exist before attempting to modify them
 */
final class Version20251113000001 extends AbstractMigration
{
    private array $tables = [
        'business_process',
        'isms_context',
        'isms_objective',
        'internal_audit',
        'management_review',
        'training',
        'vulnerabilities',
        'patches',
        'crisis_teams',
        'audit_checklist',
        'workflows',
        'workflow_instances',
        'workflow_steps',
    ];

    public function getDescription(): string
    {
        return 'Add tenant_id foreign keys to entities for complete multi-tenancy support (only if tables exist)';
    }

    public function up(Schema $schema): void
    {
        // Get existing tables
        $existingTables = $this->getExistingTables();

        // Add tenant_id columns for existing tables only
        foreach ($this->tables as $table) {
            if (in_array($table, $existingTables)) {
                // Check if column already exists
                if (!$this->columnExists($table, 'tenant_id')) {
                    $this->addSql("ALTER TABLE $table ADD tenant_id INT DEFAULT NULL");
                }
            }
        }

        // Add foreign key constraints for existing tables
        foreach ($this->tables as $table) {
            if (in_array($table, $existingTables) && $this->columnExists($table, 'tenant_id')) {
                $constraintName = 'FK_' . $table . '_tenant';
                if (!$this->constraintExists($table, $constraintName)) {
                    $this->addSql("ALTER TABLE $table ADD CONSTRAINT $constraintName FOREIGN KEY (tenant_id) REFERENCES tenant (id)");
                }
            }
        }

        // Add indexes for existing tables
        foreach ($this->tables as $table) {
            if (in_array($table, $existingTables) && $this->columnExists($table, 'tenant_id')) {
                $indexName = 'IDX_' . $table . '_tenant';
                if (!$this->indexExists($table, $indexName)) {
                    $this->addSql("CREATE INDEX $indexName ON $table (tenant_id)");
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        $existingTables = $this->getExistingTables();

        // Drop foreign keys
        foreach ($this->tables as $table) {
            if (in_array($table, $existingTables)) {
                $constraintName = 'FK_' . $table . '_tenant';
                if ($this->constraintExists($table, $constraintName)) {
                    $this->addSql("ALTER TABLE $table DROP FOREIGN KEY $constraintName");
                }
            }
        }

        // Drop indexes
        foreach ($this->tables as $table) {
            if (in_array($table, $existingTables)) {
                $indexName = 'IDX_' . $table . '_tenant';
                if ($this->indexExists($table, $indexName)) {
                    $this->addSql("DROP INDEX $indexName ON $table");
                }
            }
        }

        // Drop columns
        foreach ($this->tables as $table) {
            if (in_array($table, $existingTables) && $this->columnExists($table, 'tenant_id')) {
                $this->addSql("ALTER TABLE $table DROP tenant_id");
            }
        }
    }

    private function getExistingTables(): array
    {
        $sm = $this->connection->createSchemaManager();
        $tables = $sm->listTableNames();
        return $tables;
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $sm = $this->connection->createSchemaManager();
            $columns = $sm->listTableColumns($table);
            return isset($columns[$column]);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        try {
            $sm = $this->connection->createSchemaManager();
            $foreignKeys = $sm->listTableForeignKeys($table);
            foreach ($foreignKeys as $fk) {
                if ($fk->getName() === $constraint) {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $sm = $this->connection->createSchemaManager();
            $indexes = $sm->listTableIndexes($table);
            return isset($indexes[strtolower($index)]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
