<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add CryptographicOperation, ThreatIntelligence, and PhysicalAccessLog tables for ISO 27001 compliance
 */
final class Version20251108000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tables for cryptographic operations logging (A.8.24), threat intelligence (A.5.7), and physical access control (A.7.2)';
    }

    public function up(Schema $schema): void
    {
        // Create cryptographic_operation table
        $this->addSql('CREATE TABLE cryptographic_operation (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            operation_type VARCHAR(50) NOT NULL,
            algorithm VARCHAR(100) NOT NULL,
            key_length INT DEFAULT NULL,
            key_identifier VARCHAR(255) DEFAULT NULL,
            purpose LONGTEXT DEFAULT NULL,
            data_classification VARCHAR(255) DEFAULT NULL,
            application_component VARCHAR(255) DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            error_message LONGTEXT DEFAULT NULL,
            timestamp DATETIME NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            metadata LONGTEXT DEFAULT NULL,
            compliance_relevant TINYINT(1) NOT NULL,
            INDEX idx_crypto_timestamp (timestamp),
            INDEX idx_crypto_operation_type (operation_type),
            INDEX IDX_CRYPTO_USER (user_id),
            INDEX IDX_CRYPTO_TENANT (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE cryptographic_operation
            ADD CONSTRAINT FK_CRYPTO_USER FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE cryptographic_operation
            ADD CONSTRAINT FK_CRYPTO_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Create threat_intelligence table
        $this->addSql('CREATE TABLE threat_intelligence (
            id INT AUTO_INCREMENT NOT NULL,
            assigned_to_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            threat_type VARCHAR(100) NOT NULL,
            severity VARCHAR(50) NOT NULL,
            source VARCHAR(255) DEFAULT NULL,
            cve_id VARCHAR(255) DEFAULT NULL,
            affected_systems JSON DEFAULT NULL,
            indicators JSON DEFAULT NULL,
            mitigation_recommendations LONGTEXT DEFAULT NULL,
            status VARCHAR(50) NOT NULL,
            detection_date DATE NOT NULL,
            mitigation_date DATE DEFAULT NULL,
            actions_taken LONGTEXT DEFAULT NULL,
            affects_organization TINYINT(1) NOT NULL,
            cvss_score INT DEFAULT NULL,
            `references` LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_threat_type (threat_type),
            INDEX idx_threat_severity (severity),
            INDEX idx_threat_status (status),
            INDEX idx_threat_detection_date (detection_date),
            INDEX IDX_THREAT_ASSIGNED_TO (assigned_to_id),
            INDEX IDX_THREAT_TENANT (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE threat_intelligence
            ADD CONSTRAINT FK_THREAT_ASSIGNED_TO FOREIGN KEY (assigned_to_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE threat_intelligence
            ADD CONSTRAINT FK_THREAT_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Create physical_access_log table
        $this->addSql('CREATE TABLE physical_access_log (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            person_name VARCHAR(255) NOT NULL,
            badge_id VARCHAR(100) DEFAULT NULL,
            location VARCHAR(255) NOT NULL,
            access_type VARCHAR(50) NOT NULL,
            access_time DATETIME NOT NULL,
            authentication_method VARCHAR(50) NOT NULL,
            purpose VARCHAR(100) DEFAULT NULL,
            escorted_by VARCHAR(255) DEFAULT NULL,
            company VARCHAR(255) DEFAULT NULL,
            authorized TINYINT(1) NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            alert_level VARCHAR(50) DEFAULT NULL,
            after_hours TINYINT(1) NOT NULL,
            door_or_gate VARCHAR(255) DEFAULT NULL,
            exit_time DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_physical_access_time (access_time),
            INDEX idx_physical_access_type (access_type),
            INDEX idx_physical_location (location),
            INDEX idx_physical_person (person_name),
            INDEX IDX_PHYSICAL_USER (user_id),
            INDEX IDX_PHYSICAL_TENANT (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE physical_access_log
            ADD CONSTRAINT FK_PHYSICAL_USER FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE physical_access_log
            ADD CONSTRAINT FK_PHYSICAL_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE physical_access_log DROP FOREIGN KEY FK_PHYSICAL_USER');
        $this->addSql('ALTER TABLE physical_access_log DROP FOREIGN KEY FK_PHYSICAL_TENANT');
        $this->addSql('DROP TABLE physical_access_log');

        $this->addSql('ALTER TABLE threat_intelligence DROP FOREIGN KEY FK_THREAT_ASSIGNED_TO');
        $this->addSql('ALTER TABLE threat_intelligence DROP FOREIGN KEY FK_THREAT_TENANT');
        $this->addSql('DROP TABLE threat_intelligence');

        $this->addSql('ALTER TABLE cryptographic_operation DROP FOREIGN KEY FK_CRYPTO_USER');
        $this->addSql('ALTER TABLE cryptographic_operation DROP FOREIGN KEY FK_CRYPTO_TENANT');
        $this->addSql('DROP TABLE cryptographic_operation');
    }
}
