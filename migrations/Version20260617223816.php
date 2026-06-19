<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance-Certificate feature schema.
 *
 * Creates the two new entities `compliance_certificate` and
 * `certificate_coverage_rule`, the `compliance_fulfillment_evidence_documents`
 * ManyToMany join table, and adds the `verified_at` / `verified_by_id`
 * verification columns (incl. FK + index) to the existing
 * `compliance_requirement_fulfillment` table.
 */
final class Version20260617223816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Certificate tables (compliance_certificate, certificate_coverage_rule), '
            . 'fulfillment evidence-document join table and verification columns.';
    }

    /**
     * DDL statements commit implicitly in MySQL, which invalidates Doctrine's
     * per-migration SAVEPOINT. Run this migration non-transactionally.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE certificate_coverage_rule (id INT AUTO_INCREMENT NOT NULL, framework_code VARCHAR(100) NOT NULL, required_class VARCHAR(100) DEFAULT NULL, required_scope_tags JSON NOT NULL, requirement_ids JSON NOT NULL, default_percentage INT NOT NULL, active TINYINT NOT NULL, INDEX idx_ccr_framework (framework_code), INDEX idx_ccr_active (active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compliance_certificate (id INT AUTO_INCREMENT NOT NULL, framework_code VARCHAR(100) NOT NULL, cert_body VARCHAR(255) NOT NULL, cert_number VARCHAR(100) DEFAULT NULL, scope_text LONGTEXT DEFAULT NULL, scope_tags JSON NOT NULL, class VARCHAR(100) DEFAULT NULL, issue_date DATE DEFAULT NULL, valid_until DATE DEFAULT NULL, holder VARCHAR(255) DEFAULT NULL, status VARCHAR(50) NOT NULL, extraction_source VARCHAR(50) NOT NULL, extraction_confidence DOUBLE PRECISION DEFAULT NULL, created_at DATETIME NOT NULL, tenant_id INT NOT NULL, certificate_document_id INT DEFAULT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_2BB65348E43BDBE0 (certificate_document_id), INDEX IDX_2BB65348A2B28FE8 (uploaded_by_id), INDEX idx_cert_tenant (tenant_id), INDEX idx_cert_framework (framework_code), INDEX idx_cert_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE compliance_fulfillment_evidence_documents (compliance_requirement_fulfillment_id INT NOT NULL, document_id INT NOT NULL, INDEX IDX_C7DD5A8B747A67FE (compliance_requirement_fulfillment_id), INDEX IDX_C7DD5A8BC33F7837 (document_id), PRIMARY KEY (compliance_requirement_fulfillment_id, document_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE compliance_certificate ADD CONSTRAINT FK_2BB653489033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->addSql('ALTER TABLE compliance_certificate ADD CONSTRAINT FK_2BB65348E43BDBE0 FOREIGN KEY (certificate_document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE compliance_certificate ADD CONSTRAINT FK_2BB65348A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE compliance_fulfillment_evidence_documents ADD CONSTRAINT FK_C7DD5A8B747A67FE FOREIGN KEY (compliance_requirement_fulfillment_id) REFERENCES compliance_requirement_fulfillment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compliance_fulfillment_evidence_documents ADD CONSTRAINT FK_C7DD5A8BC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD verified_at DATETIME DEFAULT NULL, ADD verified_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment ADD CONSTRAINT FK_308AE24269F4B775 FOREIGN KEY (verified_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_308AE24269F4B775 ON compliance_requirement_fulfillment (verified_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compliance_certificate DROP FOREIGN KEY FK_2BB653489033212A');
        $this->addSql('ALTER TABLE compliance_certificate DROP FOREIGN KEY FK_2BB65348E43BDBE0');
        $this->addSql('ALTER TABLE compliance_certificate DROP FOREIGN KEY FK_2BB65348A2B28FE8');
        $this->addSql('ALTER TABLE compliance_fulfillment_evidence_documents DROP FOREIGN KEY FK_C7DD5A8B747A67FE');
        $this->addSql('ALTER TABLE compliance_fulfillment_evidence_documents DROP FOREIGN KEY FK_C7DD5A8BC33F7837');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP FOREIGN KEY FK_308AE24269F4B775');
        $this->addSql('DROP INDEX IDX_308AE24269F4B775 ON compliance_requirement_fulfillment');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP verified_at, DROP verified_by_id');
        $this->addSql('DROP TABLE certificate_coverage_rule');
        $this->addSql('DROP TABLE compliance_certificate');
        $this->addSql('DROP TABLE compliance_fulfillment_evidence_documents');
    }
}
