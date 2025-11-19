<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251119140825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRITICAL-08 Phase 1: Create DataBreach entity for GDPR Art. 33/34 compliance (72h notification requirement)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_breach (id INT AUTO_INCREMENT NOT NULL, reference_number VARCHAR(50) NOT NULL, title VARCHAR(255) NOT NULL, status VARCHAR(30) DEFAULT \'draft\' NOT NULL, severity VARCHAR(20) NOT NULL, affected_data_subjects INT DEFAULT NULL, data_categories JSON NOT NULL, data_subject_categories JSON NOT NULL, breach_nature LONGTEXT NOT NULL, likely_consequences LONGTEXT NOT NULL, measures_taken LONGTEXT NOT NULL, mitigation_measures LONGTEXT DEFAULT NULL, requires_authority_notification TINYINT(1) DEFAULT 1 NOT NULL, no_notification_reason LONGTEXT DEFAULT NULL, supervisory_authority_notified_at DATETIME DEFAULT NULL, supervisory_authority_name VARCHAR(255) DEFAULT NULL, supervisory_authority_reference VARCHAR(100) DEFAULT NULL, notification_delay_reason LONGTEXT DEFAULT NULL, notification_method VARCHAR(50) DEFAULT NULL, notification_documents JSON DEFAULT NULL, requires_subject_notification TINYINT(1) DEFAULT 0 NOT NULL, no_subject_notification_reason LONGTEXT DEFAULT NULL, data_subjects_notified_at DATETIME DEFAULT NULL, subject_notification_method VARCHAR(50) DEFAULT NULL, subjects_notified INT DEFAULT NULL, subject_notification_documents JSON DEFAULT NULL, risk_level VARCHAR(20) DEFAULT NULL, risk_assessment LONGTEXT DEFAULT NULL, special_categories_affected TINYINT(1) DEFAULT 0 NOT NULL, criminal_data_affected TINYINT(1) DEFAULT 0 NOT NULL, root_cause LONGTEXT DEFAULT NULL, lessons_learned LONGTEXT DEFAULT NULL, follow_up_actions JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, tenant_id INT NOT NULL, incident_id INT NOT NULL, processing_activity_id INT DEFAULT NULL, data_protection_officer_id INT DEFAULT NULL, assessor_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_5F46FA6C8BF1AE50 (reference_number), UNIQUE INDEX UNIQ_5F46FA6C59E53FB9 (incident_id), INDEX IDX_5F46FA6C72D4D63B (processing_activity_id), INDEX IDX_5F46FA6CF081EB64 (data_protection_officer_id), INDEX IDX_5F46FA6CA5E4B630 (assessor_id), INDEX IDX_5F46FA6CB03A8386 (created_by_id), INDEX IDX_5F46FA6C896DBBDE (updated_by_id), INDEX idx_data_breach_tenant (tenant_id), INDEX idx_data_breach_status (status), INDEX idx_data_breach_severity (severity), INDEX idx_data_breach_authority_notified (supervisory_authority_notified_at), INDEX idx_data_breach_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE data_breach ADD CONSTRAINT FK_5F46FA6C9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE data_breach ADD CONSTRAINT FK_5F46FA6C59E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE data_breach ADD CONSTRAINT FK_5F46FA6C72D4D63B FOREIGN KEY (processing_activity_id) REFERENCES processing_activity (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE data_breach ADD CONSTRAINT FK_5F46FA6CF081EB64 FOREIGN KEY (data_protection_officer_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE data_breach ADD CONSTRAINT FK_5F46FA6CA5E4B630 FOREIGN KEY (assessor_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE data_breach ADD CONSTRAINT FK_5F46FA6CB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE data_breach ADD CONSTRAINT FK_5F46FA6C896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY FK_5F46FA6C9033212A');
        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY FK_5F46FA6C59E53FB9');
        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY FK_5F46FA6C72D4D63B');
        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY FK_5F46FA6CF081EB64');
        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY FK_5F46FA6CA5E4B630');
        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY FK_5F46FA6CB03A8386');
        $this->addSql('ALTER TABLE data_breach DROP FOREIGN KEY FK_5F46FA6C896DBBDE');
        $this->addSql('DROP TABLE data_breach');
    }
}
