<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add corporate structure support to Tenant entity
 * - Parent-child relationships
 * - Governance models (hierarchical, shared, independent)
 */
final class Version20251114000001_add_corporate_structure extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add corporate structure support (parent companies and subsidiaries) with governance models';
    }

    public function up(Schema $schema): void
    {
        // Add parent relationship (self-referencing foreign key)
        $this->addSql('ALTER TABLE tenant ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD governance_model VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD is_corporate_parent TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE tenant ADD corporate_notes LONGTEXT DEFAULT NULL');

        // Add foreign key constraint
        $this->addSql('ALTER TABLE tenant ADD CONSTRAINT FK_4E59C462727ACA70 FOREIGN KEY (parent_id) REFERENCES tenant (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4E59C462727ACA70 ON tenant (parent_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove foreign key and index
        $this->addSql('ALTER TABLE tenant DROP FOREIGN KEY FK_4E59C462727ACA70');
        $this->addSql('DROP INDEX IDX_4E59C462727ACA70 ON tenant');

        // Remove columns
        $this->addSql('ALTER TABLE tenant DROP parent_id');
        $this->addSql('ALTER TABLE tenant DROP governance_model');
        $this->addSql('ALTER TABLE tenant DROP is_corporate_parent');
        $this->addSql('ALTER TABLE tenant DROP corporate_notes');
    }
}
