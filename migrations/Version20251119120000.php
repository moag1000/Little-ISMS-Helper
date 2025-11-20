<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates ProcessingActivity entity (VVT/ROPA - Verzeichnis von Verarbeitungstätigkeiten / Record of Processing Activities)
 * Required for GDPR Art. 30 compliance
 * MUST run BEFORE Version20251119132242 (DPIA) which references this table
 */
final class Version20251119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ProcessingActivity table (VVT/ROPA per Art. 30 GDPR) - CRITICAL: Must run before DPIA migration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE processing_activity (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, purposes JSON NOT NULL, data_subject_categories JSON NOT NULL, estimated_data_subjects_count INT DEFAULT NULL, personal_data_categories JSON NOT NULL, processes_special_categories TINYINT(1) DEFAULT 0 NOT NULL, special_categories_details JSON DEFAULT NULL, processes_criminal_data TINYINT(1) DEFAULT 0 NOT NULL, recipient_categories JSON DEFAULT NULL, recipient_details LONGTEXT DEFAULT NULL, has_third_country_transfer TINYINT(1) DEFAULT 0 NOT NULL, third_countries JSON DEFAULT NULL, transfer_safeguards VARCHAR(50) DEFAULT NULL, retention_period LONGTEXT DEFAULT NULL, retention_period_days INT DEFAULT NULL, retention_legal_basis LONGTEXT DEFAULT NULL, technical_organizational_measures LONGTEXT DEFAULT NULL, legal_basis VARCHAR(50) NOT NULL, legal_basis_details LONGTEXT DEFAULT NULL, legal_basis_special_categories VARCHAR(50) DEFAULT NULL, responsible_department VARCHAR(255) DEFAULT NULL, involves_processors TINYINT(1) DEFAULT 0 NOT NULL, processors JSON DEFAULT NULL, is_joint_controller TINYINT(1) DEFAULT 0 NOT NULL, joint_controller_details JSON DEFAULT NULL, is_high_risk TINYINT(1) DEFAULT 0 NOT NULL, dpia_completed TINYINT(1) DEFAULT 0 NOT NULL, dpia_date DATE DEFAULT NULL, risk_level VARCHAR(20) DEFAULT NULL, data_sources JSON DEFAULT NULL, has_automated_decision_making TINYINT(1) DEFAULT 0 NOT NULL, automated_decision_making_details LONGTEXT DEFAULT NULL, status VARCHAR(20) DEFAULT \'draft\' NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, last_review_date DATE DEFAULT NULL, next_review_date DATE DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, tenant_id INT NOT NULL, contact_person_id INT DEFAULT NULL, data_protection_officer_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_CBC21BBA4F8A983C (contact_person_id), INDEX IDX_CBC21BBAF081EB64 (data_protection_officer_id), INDEX IDX_CBC21BBAB03A8386 (created_by_id), INDEX IDX_CBC21BBA896DBBDE (updated_by_id), INDEX idx_processing_activity_tenant (tenant_id), INDEX idx_processing_activity_legal_basis (legal_basis), INDEX idx_processing_activity_high_risk (is_high_risk), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE processing_activity_control (processing_activity_id INT NOT NULL, control_id INT NOT NULL, INDEX IDX_7999A91872D4D63B (processing_activity_id), INDEX IDX_7999A91832BEC70E (control_id), PRIMARY KEY (processing_activity_id, control_id)) DEFAULT CHARACTER SET utf8mb4');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE processing_activity ADD CONSTRAINT FK_CBC21BBA9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE processing_activity ADD CONSTRAINT FK_CBC21BBA4F8A983C FOREIGN KEY (contact_person_id) REFERENCES person (id)');
        $this->addSql('ALTER TABLE processing_activity ADD CONSTRAINT FK_CBC21BBAF081EB64 FOREIGN KEY (data_protection_officer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE processing_activity ADD CONSTRAINT FK_CBC21BBAB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE processing_activity ADD CONSTRAINT FK_CBC21BBA896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE processing_activity_control ADD CONSTRAINT FK_7999A91872D4D63B FOREIGN KEY (processing_activity_id) REFERENCES processing_activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE processing_activity_control ADD CONSTRAINT FK_7999A91832BEC70E FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY FK_CBC21BBA9033212A');
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY FK_CBC21BBA4F8A983C');
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY FK_CBC21BBAF081EB64');
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY FK_CBC21BBAB03A8386');
        $this->addSql('ALTER TABLE processing_activity DROP FOREIGN KEY FK_CBC21BBA896DBBDE');
        $this->addSql('ALTER TABLE processing_activity_control DROP FOREIGN KEY FK_7999A91872D4D63B');
        $this->addSql('ALTER TABLE processing_activity_control DROP FOREIGN KEY FK_7999A91832BEC70E');
        $this->addSql('DROP TABLE processing_activity');
        $this->addSql('DROP TABLE processing_activity_control');
    }
}
