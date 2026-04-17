<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WS-1 (DATA_REUSE_IMPROVEMENT_PLAN.md v1.1): Mapping-based inheritance suggestions with mandatory review.
 *
 * Changes:
 * - compliance_mapping: source, version, valid_from, valid_until (MAJOR-2 versioning)
 * - New table: fulfillment_inheritance_log (review queue + audit trail for inheritance)
 * - New table: four_eyes_approval_request (generic 4-eyes for multiple workstreams)
 */
final class Version20260417210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WS-1: ComplianceMapping versioning + FulfillmentInheritanceLog + FourEyesApprovalRequest';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE compliance_mapping
            ADD source VARCHAR(100) DEFAULT 'algorithm_generated_v1.0' NOT NULL,
            ADD version INT DEFAULT 1 NOT NULL,
            ADD valid_from DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            ADD valid_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        $this->addSql('UPDATE compliance_mapping SET valid_from = created_at WHERE valid_from IS NULL');

        $this->addSql("ALTER TABLE compliance_mapping
            MODIFY valid_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE INDEX idx_cm_source ON compliance_mapping (source)');
        $this->addSql('CREATE INDEX idx_cm_valid ON compliance_mapping (valid_from, valid_until)');

        $this->addSql("CREATE TABLE fulfillment_inheritance_log (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            fulfillment_id INT NOT NULL,
            derived_from_mapping_id INT NOT NULL,
            reviewed_by_id INT DEFAULT NULL,
            four_eyes_approved_by_id INT DEFAULT NULL,
            overridden_by_id INT DEFAULT NULL,
            mapping_version_used INT DEFAULT 1 NOT NULL,
            suggested_percentage INT DEFAULT 0 NOT NULL,
            review_status VARCHAR(30) DEFAULT 'pending_review' NOT NULL,
            reviewed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            review_comment LONGTEXT DEFAULT NULL,
            four_eyes_approved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            overridden_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            override_reason LONGTEXT DEFAULT NULL,
            override_value INT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_fil_fulfillment (fulfillment_id),
            INDEX idx_fil_status (review_status),
            INDEX idx_fil_tenant_status (tenant_id, review_status),
            INDEX idx_fil_tenant (tenant_id),
            INDEX idx_fil_mapping (derived_from_mapping_id),
            INDEX idx_fil_reviewer (reviewed_by_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4");

        $this->addSql('ALTER TABLE fulfillment_inheritance_log
            ADD CONSTRAINT fk_fil_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log
            ADD CONSTRAINT fk_fil_fulfillment FOREIGN KEY (fulfillment_id) REFERENCES compliance_requirement_fulfillment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log
            ADD CONSTRAINT fk_fil_mapping FOREIGN KEY (derived_from_mapping_id) REFERENCES compliance_mapping (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log
            ADD CONSTRAINT fk_fil_reviewed_by FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log
            ADD CONSTRAINT fk_fil_feyes_by FOREIGN KEY (four_eyes_approved_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log
            ADD CONSTRAINT fk_fil_overridden_by FOREIGN KEY (overridden_by_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql("CREATE TABLE four_eyes_approval_request (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            requested_by_id INT NOT NULL,
            requested_approver_id INT DEFAULT NULL,
            approved_by_id INT DEFAULT NULL,
            action_type VARCHAR(50) NOT NULL,
            payload JSON NOT NULL,
            status VARCHAR(20) DEFAULT 'pending' NOT NULL,
            approved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            rejection_reason LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_feyes_status (status),
            INDEX idx_feyes_tenant_status (tenant_id, status),
            INDEX idx_feyes_approver (requested_approver_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4");

        $this->addSql('ALTER TABLE four_eyes_approval_request
            ADD CONSTRAINT fk_feyes_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE four_eyes_approval_request
            ADD CONSTRAINT fk_feyes_requested_by FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE four_eyes_approval_request
            ADD CONSTRAINT fk_feyes_approver FOREIGN KEY (requested_approver_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE four_eyes_approval_request
            ADD CONSTRAINT fk_feyes_approved_by FOREIGN KEY (approved_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE four_eyes_approval_request DROP FOREIGN KEY fk_feyes_approved_by');
        $this->addSql('ALTER TABLE four_eyes_approval_request DROP FOREIGN KEY fk_feyes_approver');
        $this->addSql('ALTER TABLE four_eyes_approval_request DROP FOREIGN KEY fk_feyes_requested_by');
        $this->addSql('ALTER TABLE four_eyes_approval_request DROP FOREIGN KEY fk_feyes_tenant');
        $this->addSql('DROP TABLE four_eyes_approval_request');

        $this->addSql('ALTER TABLE fulfillment_inheritance_log DROP FOREIGN KEY fk_fil_overridden_by');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log DROP FOREIGN KEY fk_fil_feyes_by');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log DROP FOREIGN KEY fk_fil_reviewed_by');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log DROP FOREIGN KEY fk_fil_mapping');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log DROP FOREIGN KEY fk_fil_fulfillment');
        $this->addSql('ALTER TABLE fulfillment_inheritance_log DROP FOREIGN KEY fk_fil_tenant');
        $this->addSql('DROP TABLE fulfillment_inheritance_log');

        $this->addSql('DROP INDEX idx_cm_valid ON compliance_mapping');
        $this->addSql('DROP INDEX idx_cm_source ON compliance_mapping');
        $this->addSql('ALTER TABLE compliance_mapping DROP source, DROP version, DROP valid_from, DROP valid_until');
    }
}
