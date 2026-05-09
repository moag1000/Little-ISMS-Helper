<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T31 Sprint-2 Norm-Fields Migration (FormType-Norm-Gating-Rollout, 2026-05-07)
 *
 * - Incident: 16 fields (4 ISO 27001 A.5.24-A.5.28 + 12 DORA Art. 17-19)
 * - BusinessContinuityPlan: responseTeamMembers + escalationLevels JSON + crisisTeams M2M
 * - CrisisTeam: activationCount + escalationMatrix JSON
 * - BCExercise: actualRtoAchieved + actualRpoAchieved + evidenceArtifacts JSON
 * - ManagementReview: 7 fields (topManagementAttended, nextReviewDate,
 *   riskTreatmentEffectiveness, policyReviewOutcome, frameworkComplianceStatus,
 *   actionItemsWithDeadlines, meetingMinutesDocument FK)
 * - 2 new M2M tables (bc_plan_crisis_team, incident_critical_services)
 *
 * isTransactional()=false required: ALTER+CREATE TABLE in MySQL implicit-commit
 * (per CLAUDE.md memory feedback_migration_savepoint).
 */
final class Version20260507233715 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'T31 Sprint-2 norm-fields: Incident DORA + BCM JSON-strukturiert + ManagementReview ISO §9.3';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bc_plan_crisis_team (business_continuity_plan_id INT NOT NULL, crisis_team_id INT NOT NULL, INDEX IDX_1287FE4EE338D05C (business_continuity_plan_id), INDEX IDX_1287FE4EF54E4E81 (crisis_team_id), PRIMARY KEY (business_continuity_plan_id, crisis_team_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE incident_critical_services (incident_id INT NOT NULL, business_process_id INT NOT NULL, INDEX IDX_BB620C7959E53FB9 (incident_id), INDEX IDX_BB620C79BE61FDDF (business_process_id), PRIMARY KEY (incident_id, business_process_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bc_plan_crisis_team ADD CONSTRAINT FK_1287FE4EE338D05C FOREIGN KEY (business_continuity_plan_id) REFERENCES business_continuity_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bc_plan_crisis_team ADD CONSTRAINT FK_1287FE4EF54E4E81 FOREIGN KEY (crisis_team_id) REFERENCES crisis_teams (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_critical_services ADD CONSTRAINT FK_BB620C7959E53FB9 FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_critical_services ADD CONSTRAINT FK_BB620C79BE61FDDF FOREIGN KEY (business_process_id) REFERENCES business_process (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bc_exercise ADD actual_rto_achieved NUMERIC(10, 2) DEFAULT NULL, ADD actual_rpo_achieved NUMERIC(10, 2) DEFAULT NULL, ADD evidence_artifacts JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE business_continuity_plan ADD response_team_members JSON DEFAULT NULL, ADD escalation_levels JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE crisis_teams ADD activation_count INT DEFAULT 0 NOT NULL, ADD escalation_matrix JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE incident ADD incident_classification VARCHAR(50) DEFAULT NULL, ADD containment_actions LONGTEXT DEFAULT NULL, ADD evidence_preserved TINYINT DEFAULT 0 NOT NULL, ADD evidence_artifacts_json JSON DEFAULT NULL, ADD ict_incident_classification VARCHAR(50) DEFAULT NULL, ADD data_loss_occurred TINYINT DEFAULT 0 NOT NULL, ADD data_leakage_occurred TINYINT DEFAULT 0 NOT NULL, ADD economic_impact NUMERIC(15, 2) DEFAULT NULL, ADD reputational_impact SMALLINT DEFAULT NULL, ADD recurring_incident TINYINT DEFAULT 0 NOT NULL, ADD clients_affected INT DEFAULT NULL, ADD clients_affected_financial_volume NUMERIC(15, 2) DEFAULT NULL, ADD replication_of_impact TINYINT DEFAULT 0 NOT NULL, ADD initial_report_submitted_at DATETIME DEFAULT NULL, ADD intermediate_report_submitted_at DATETIME DEFAULT NULL, ADD data_recovery_strategy LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE management_review ADD top_management_attended TINYINT DEFAULT 0 NOT NULL, ADD next_review_date DATE DEFAULT NULL, ADD risk_treatment_effectiveness LONGTEXT DEFAULT NULL, ADD policy_review_outcome LONGTEXT DEFAULT NULL, ADD framework_compliance_status JSON DEFAULT NULL, ADD action_items_with_deadlines JSON DEFAULT NULL, ADD meeting_minutes_document_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE management_review ADD CONSTRAINT FK_4F5A850CD3E4B9D7 FOREIGN KEY (meeting_minutes_document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_4F5A850CD3E4B9D7 ON management_review (meeting_minutes_document_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bc_plan_crisis_team DROP FOREIGN KEY FK_1287FE4EE338D05C');
        $this->addSql('ALTER TABLE bc_plan_crisis_team DROP FOREIGN KEY FK_1287FE4EF54E4E81');
        $this->addSql('ALTER TABLE incident_critical_services DROP FOREIGN KEY FK_BB620C7959E53FB9');
        $this->addSql('ALTER TABLE incident_critical_services DROP FOREIGN KEY FK_BB620C79BE61FDDF');
        $this->addSql('DROP TABLE bc_plan_crisis_team');
        $this->addSql('DROP TABLE incident_critical_services');
        $this->addSql('ALTER TABLE bc_exercise DROP actual_rto_achieved, DROP actual_rpo_achieved, DROP evidence_artifacts');
        $this->addSql('ALTER TABLE business_continuity_plan DROP response_team_members, DROP escalation_levels');
        $this->addSql('ALTER TABLE crisis_teams DROP activation_count, DROP escalation_matrix');
        $this->addSql('ALTER TABLE incident DROP incident_classification, DROP containment_actions, DROP evidence_preserved, DROP evidence_artifacts_json, DROP ict_incident_classification, DROP data_loss_occurred, DROP data_leakage_occurred, DROP economic_impact, DROP reputational_impact, DROP recurring_incident, DROP clients_affected, DROP clients_affected_financial_volume, DROP replication_of_impact, DROP initial_report_submitted_at, DROP intermediate_report_submitted_at, DROP data_recovery_strategy');
        $this->addSql('ALTER TABLE management_review DROP FOREIGN KEY FK_4F5A850CD3E4B9D7');
        $this->addSql('DROP INDEX IDX_4F5A850CD3E4B9D7 ON management_review');
        $this->addSql('ALTER TABLE management_review DROP top_management_attended, DROP next_review_date, DROP risk_treatment_effectiveness, DROP policy_review_outcome, DROP framework_compliance_status, DROP action_items_with_deadlines, DROP meeting_minutes_document_id');
    }
}
