<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Location entity
 *
 * Creates centralized location management table for buildings, rooms, gates, etc.
 * Enables data reuse across PhysicalAccessLog, Asset, and other entities
 */
final class Version20251108000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create location table for centralized physical location management with self-referential hierarchy';
    }

    public function up(Schema $schema): void
    {
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
            square_meters DECIMAL(10, 2) DEFAULT NULL,
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
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE location DROP FOREIGN KEY FK_LOCATION_PARENT');
        $this->addSql('ALTER TABLE location DROP FOREIGN KEY FK_LOCATION_TENANT');
        $this->addSql('DROP TABLE location');
    }
}
