<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * H-01: Structured Audit Findings + Corrective Actions (ISO 27001 Clause 10.1).
 * Required for certification — replaces free-text `internal_audit.findings`.
 */
final class Version20260418130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'H-01: audit_findings + corrective_actions tables (ISO 27001 Clause 10.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_findings (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            audit_id INT NOT NULL,
            related_control_id INT DEFAULT NULL,
            reported_by_id INT DEFAULT NULL,
            assigned_to_id INT DEFAULT NULL,
            finding_number VARCHAR(50) DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            type VARCHAR(50) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            status VARCHAR(30) NOT NULL,
            clause_reference VARCHAR(100) DEFAULT NULL,
            evidence LONGTEXT DEFAULT NULL,
            due_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_af_tenant (tenant_id),
            INDEX idx_af_audit (audit_id),
            INDEX idx_af_status (status),
            INDEX idx_af_severity (severity),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE audit_findings
            ADD CONSTRAINT FK_AF_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE audit_findings
            ADD CONSTRAINT FK_AF_AUDIT FOREIGN KEY (audit_id) REFERENCES internal_audit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_findings
            ADD CONSTRAINT FK_AF_CONTROL FOREIGN KEY (related_control_id) REFERENCES control (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audit_findings
            ADD CONSTRAINT FK_AF_REPORTER FOREIGN KEY (reported_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE audit_findings
            ADD CONSTRAINT FK_AF_ASSIGNEE FOREIGN KEY (assigned_to_id) REFERENCES users (id)');

        $this->addSql('CREATE TABLE corrective_actions (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            finding_id INT NOT NULL,
            responsible_person_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            root_cause_analysis LONGTEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL,
            planned_completion_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            actual_completion_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            effectiveness_review_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            effectiveness_notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_ca_tenant (tenant_id),
            INDEX idx_ca_finding (finding_id),
            INDEX idx_ca_status (status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE corrective_actions
            ADD CONSTRAINT FK_CA_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE corrective_actions
            ADD CONSTRAINT FK_CA_FINDING FOREIGN KEY (finding_id) REFERENCES audit_findings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE corrective_actions
            ADD CONSTRAINT FK_CA_USER FOREIGN KEY (responsible_person_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY FK_CA_USER');
        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY FK_CA_FINDING');
        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY FK_CA_TENANT');
        $this->addSql('DROP TABLE corrective_actions');

        $this->addSql('ALTER TABLE audit_findings DROP FOREIGN KEY FK_AF_ASSIGNEE');
        $this->addSql('ALTER TABLE audit_findings DROP FOREIGN KEY FK_AF_REPORTER');
        $this->addSql('ALTER TABLE audit_findings DROP FOREIGN KEY FK_AF_CONTROL');
        $this->addSql('ALTER TABLE audit_findings DROP FOREIGN KEY FK_AF_AUDIT');
        $this->addSql('ALTER TABLE audit_findings DROP FOREIGN KEY FK_AF_TENANT');
        $this->addSql('DROP TABLE audit_findings');
    }
}
