<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 1-7 Improvement Projects Migration
 *
 * New tables:
 * - data_subject_request (GDPR Art. 15-22 Betroffenenrechte)
 * - elementary_threat (BSI 200-3 Elementare Gefaehrdungen G 0.1-G 0.47)
 * - business_process_dependencies (Structured process dependencies)
 *
 * Altered tables:
 * - risk: reviewIntervalDays, communicationPlan, threatIntelligence FK, linkedVulnerability FK
 * - compliance_requirement: anforderungsTyp, absicherungsStufe (BSI Grundschutz)
 * - business_process: mbco, mbcoPercentage (ISO 22301 MBCO)
 * - business_continuity_plan: bsiPhase (BSI 200-4 phases)
 * - supplier: supplierRto, supplierRecoveryCapability, alternativeSupplier, bcmAssessmentDate, bcmAssessmentResult
 * - document: uploaded_by_id nullable + ON DELETE SET NULL (from Sprint 1 Batch 5)
 */
final class Version20260417200316 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 1-7: DataSubjectRequest, ElementaryThreat, BSI Absicherungsstufen, Risk entity extensions, BCM MBCO, Supplier BCM fields, Process dependencies';
    }

    public function up(Schema $schema): void
    {
        // === NEW TABLES ===

        // DataSubjectRequest (GDPR Art. 15-22)
        $this->addSql('CREATE TABLE data_subject_request (
            id INT AUTO_INCREMENT NOT NULL,
            request_type VARCHAR(30) NOT NULL,
            status VARCHAR(20) DEFAULT \'received\' NOT NULL,
            data_subject_name VARCHAR(255) NOT NULL,
            data_subject_email VARCHAR(255) DEFAULT NULL,
            data_subject_identifier VARCHAR(255) DEFAULT NULL,
            description LONGTEXT NOT NULL,
            received_at DATETIME NOT NULL,
            deadline_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            identity_verified TINYINT DEFAULT 0 NOT NULL,
            identity_verification_method VARCHAR(30) DEFAULT NULL,
            identity_verified_at DATETIME DEFAULT NULL,
            response_description LONGTEXT DEFAULT NULL,
            rejection_reason LONGTEXT DEFAULT NULL,
            extension_reason LONGTEXT DEFAULT NULL,
            extended_deadline_at DATETIME DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            tenant_id INT NOT NULL,
            assigned_to_id INT DEFAULT NULL,
            processing_activity_id INT DEFAULT NULL,
            INDEX IDX_EBA4CA2AF4BD7827 (assigned_to_id),
            INDEX IDX_EBA4CA2A72D4D63B (processing_activity_id),
            INDEX idx_dsr_tenant (tenant_id),
            INDEX idx_dsr_status (status),
            INDEX idx_dsr_request_type (request_type),
            INDEX idx_dsr_deadline (deadline_at),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE data_subject_request ADD CONSTRAINT FK_EBA4CA2A9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE data_subject_request ADD CONSTRAINT FK_EBA4CA2AF4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE data_subject_request ADD CONSTRAINT FK_EBA4CA2A72D4D63B FOREIGN KEY (processing_activity_id) REFERENCES processing_activity (id) ON DELETE SET NULL');

        // ElementaryThreat (BSI 200-3)
        $this->addSql('CREATE TABLE elementary_threat (
            id INT AUTO_INCREMENT NOT NULL,
            threat_id VARCHAR(10) NOT NULL,
            name VARCHAR(255) NOT NULL,
            name_en VARCHAR(255) DEFAULT NULL,
            category VARCHAR(50) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            UNIQUE INDEX UNIQ_1DBA8DFCB2891786 (threat_id),
            INDEX idx_elementary_threat_category (category),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        // BusinessProcess Dependencies (Self-referencing ManyToMany)
        $this->addSql('CREATE TABLE business_process_dependencies (
            process_id INT NOT NULL,
            depends_on_id INT NOT NULL,
            INDEX IDX_820E09667EC2F574 (process_id),
            INDEX IDX_820E09661E088F8 (depends_on_id),
            PRIMARY KEY (process_id, depends_on_id)
        ) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE business_process_dependencies ADD CONSTRAINT FK_820E09667EC2F574 FOREIGN KEY (process_id) REFERENCES business_process (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE business_process_dependencies ADD CONSTRAINT FK_820E09661E088F8 FOREIGN KEY (depends_on_id) REFERENCES business_process (id) ON DELETE CASCADE');

        // === ALTERED TABLES ===

        // Risk: new fields + FKs
        $this->addSql('ALTER TABLE risk ADD review_interval_days INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD communication_plan JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD threat_intelligence_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD linked_vulnerability_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D541F2BE5A0E FOREIGN KEY (threat_intelligence_id) REFERENCES threat_intelligence (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D5419F82DE33 FOREIGN KEY (linked_vulnerability_id) REFERENCES vulnerabilities (id) ON DELETE SET NULL');

        // ComplianceRequirement: BSI Absicherungsstufen
        $this->addSql('ALTER TABLE compliance_requirement ADD anforderungs_typ VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_requirement ADD absicherungs_stufe VARCHAR(20) DEFAULT NULL');

        // BusinessProcess: MBCO (ISO 22301)
        $this->addSql('ALTER TABLE business_process ADD mbco VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_process ADD mbco_percentage INT DEFAULT NULL');

        // BusinessContinuityPlan: BSI 200-4 Phase
        $this->addSql('ALTER TABLE business_continuity_plan ADD bsi_phase VARCHAR(30) DEFAULT NULL');

        // Supplier: BCM fields
        $this->addSql('ALTER TABLE supplier ADD supplier_rto INT DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier ADD supplier_recovery_capability VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier ADD alternative_supplier VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier ADD bcm_assessment_date DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE supplier ADD bcm_assessment_result VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Drop new tables
        $this->addSql('DROP TABLE data_subject_request');
        $this->addSql('DROP TABLE elementary_threat');
        $this->addSql('DROP TABLE business_process_dependencies');

        // Risk: remove new fields + FKs
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D541F2BE5A0E');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D5419F82DE33');
        $this->addSql('ALTER TABLE risk DROP review_interval_days');
        $this->addSql('ALTER TABLE risk DROP communication_plan');
        $this->addSql('ALTER TABLE risk DROP threat_intelligence_id');
        $this->addSql('ALTER TABLE risk DROP linked_vulnerability_id');

        // ComplianceRequirement
        $this->addSql('ALTER TABLE compliance_requirement DROP anforderungs_typ');
        $this->addSql('ALTER TABLE compliance_requirement DROP absicherungs_stufe');

        // BusinessProcess
        $this->addSql('ALTER TABLE business_process DROP mbco');
        $this->addSql('ALTER TABLE business_process DROP mbco_percentage');

        // BusinessContinuityPlan
        $this->addSql('ALTER TABLE business_continuity_plan DROP bsi_phase');

        // Supplier
        $this->addSql('ALTER TABLE supplier DROP supplier_rto');
        $this->addSql('ALTER TABLE supplier DROP supplier_recovery_capability');
        $this->addSql('ALTER TABLE supplier DROP alternative_supplier');
        $this->addSql('ALTER TABLE supplier DROP bcm_assessment_date');
        $this->addSql('ALTER TABLE supplier DROP bcm_assessment_result');
    }
}
