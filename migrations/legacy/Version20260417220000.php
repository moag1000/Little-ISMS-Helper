<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WS-3 (DATA_REUSE_IMPROVEMENT_PLAN.md v1.1): Supplier DORA + GDPR Art. 28 fields.
 *
 * Adds the fields necessary for DORA Register of Information (ITS on ROI, 2024)
 * and GDPR Art. 28 processor relationships, without duplicating the supplier
 * across frameworks.
 */
final class Version20260417220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WS-3: Supplier DORA ROI fields + GDPR Art. 28 processor metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE supplier
            ADD lei_code VARCHAR(20) DEFAULT NULL,
            ADD ict_criticality VARCHAR(20) DEFAULT NULL,
            ADD ict_function_type VARCHAR(100) DEFAULT NULL,
            ADD substitutability VARCHAR(20) DEFAULT NULL,
            ADD has_subcontractors TINYINT(1) DEFAULT 0 NOT NULL,
            ADD subcontractor_chain JSON DEFAULT NULL,
            ADD processing_locations JSON DEFAULT NULL,
            ADD last_dora_audit_date DATE DEFAULT NULL,
            ADD has_exit_strategy TINYINT(1) DEFAULT 0 NOT NULL,
            ADD exit_strategy_document_id INT DEFAULT NULL,
            ADD gdpr_processor_status VARCHAR(30) DEFAULT NULL,
            ADD gdpr_transfer_mechanism VARCHAR(50) DEFAULT NULL,
            ADD gdpr_av_contract_signed TINYINT(1) DEFAULT 0 NOT NULL,
            ADD gdpr_av_contract_date DATE DEFAULT NULL");

        $this->addSql('CREATE INDEX idx_supplier_ict_criticality ON supplier (ict_criticality)');
        $this->addSql('CREATE INDEX idx_supplier_gdpr_processor_status ON supplier (gdpr_processor_status)');

        $this->addSql('ALTER TABLE supplier
            ADD CONSTRAINT fk_supplier_exit_strategy_document FOREIGN KEY (exit_strategy_document_id)
            REFERENCES document (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier DROP FOREIGN KEY fk_supplier_exit_strategy_document');
        $this->addSql('DROP INDEX idx_supplier_gdpr_processor_status ON supplier');
        $this->addSql('DROP INDEX idx_supplier_ict_criticality ON supplier');
        $this->addSql("ALTER TABLE supplier
            DROP lei_code,
            DROP ict_criticality,
            DROP ict_function_type,
            DROP substitutability,
            DROP has_subcontractors,
            DROP subcontractor_chain,
            DROP processing_locations,
            DROP last_dora_audit_date,
            DROP has_exit_strategy,
            DROP exit_strategy_document_id,
            DROP gdpr_processor_status,
            DROP gdpr_transfer_mechanism,
            DROP gdpr_av_contract_signed,
            DROP gdpr_av_contract_date");
    }
}
