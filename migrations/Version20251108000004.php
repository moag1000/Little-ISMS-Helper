<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data Reuse Migration - Person, Location entities and enhanced relationships
 */
final class Version20251108000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Person and Location entities for data reuse, update PhysicalAccessLog, ThreatIntelligence, CryptographicOperation, Asset, and Incident relationships';
    }

    public function up(Schema $schema): void
    {
        // Create person table
        $this->addSql('CREATE TABLE person (
            id INT AUTO_INCREMENT NOT NULL,
            linked_user_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            full_name VARCHAR(255) NOT NULL,
            person_type VARCHAR(50) NOT NULL,
            badge_id VARCHAR(100) DEFAULT NULL,
            company VARCHAR(255) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            department VARCHAR(100) DEFAULT NULL,
            job_title VARCHAR(100) DEFAULT NULL,
            active TINYINT(1) NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            access_valid_from DATE DEFAULT NULL,
            access_valid_until DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_PERSON_BADGE (badge_id),
            INDEX idx_person_type (person_type),
            INDEX idx_person_badge (badge_id),
            INDEX idx_person_company (company),
            INDEX IDX_PERSON_USER (linked_user_id),
            INDEX IDX_PERSON_TENANT (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE person
            ADD CONSTRAINT FK_PERSON_USER FOREIGN KEY (linked_user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE person
            ADD CONSTRAINT FK_PERSON_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Create location table
        $this->addSql('CREATE TABLE location (
            id INT AUTO_INCREMENT NOT NULL,
            parent_location_id INT DEFAULT NULL,
            tenant_id INT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            location_type VARCHAR(100) NOT NULL,
            code VARCHAR(50) DEFAULT NULL,
            description LONGTEXT DEFAULT NULL,
            address LONGTEXT DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            country VARCHAR(100) DEFAULT NULL,
            postal_code VARCHAR(20) DEFAULT NULL,
            security_level VARCHAR(50) NOT NULL,
            requires_badge_access TINYINT(1) NOT NULL,
            requires_escort TINYINT(1) NOT NULL,
            camera_monitored TINYINT(1) NOT NULL,
            access_control_system VARCHAR(255) DEFAULT NULL,
            responsible_person VARCHAR(255) DEFAULT NULL,
            capacity INT DEFAULT NULL,
            square_meters NUMERIC(10, 2) DEFAULT NULL,
            active TINYINT(1) NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_location_type (location_type),
            INDEX idx_location_active (active),
            INDEX IDX_LOCATION_PARENT (parent_location_id),
            INDEX IDX_LOCATION_TENANT (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE location
            ADD CONSTRAINT FK_LOCATION_PARENT FOREIGN KEY (parent_location_id) REFERENCES location (id)');
        $this->addSql('ALTER TABLE location
            ADD CONSTRAINT FK_LOCATION_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

        // Update physical_access_log table - add person and location relationships
        $this->addSql('ALTER TABLE physical_access_log
            ADD person_id INT DEFAULT NULL AFTER id');
        $this->addSql('ALTER TABLE physical_access_log
            ADD location_entity_id INT DEFAULT NULL AFTER person_id');

        // Make legacy fields nullable
        $this->addSql('ALTER TABLE physical_access_log
            MODIFY person_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE physical_access_log
            MODIFY location VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE physical_access_log
            ADD CONSTRAINT FK_PHYSICAL_PERSON FOREIGN KEY (person_id) REFERENCES person (id)');
        $this->addSql('ALTER TABLE physical_access_log
            ADD CONSTRAINT FK_PHYSICAL_LOCATION FOREIGN KEY (location_entity_id) REFERENCES location (id)');
        $this->addSql('CREATE INDEX IDX_PHYSICAL_PERSON_NEW ON physical_access_log (person_id)');
        $this->addSql('CREATE INDEX IDX_PHYSICAL_LOCATION_NEW ON physical_access_log (location_entity_id)');

        // Add Asset relationship to ThreatIntelligence (ManyToMany)
        $this->addSql('CREATE TABLE threat_intelligence_affected_assets (
            threat_intelligence_id INT NOT NULL,
            asset_id INT NOT NULL,
            INDEX IDX_THREAT_ASSET_THREAT (threat_intelligence_id),
            INDEX IDX_THREAT_ASSET_ASSET (asset_id),
            PRIMARY KEY(threat_intelligence_id, asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE threat_intelligence_affected_assets
            ADD CONSTRAINT FK_THREAT_ASSET_THREAT FOREIGN KEY (threat_intelligence_id) REFERENCES threat_intelligence (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE threat_intelligence_affected_assets
            ADD CONSTRAINT FK_THREAT_ASSET_ASSET FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE');

        // Add Asset relationship to CryptographicOperation
        $this->addSql('ALTER TABLE cryptographic_operation
            ADD related_asset_id INT DEFAULT NULL AFTER user_id');
        $this->addSql('ALTER TABLE cryptographic_operation
            ADD CONSTRAINT FK_CRYPTO_ASSET FOREIGN KEY (related_asset_id) REFERENCES asset (id)');
        $this->addSql('CREATE INDEX IDX_CRYPTO_ASSET ON cryptographic_operation (related_asset_id)');

        // Add Location relationship to Asset
        $this->addSql('ALTER TABLE asset
            ADD physical_location_id INT DEFAULT NULL AFTER tenant_id');
        $this->addSql('ALTER TABLE asset
            ADD CONSTRAINT FK_ASSET_LOCATION FOREIGN KEY (physical_location_id) REFERENCES location (id)');
        $this->addSql('CREATE INDEX IDX_ASSET_LOCATION ON asset (physical_location_id)');

        // Add Threat → Incident chain
        $this->addSql('ALTER TABLE incident
            ADD originating_threat_id INT DEFAULT NULL AFTER tenant_id');
        $this->addSql('ALTER TABLE incident
            ADD CONSTRAINT FK_INCIDENT_THREAT FOREIGN KEY (originating_threat_id) REFERENCES threat_intelligence (id)');
        $this->addSql('CREATE INDEX IDX_INCIDENT_THREAT ON incident (originating_threat_id)');

        // Add Incident → failed Controls (ManyToMany)
        $this->addSql('CREATE TABLE incident_failed_controls (
            incident_id INT NOT NULL,
            control_id INT NOT NULL,
            INDEX IDX_INCIDENT_CONTROL_INCIDENT (incident_id),
            INDEX IDX_INCIDENT_CONTROL_CONTROL (control_id),
            PRIMARY KEY(incident_id, control_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE incident_failed_controls
            ADD CONSTRAINT FK_INCIDENT_CONTROL_INCIDENT FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_failed_controls
            ADD CONSTRAINT FK_INCIDENT_CONTROL_CONTROL FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Remove Incident → failed Controls
        $this->addSql('ALTER TABLE incident_failed_controls DROP FOREIGN KEY FK_INCIDENT_CONTROL_INCIDENT');
        $this->addSql('ALTER TABLE incident_failed_controls DROP FOREIGN KEY FK_INCIDENT_CONTROL_CONTROL');
        $this->addSql('DROP TABLE incident_failed_controls');

        // Remove Threat → Incident chain
        $this->addSql('ALTER TABLE incident DROP FOREIGN KEY FK_INCIDENT_THREAT');
        $this->addSql('DROP INDEX IDX_INCIDENT_THREAT ON incident');
        $this->addSql('ALTER TABLE incident DROP originating_threat_id');

        // Remove Asset → Location
        $this->addSql('ALTER TABLE asset DROP FOREIGN KEY FK_ASSET_LOCATION');
        $this->addSql('DROP INDEX IDX_ASSET_LOCATION ON asset');
        $this->addSql('ALTER TABLE asset DROP physical_location_id');

        // Remove CryptographicOperation → Asset
        $this->addSql('ALTER TABLE cryptographic_operation DROP FOREIGN KEY FK_CRYPTO_ASSET');
        $this->addSql('DROP INDEX IDX_CRYPTO_ASSET ON cryptographic_operation');
        $this->addSql('ALTER TABLE cryptographic_operation DROP related_asset_id');

        // Remove ThreatIntelligence → Asset
        $this->addSql('ALTER TABLE threat_intelligence_affected_assets DROP FOREIGN KEY FK_THREAT_ASSET_THREAT');
        $this->addSql('ALTER TABLE threat_intelligence_affected_assets DROP FOREIGN KEY FK_THREAT_ASSET_ASSET');
        $this->addSql('DROP TABLE threat_intelligence_affected_assets');

        // Remove PhysicalAccessLog relationships
        $this->addSql('ALTER TABLE physical_access_log DROP FOREIGN KEY FK_PHYSICAL_PERSON');
        $this->addSql('ALTER TABLE physical_access_log DROP FOREIGN KEY FK_PHYSICAL_LOCATION');
        $this->addSql('DROP INDEX IDX_PHYSICAL_PERSON_NEW ON physical_access_log');
        $this->addSql('DROP INDEX IDX_PHYSICAL_LOCATION_NEW ON physical_access_log');
        $this->addSql('ALTER TABLE physical_access_log DROP person_id');
        $this->addSql('ALTER TABLE physical_access_log DROP location_entity_id');

        // Revert legacy fields to NOT NULL
        $this->addSql('ALTER TABLE physical_access_log MODIFY person_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE physical_access_log MODIFY location VARCHAR(255) NOT NULL');

        // Drop location table
        $this->addSql('ALTER TABLE location DROP FOREIGN KEY FK_LOCATION_PARENT');
        $this->addSql('ALTER TABLE location DROP FOREIGN KEY FK_LOCATION_TENANT');
        $this->addSql('DROP TABLE location');

        // Drop person table
        $this->addSql('ALTER TABLE person DROP FOREIGN KEY FK_PERSON_USER');
        $this->addSql('ALTER TABLE person DROP FOREIGN KEY FK_PERSON_TENANT');
        $this->addSql('DROP TABLE person');
    }
}
