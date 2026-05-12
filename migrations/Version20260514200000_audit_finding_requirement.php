<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F15.4 — audit_finding_requirement pivot table.
 *
 * Many-to-many between AuditFinding and ComplianceRequirement.
 * Linking triggers AutoTaskCreator to create CorrectiveAction tasks
 * for each requirement's responsible owner (ISO 27001 Cl. 10.1).
 */
final class Version20260514200000_audit_finding_requirement extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'F15.4 — audit_finding_requirement M2M pivot table (AuditFinding ↔ ComplianceRequirement)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS audit_finding_requirement (
            audit_finding_id INT NOT NULL,
            compliance_requirement_id INT NOT NULL,
            PRIMARY KEY (audit_finding_id, compliance_requirement_id),
            INDEX idx_afr_finding (audit_finding_id),
            INDEX idx_afr_requirement (compliance_requirement_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');

        $this->addSql('ALTER TABLE audit_finding_requirement
            ADD CONSTRAINT FK_afr_finding FOREIGN KEY (audit_finding_id)
                REFERENCES audit_findings (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_afr_requirement FOREIGN KEY (compliance_requirement_id)
                REFERENCES compliance_requirement (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_finding_requirement DROP FOREIGN KEY FK_afr_finding');
        $this->addSql('ALTER TABLE audit_finding_requirement DROP FOREIGN KEY FK_afr_requirement');
        $this->addSql('DROP TABLE IF EXISTS audit_finding_requirement');
    }
}
