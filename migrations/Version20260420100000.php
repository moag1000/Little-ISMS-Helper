<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TISAX / VDA-ISA 6.0 Kapitel 8 — Prototype-Protection-Assessment.
 *
 * Creates two tables:
 *   prototype_protection_assessment  — one row per assessment
 *   prototype_protection_evidence    — M:M join to document
 *
 * Uses `CREATE TABLE IF NOT EXISTS` so re-running on an existing schema
 * is a no-op — required by this project's migration style guide.
 */
final class Version20260420100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TISAX Kap. 8: prototype_protection_assessment + evidence M:M';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS prototype_protection_assessment (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                supplier_id INT DEFAULT NULL,
                location_id INT DEFAULT NULL,
                assessor_id INT DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                scope LONGTEXT DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                tisax_level VARCHAR(5) DEFAULT NULL,
                required_labels JSON DEFAULT NULL COMMENT '(DC2Type:json)',
                assessment_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                next_assessment_due DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                overall_result VARCHAR(30) DEFAULT NULL,
                physical_result VARCHAR(30) DEFAULT NULL,
                physical_notes LONGTEXT DEFAULT NULL,
                organisation_result VARCHAR(30) DEFAULT NULL,
                organisation_notes LONGTEXT DEFAULT NULL,
                handling_result VARCHAR(30) DEFAULT NULL,
                handling_notes LONGTEXT DEFAULT NULL,
                trial_operation_result VARCHAR(30) DEFAULT NULL,
                trial_operation_notes LONGTEXT DEFAULT NULL,
                events_result VARCHAR(30) DEFAULT NULL,
                events_notes LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_ppa_tenant (tenant_id),
                INDEX idx_ppa_status (status),
                INDEX idx_ppa_assessment_date (assessment_date),
                INDEX idx_ppa_supplier (supplier_id),
                INDEX idx_ppa_location (location_id),
                CONSTRAINT fk_ppa_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id),
                CONSTRAINT fk_ppa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE SET NULL,
                CONSTRAINT fk_ppa_location FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL,
                CONSTRAINT fk_ppa_assessor FOREIGN KEY (assessor_id) REFERENCES users (id) ON DELETE SET NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS prototype_protection_evidence (
                prototype_protection_assessment_id INT NOT NULL,
                document_id INT NOT NULL,
                INDEX idx_ppe_assessment (prototype_protection_assessment_id),
                INDEX idx_ppe_document (document_id),
                CONSTRAINT fk_ppe_assessment FOREIGN KEY (prototype_protection_assessment_id) REFERENCES prototype_protection_assessment (id) ON DELETE CASCADE,
                CONSTRAINT fk_ppe_document FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE,
                PRIMARY KEY(prototype_protection_assessment_id, document_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS prototype_protection_evidence');
        $this->addSql('DROP TABLE IF EXISTS prototype_protection_assessment');
    }
}
