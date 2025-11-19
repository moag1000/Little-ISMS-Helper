<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251119132242 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRITICAL-07 Phase 1: Create DataProtectionImpactAssessment entity (DPIA/DSFA per Art. 35 GDPR)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_protection_impact_assessment (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, reference_number VARCHAR(50) NOT NULL, version VARCHAR(20) DEFAULT \'1.0\' NOT NULL, processing_description LONGTEXT NOT NULL, processing_purposes LONGTEXT NOT NULL, data_categories JSON NOT NULL, data_subject_categories JSON NOT NULL, estimated_data_subjects INT DEFAULT NULL, data_retention_period LONGTEXT DEFAULT NULL, data_flow_description LONGTEXT DEFAULT NULL, necessity_assessment LONGTEXT NOT NULL, proportionality_assessment LONGTEXT NOT NULL, legal_basis VARCHAR(50) NOT NULL, legislative_compliance LONGTEXT DEFAULT NULL, identified_risks JSON NOT NULL, risk_level VARCHAR(20) NOT NULL, likelihood VARCHAR(20) DEFAULT NULL, impact VARCHAR(20) DEFAULT NULL, data_subject_risks LONGTEXT DEFAULT NULL, technical_measures LONGTEXT NOT NULL, organizational_measures LONGTEXT NOT NULL, compliance_measures LONGTEXT DEFAULT NULL, residual_risk_assessment LONGTEXT DEFAULT NULL, residual_risk_level VARCHAR(20) DEFAULT NULL, dpo_consultation_date DATE DEFAULT NULL, dpo_advice LONGTEXT DEFAULT NULL, data_subjects_consulted TINYINT(1) DEFAULT 0 NOT NULL, data_subject_consultation_details LONGTEXT DEFAULT NULL, stakeholders_consulted JSON DEFAULT NULL, requires_supervisory_consultation TINYINT(1) DEFAULT 0 NOT NULL, supervisory_consultation_date DATE DEFAULT NULL, supervisory_authority_feedback LONGTEXT DEFAULT NULL, status VARCHAR(30) DEFAULT \'draft\' NOT NULL, approval_date DATE DEFAULT NULL, approval_comments LONGTEXT DEFAULT NULL, rejection_reason LONGTEXT DEFAULT NULL, review_required TINYINT(1) DEFAULT 0 NOT NULL, last_review_date DATE DEFAULT NULL, next_review_date DATE DEFAULT NULL, review_frequency_months INT DEFAULT NULL, review_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, tenant_id INT NOT NULL, processing_activity_id INT DEFAULT NULL, data_protection_officer_id INT DEFAULT NULL, conductor_id INT DEFAULT NULL, approver_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_1ECB684C8BF1AE50 (reference_number), UNIQUE INDEX UNIQ_1ECB684C72D4D63B (processing_activity_id), INDEX IDX_1ECB684CF081EB64 (data_protection_officer_id), INDEX IDX_1ECB684CA49DECF0 (conductor_id), INDEX IDX_1ECB684CBB23766C (approver_id), INDEX IDX_1ECB684CB03A8386 (created_by_id), INDEX IDX_1ECB684C896DBBDE (updated_by_id), INDEX idx_dpia_tenant (tenant_id), INDEX idx_dpia_status (status), INDEX idx_dpia_risk_level (risk_level), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dpia_control (data_protection_impact_assessment_id INT NOT NULL, control_id INT NOT NULL, INDEX IDX_34F86BE9BE534B1D (data_protection_impact_assessment_id), INDEX IDX_34F86BE932BEC70E (control_id), PRIMARY KEY (data_protection_impact_assessment_id, control_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT FK_1ECB684C9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT FK_1ECB684C72D4D63B FOREIGN KEY (processing_activity_id) REFERENCES processing_activity (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT FK_1ECB684CF081EB64 FOREIGN KEY (data_protection_officer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT FK_1ECB684CA49DECF0 FOREIGN KEY (conductor_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT FK_1ECB684CBB23766C FOREIGN KEY (approver_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT FK_1ECB684CB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE data_protection_impact_assessment ADD CONSTRAINT FK_1ECB684C896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE dpia_control ADD CONSTRAINT FK_34F86BE9BE534B1D FOREIGN KEY (data_protection_impact_assessment_id) REFERENCES data_protection_impact_assessment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dpia_control ADD CONSTRAINT FK_34F86BE932BEC70E FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY FK_1ECB684C9033212A');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY FK_1ECB684C72D4D63B');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY FK_1ECB684CF081EB64');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY FK_1ECB684CA49DECF0');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY FK_1ECB684CBB23766C');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY FK_1ECB684CB03A8386');
        $this->addSql('ALTER TABLE data_protection_impact_assessment DROP FOREIGN KEY FK_1ECB684C896DBBDE');
        $this->addSql('ALTER TABLE dpia_control DROP FOREIGN KEY FK_34F86BE9BE534B1D');
        $this->addSql('ALTER TABLE dpia_control DROP FOREIGN KEY FK_34F86BE932BEC70E');
        $this->addSql('DROP TABLE data_protection_impact_assessment');
        $this->addSql('DROP TABLE dpia_control');
    }
}
