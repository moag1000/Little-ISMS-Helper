<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to fix BusinessProcess entity mismatches
 *
 * Changes:
 * - Replace financial_impact (INT) with financial_impact_per_hour and financial_impact_per_day (DECIMAL)
 * - Replace dependencies (LONGTEXT) with dependencies_upstream and dependencies_downstream
 * - Remove minimum_resources column (not in entity)
 */
final class Version20251108000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix BusinessProcess table schema to match entity properties';
    }

    public function up(Schema $schema): void
    {
        // Remove old column
        $this->addSql('ALTER TABLE business_process DROP COLUMN financial_impact');

        // Add new financial impact columns
        $this->addSql('ALTER TABLE business_process ADD financial_impact_per_hour DECIMAL(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_process ADD financial_impact_per_day DECIMAL(10, 2) DEFAULT NULL');

        // Remove old dependencies column
        $this->addSql('ALTER TABLE business_process DROP COLUMN dependencies');

        // Add new dependency columns
        $this->addSql('ALTER TABLE business_process ADD dependencies_upstream LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE business_process ADD dependencies_downstream LONGTEXT DEFAULT NULL');

        // Remove minimum_resources column (not in entity)
        $this->addSql('ALTER TABLE business_process DROP COLUMN minimum_resources');
    }

    public function down(Schema $schema): void
    {
        // Restore old financial_impact column
        $this->addSql('ALTER TABLE business_process ADD financial_impact INT NOT NULL COMMENT "1-10 scale" DEFAULT 1');
        $this->addSql('ALTER TABLE business_process DROP COLUMN financial_impact_per_hour');
        $this->addSql('ALTER TABLE business_process DROP COLUMN financial_impact_per_day');

        // Restore old dependencies column
        $this->addSql('ALTER TABLE business_process ADD dependencies LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE business_process DROP COLUMN dependencies_upstream');
        $this->addSql('ALTER TABLE business_process DROP COLUMN dependencies_downstream');

        // Restore minimum_resources column
        $this->addSql('ALTER TABLE business_process ADD minimum_resources LONGTEXT DEFAULT NULL');
    }
}
