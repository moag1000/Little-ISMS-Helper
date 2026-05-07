<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T31 Sprint-1 Norm-Fields Migration (FormType-Norm-Gating-Rollout, 2026-05-07)
 *
 * Adds GDPR/ISO 27001 audit-critical fields per 6 specialist reviews:
 *   - Risk: likelihoodJustification, impactJustification, decisionRationale,
 *     decisionApprovedByUser, decisionApprovalDate (ISO 27001 6.1.2.d + ISO 31000)
 *   - Consent: withdrawnAt, withdrawalReason, withdrawalChannel (GDPR Art. 7(3))
 *   - DataSubjectRequest: responseAt, responseDocument, responseMethod (GDPR Art. 12(3))
 *
 * Plus absorbed parallel-agent schema-drift (supplier index, tenant comments).
 *
 * isTransactional()=false required: contains ALTER TABLE which implicitly
 * commits in MySQL (per CLAUDE.md memory feedback_migration_savepoint).
 */
final class Version20260507201904 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'T31 Sprint-1 norm-fields: Risk justifikation+approval, Consent withdrawal, DSR frist-tracking';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE consent ADD withdrawn_at DATETIME DEFAULT NULL, ADD withdrawal_reason LONGTEXT DEFAULT NULL, ADD withdrawal_channel VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE data_subject_request ADD response_at DATETIME DEFAULT NULL, ADD response_document VARCHAR(255) DEFAULT NULL, ADD response_method VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD likelihood_justification LONGTEXT DEFAULT NULL, ADD impact_justification LONGTEXT DEFAULT NULL, ADD decision_rationale LONGTEXT DEFAULT NULL, ADD decision_approval_date DATETIME DEFAULT NULL, ADD decision_approved_by_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD CONSTRAINT FK_7906D541B21A2D4D FOREIGN KEY (decision_approved_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7906D541B21A2D4D ON risk (decision_approved_by_user_id)');
        $this->addSql('DROP INDEX idx_supplier_lksg_obligation ON supplier');
        $this->addSql('DROP INDEX idx_supplier_lksg_category ON supplier');
        $this->addSql('ALTER TABLE supplier CHANGE lksg_reporting_obligation lksg_reporting_obligation TINYINT NOT NULL');
        $this->addSql('ALTER TABLE tenant CHANGE data_retention_policies data_retention_policies JSON DEFAULT NULL COMMENT \'GDPR retention policies keyed by data category (e.g. crm, contracts, audit_evidence)\', CHANGE wizard_maturity_target wizard_maturity_target VARCHAR(32) DEFAULT \'baseline\' COMMENT \'Default maturity target across wizards: baseline|enhanced\', CHANGE notification_preferences notification_preferences JSON DEFAULT NULL COMMENT \'Notification preferences keyed by event type (incident, breach, audit_finding, training_overdue)\', CHANGE csirt_endpoints csirt_endpoints JSON DEFAULT NULL COMMENT \'CSIRT endpoints for automated incident reporting (BSI, sectoral CSIRT, ENISA)\', CHANGE crisis_team_on_call crisis_team_on_call JSON DEFAULT NULL COMMENT \'Crisis team on-call rotation entries (each: date, primary, deputy)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE consent DROP withdrawn_at, DROP withdrawal_reason, DROP withdrawal_channel');
        $this->addSql('ALTER TABLE data_subject_request DROP response_at, DROP response_document, DROP response_method');
        $this->addSql('ALTER TABLE risk DROP FOREIGN KEY FK_7906D541B21A2D4D');
        $this->addSql('DROP INDEX IDX_7906D541B21A2D4D ON risk');
        $this->addSql('ALTER TABLE risk DROP likelihood_justification, DROP impact_justification, DROP decision_rationale, DROP decision_approval_date, DROP decision_approved_by_user_id');
        $this->addSql('ALTER TABLE supplier CHANGE lksg_reporting_obligation lksg_reporting_obligation TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_supplier_lksg_obligation ON supplier (lksg_reporting_obligation)');
        $this->addSql('CREATE INDEX idx_supplier_lksg_category ON supplier (lksg_risk_category)');
        $this->addSql('ALTER TABLE tenant CHANGE data_retention_policies data_retention_policies JSON DEFAULT NULL COMMENT \'GDPR retention policies keyed by data category\', CHANGE wizard_maturity_target wizard_maturity_target VARCHAR(32) DEFAULT \'baseline\' COMMENT \'baseline|enhanced\', CHANGE notification_preferences notification_preferences JSON DEFAULT NULL COMMENT \'Notification preferences keyed by event type\', CHANGE csirt_endpoints csirt_endpoints JSON DEFAULT NULL COMMENT \'CSIRT endpoints for automated incident reporting\', CHANGE crisis_team_on_call crisis_team_on_call JSON DEFAULT NULL COMMENT \'Crisis team on-call rotation entries\'');
    }
}
