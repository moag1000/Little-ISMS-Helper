<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add tenant_id to isms_context table - Simplified version
 */
final class Version20251113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant_id column to isms_context table (simplified, safe version)';
    }

    public function up(Schema $schema): void
    {
        // Check if table exists
        $tableExists = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name = 'isms_context'"
        );

        if (!$tableExists) {
            $this->write('Table isms_context does not exist, skipping migration');
            return;
        }

        // Check if column already exists
        $columnExists = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
             AND table_name = 'isms_context'
             AND column_name = 'tenant_id'"
        );

        if ($columnExists) {
            $this->write('Column tenant_id already exists in isms_context, skipping');
            return;
        }

        // Add the column
        $this->addSql('ALTER TABLE isms_context ADD tenant_id INT DEFAULT NULL');

        // Add index
        $this->addSql('CREATE INDEX IDX_isms_context_tenant ON isms_context (tenant_id)');

        // Add foreign key
        $this->addSql('ALTER TABLE isms_context ADD CONSTRAINT FK_isms_context_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        $this->write('Successfully added tenant_id column to isms_context');
    }

    public function down(Schema $schema): void
    {
        // Check if table exists
        $tableExists = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name = 'isms_context'"
        );

        if (!$tableExists) {
            return;
        }

        // Check if column exists
        $columnExists = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
             AND table_name = 'isms_context'
             AND column_name = 'tenant_id'"
        );

        if (!$columnExists) {
            return;
        }

        // Remove foreign key
        $fkExists = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM information_schema.table_constraints
             WHERE table_schema = DATABASE()
             AND table_name = 'isms_context'
             AND constraint_name = 'FK_isms_context_tenant'"
        );

        if ($fkExists) {
            $this->addSql('ALTER TABLE isms_context DROP FOREIGN KEY FK_isms_context_tenant');
        }

        // Remove index
        $idxExists = $this->connection->fetchOne(
            "SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
             AND table_name = 'isms_context'
             AND index_name = 'IDX_isms_context_tenant'"
        );

        if ($idxExists) {
            $this->addSql('DROP INDEX IDX_isms_context_tenant ON isms_context');
        }

        // Remove column
        $this->addSql('ALTER TABLE isms_context DROP tenant_id');
    }
}
