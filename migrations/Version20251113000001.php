<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add tenant_id foreign keys to all tenant-scoped entities for complete multi-tenancy
 * This migration checks if tables and columns exist before attempting to modify them
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
        // Add tenant_id columns for existing tables only
        foreach ($this->tables as $table) {
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );

            if ($tableExists) {
                $columnExists = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id'",
                    [$table]
                );

                if (!$columnExists) {
                    $this->addSql("ALTER TABLE $table ADD tenant_id INT DEFAULT NULL");
                }
            }
        }

        // Add foreign key constraints for existing tables
        foreach ($this->tables as $table) {
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );

            if ($tableExists) {
                $columnExists = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id'",
                    [$table]
                );

                if ($columnExists) {
                    $constraintName = 'FK_' . $table . '_tenant';
                    $constraintExists = $this->connection->fetchOne(
                        "SELECT COUNT(*) FROM information_schema.table_constraints
                         WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?",
                        [$table, $constraintName]
                    );

                    if (!$constraintExists) {
                        $this->addSql("ALTER TABLE $table ADD CONSTRAINT $constraintName FOREIGN KEY (tenant_id) REFERENCES tenant (id)");
                    }
                }
            }
        }

        // Add indexes for existing tables
        foreach ($this->tables as $table) {
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );

            if ($tableExists) {
                $columnExists = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id'",
                    [$table]
                );

                if ($columnExists) {
                    $indexName = 'IDX_' . $table . '_tenant';
                    $indexExists = $this->connection->fetchOne(
                        "SELECT COUNT(*) FROM information_schema.statistics
                         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
                        [$table, $indexName]
                    );

                    if (!$indexExists) {
                        $this->addSql("CREATE INDEX $indexName ON $table (tenant_id)");
                    }
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys
        foreach ($this->tables as $table) {
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );

            if ($tableExists) {
                $constraintName = 'FK_' . $table . '_tenant';
                $constraintExists = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM information_schema.table_constraints
                     WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?",
                    [$table, $constraintName]
                );

                if ($constraintExists) {
                    $this->addSql("ALTER TABLE $table DROP FOREIGN KEY $constraintName");
                }
            }
        }

        // Drop indexes
        foreach ($this->tables as $table) {
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );

            if ($tableExists) {
                $indexName = 'IDX_' . $table . '_tenant';
                $indexExists = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
                    [$table, $indexName]
                );

                if ($indexExists) {
                    $this->addSql("DROP INDEX $indexName ON $table");
                }
            }
        }

        // Drop columns
        foreach ($this->tables as $table) {
            $tableExists = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );

            if ($tableExists) {
                $columnExists = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id'",
                    [$table]
                );

                if ($columnExists) {
                    $this->addSql("ALTER TABLE $table DROP tenant_id");
                }
            }
        }
    }
}
