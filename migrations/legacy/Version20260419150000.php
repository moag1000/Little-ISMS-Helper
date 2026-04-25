<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M-05: ComplianceRequirement ↔ Document M:M evidence link (ISO 27001 Clause 7.5).
 * Replaces the free-text evidenceDescription at the data layer.
 */
final class Version20260419150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'compliance_requirement_evidence: M:M ComplianceRequirement ↔ Document for structured evidence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS compliance_requirement_evidence (
            compliance_requirement_id INT NOT NULL,
            document_id INT NOT NULL,
            INDEX idx_cre_requirement (compliance_requirement_id),
            INDEX idx_cre_document (document_id),
            PRIMARY KEY (compliance_requirement_id, document_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql("SET @fk1 := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='compliance_requirement_evidence' AND CONSTRAINT_NAME='FK_CRE_REQUIREMENT')");
        $this->addSql("SET @sql := IF(@fk1 = 0, 'ALTER TABLE compliance_requirement_evidence ADD CONSTRAINT FK_CRE_REQUIREMENT FOREIGN KEY (compliance_requirement_id) REFERENCES compliance_requirement (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("SET @fk2 := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='compliance_requirement_evidence' AND CONSTRAINT_NAME='FK_CRE_DOCUMENT')");
        $this->addSql("SET @sql := IF(@fk2 = 0, 'ALTER TABLE compliance_requirement_evidence ADD CONSTRAINT FK_CRE_DOCUMENT FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_requirement_evidence DROP FOREIGN KEY FK_CRE_REQUIREMENT');
        $this->addSql('ALTER TABLE compliance_requirement_evidence DROP FOREIGN KEY FK_CRE_DOCUMENT');
        $this->addSql('DROP TABLE compliance_requirement_evidence');
    }
}
