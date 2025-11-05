<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create compliance framework tables for multi-framework compliance management (TISAX, DORA, etc.)';
    }

    public function up(Schema $schema): void
    {
        // Compliance Framework table
        $this->addSql('CREATE TABLE compliance_framework (
            id INT AUTO_INCREMENT NOT NULL,
            code VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            version VARCHAR(50) NOT NULL,
            applicable_industry VARCHAR(100) NOT NULL,
            regulatory_body VARCHAR(100) NOT NULL,
            mandatory TINYINT(1) NOT NULL DEFAULT 0,
            scope_description LONGTEXT DEFAULT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX IDX_FRAMEWORK_CODE (code),
            INDEX IDX_FRAMEWORK_ACTIVE (active)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Compliance Requirement table
        $this->addSql('CREATE TABLE compliance_requirement (
            id INT AUTO_INCREMENT NOT NULL,
            framework_id INT NOT NULL,
            requirement_id VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            category VARCHAR(100) DEFAULT NULL,
            priority VARCHAR(50) NOT NULL,
            applicable TINYINT(1) NOT NULL DEFAULT 1,
            applicability_justification LONGTEXT DEFAULT NULL,
            fulfillment_percentage INT NOT NULL DEFAULT 0,
            fulfillment_notes LONGTEXT DEFAULT NULL,
            evidence_description LONGTEXT DEFAULT NULL,
            data_source_mapping JSON DEFAULT NULL,
            responsible_person VARCHAR(100) DEFAULT NULL,
            target_date DATE DEFAULT NULL,
            last_assessment_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX IDX_FRAMEWORK (framework_id),
            INDEX IDX_REQUIREMENT_ID (requirement_id),
            INDEX IDX_PRIORITY (priority),
            INDEX IDX_FULFILLMENT (fulfillment_percentage)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Compliance Requirement - Control many-to-many
        $this->addSql('CREATE TABLE compliance_requirement_control (
            compliance_requirement_id INT NOT NULL,
            control_id INT NOT NULL,
            INDEX IDX_REQUIREMENT (compliance_requirement_id),
            INDEX IDX_CONTROL (control_id),
            PRIMARY KEY(compliance_requirement_id, control_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Compliance Mapping table (cross-framework mappings)
        $this->addSql('CREATE TABLE compliance_mapping (
            id INT AUTO_INCREMENT NOT NULL,
            source_requirement_id INT NOT NULL,
            target_requirement_id INT NOT NULL,
            mapping_percentage INT NOT NULL DEFAULT 0,
            mapping_type VARCHAR(50) NOT NULL,
            mapping_rationale LONGTEXT DEFAULT NULL,
            bidirectional TINYINT(1) NOT NULL DEFAULT 0,
            confidence VARCHAR(20) NOT NULL DEFAULT \'medium\',
            verified_by VARCHAR(100) DEFAULT NULL,
            verification_date DATE DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY(id),
            INDEX IDX_SOURCE (source_requirement_id),
            INDEX IDX_TARGET (target_requirement_id),
            INDEX IDX_MAPPING_TYPE (mapping_type),
            INDEX IDX_BIDIRECTIONAL (bidirectional)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Foreign keys
        $this->addSql('ALTER TABLE compliance_requirement
            ADD CONSTRAINT FK_CR_FRAMEWORK FOREIGN KEY (framework_id)
            REFERENCES compliance_framework (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE compliance_requirement_control
            ADD CONSTRAINT FK_CRC_REQUIREMENT FOREIGN KEY (compliance_requirement_id)
            REFERENCES compliance_requirement (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE compliance_requirement_control
            ADD CONSTRAINT FK_CRC_CONTROL FOREIGN KEY (control_id)
            REFERENCES control (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE compliance_mapping
            ADD CONSTRAINT FK_CM_SOURCE FOREIGN KEY (source_requirement_id)
            REFERENCES compliance_requirement (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE compliance_mapping
            ADD CONSTRAINT FK_CM_TARGET FOREIGN KEY (target_requirement_id)
            REFERENCES compliance_requirement (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS compliance_mapping');
        $this->addSql('DROP TABLE IF EXISTS compliance_requirement_control');
        $this->addSql('DROP TABLE IF EXISTS compliance_requirement');
        $this->addSql('DROP TABLE IF EXISTS compliance_framework');
    }
}
