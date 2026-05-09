<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T31 Sprint 6-7: Control effectiveness/cloud + RiskType DORA-ICT + Supplier MaRisk
 *
 * Control (11 fields): effectiveness, controlType, automationLevel, controlMaturity,
 *   lastEffectivenessTest, nextEffectivenessTest, frameworkReferences (JSON),
 *   cloud_security gated: cloudControlReference, cloudPrivacyReference, pimsReference,
 *   customerOrProviderResponsibility
 *
 * Risk (8 fields + 2 join tables): ictRiskCategory, criticalOrImportantFunction,
 *   ictThirdPartyConcentration, dataResilienceRequirement, tlptScope,
 *   regulatoryReportingRequired, boardEscalationRequired, lessonsLearnedDocumented,
 *   + risk_ict_asset_dependency + risk_ict_incident_history join tables
 *
 * Supplier (10 fields): MaRisk AT 9 outsourcing classification/due-diligence/exit-strategy,
 *   AT 9.7 BaFin notification, AT 4.1 risk-bearing-capacity, AT 4.3/4.4 board/compliance/audit
 */
final class Version20260508000553 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'T31 Sprint 6-7: Control effectiveness+cloud, Risk DORA-ICT subset, Supplier MaRisk outsourcing fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE risk_ict_asset_dependency (risk_id INT NOT NULL, asset_id INT NOT NULL, INDEX IDX_B4E8B705235B6D1 (risk_id), INDEX IDX_B4E8B7055DA1941 (asset_id), PRIMARY KEY (risk_id, asset_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE risk_ict_incident_history (risk_id INT NOT NULL, incident_id INT NOT NULL, INDEX IDX_DD216BCC235B6D1 (risk_id), INDEX IDX_DD216BCC59E53FB9 (incident_id), PRIMARY KEY (risk_id, incident_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE risk_ict_asset_dependency ADD CONSTRAINT FK_B4E8B705235B6D1 FOREIGN KEY (risk_id) REFERENCES risk (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk_ict_asset_dependency ADD CONSTRAINT FK_B4E8B7055DA1941 FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk_ict_incident_history ADD CONSTRAINT FK_DD216BCC235B6D1 FOREIGN KEY (risk_id) REFERENCES risk (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE risk_ict_incident_history ADD CONSTRAINT FK_DD216BCC59E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE control ADD effectiveness VARCHAR(50) DEFAULT NULL, ADD control_type VARCHAR(50) DEFAULT NULL, ADD automation_level VARCHAR(50) DEFAULT NULL, ADD control_maturity SMALLINT DEFAULT NULL, ADD last_effectiveness_test DATE DEFAULT NULL, ADD next_effectiveness_test DATE DEFAULT NULL, ADD framework_references JSON DEFAULT NULL, ADD cloud_control_reference VARCHAR(255) DEFAULT NULL, ADD cloud_privacy_reference VARCHAR(255) DEFAULT NULL, ADD pims_reference VARCHAR(255) DEFAULT NULL, ADD customer_or_provider_responsibility VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE risk ADD ict_risk_category VARCHAR(50) DEFAULT NULL, ADD critical_or_important_function TINYINT DEFAULT 0 NOT NULL, ADD ict_third_party_concentration TINYINT DEFAULT 0 NOT NULL, ADD data_resilience_requirement LONGTEXT DEFAULT NULL, ADD tlpt_scope TINYINT DEFAULT 0 NOT NULL, ADD regulatory_reporting_required TINYINT DEFAULT 0 NOT NULL, ADD board_escalation_required TINYINT DEFAULT 0 NOT NULL, ADD lessons_learned_documented TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE supplier ADD outsourcing_classification VARCHAR(50) DEFAULT NULL, ADD outsourcing_due_diligence_completed TINYINT DEFAULT 0 NOT NULL, ADD outsourcing_due_diligence_date DATE DEFAULT NULL, ADD outsourcing_exit_strategy LONGTEXT DEFAULT NULL, ADD bafin_notification_required TINYINT DEFAULT 0 NOT NULL, ADD bafin_notification_date DATE DEFAULT NULL, ADD risk_bearing_capacity_impact LONGTEXT DEFAULT NULL, ADD board_level_risk_acceptance TINYINT DEFAULT 0 NOT NULL, ADD compliance_function_involvement TINYINT DEFAULT 0 NOT NULL, ADD internal_audit_function_involvement TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE risk_ict_asset_dependency DROP FOREIGN KEY FK_B4E8B705235B6D1');
        $this->addSql('ALTER TABLE risk_ict_asset_dependency DROP FOREIGN KEY FK_B4E8B7055DA1941');
        $this->addSql('ALTER TABLE risk_ict_incident_history DROP FOREIGN KEY FK_DD216BCC235B6D1');
        $this->addSql('ALTER TABLE risk_ict_incident_history DROP FOREIGN KEY FK_DD216BCC59E53FB9');
        $this->addSql('DROP TABLE risk_ict_asset_dependency');
        $this->addSql('DROP TABLE risk_ict_incident_history');
        $this->addSql('ALTER TABLE control DROP effectiveness, DROP control_type, DROP automation_level, DROP control_maturity, DROP last_effectiveness_test, DROP next_effectiveness_test, DROP framework_references, DROP cloud_control_reference, DROP cloud_privacy_reference, DROP pims_reference, DROP customer_or_provider_responsibility');
        $this->addSql('ALTER TABLE risk DROP ict_risk_category, DROP critical_or_important_function, DROP ict_third_party_concentration, DROP data_resilience_requirement, DROP tlpt_scope, DROP regulatory_reporting_required, DROP board_escalation_required, DROP lessons_learned_documented');
        $this->addSql('ALTER TABLE supplier DROP outsourcing_classification, DROP outsourcing_due_diligence_completed, DROP outsourcing_due_diligence_date, DROP outsourcing_exit_strategy, DROP bafin_notification_required, DROP bafin_notification_date, DROP risk_bearing_capacity_impact, DROP board_level_risk_acceptance, DROP compliance_function_involvement, DROP internal_audit_function_involvement');
    }
}
