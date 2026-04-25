<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M-04: ComplianceFramework lifecycle state + successor reference.
 * Supports upgrade paths (ISO 27001:2013 → 2022, BSI C5:2020 → 2026).
 */
final class Version20260419170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M-04: compliance_framework.lifecycle_state + successor_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compliance_framework' AND COLUMN_NAME='lifecycle_state')");
        $this->addSql("SET @sql := IF(@col = 0, \"ALTER TABLE compliance_framework ADD lifecycle_state VARCHAR(20) NOT NULL DEFAULT 'active'\", 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compliance_framework' AND COLUMN_NAME='successor_id')");
        $this->addSql("SET @sql := IF(@col = 0, 'ALTER TABLE compliance_framework ADD successor_id INT DEFAULT NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='compliance_framework' AND CONSTRAINT_NAME='FK_CF_SUCCESSOR')");
        $this->addSql("SET @sql := IF(@fk = 0, 'ALTER TABLE compliance_framework ADD CONSTRAINT FK_CF_SUCCESSOR FOREIGN KEY (successor_id) REFERENCES compliance_framework (id) ON DELETE SET NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        // Seed known lifecycle states: BSI-C5 (2020) deprecated, BSI-C5-2026 active
        $this->addSql("UPDATE compliance_framework SET lifecycle_state = 'deprecated' WHERE code = 'BSI-C5'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_framework DROP FOREIGN KEY FK_CF_SUCCESSOR');
        $this->addSql('ALTER TABLE compliance_framework DROP successor_id, DROP lifecycle_state');
    }
}
