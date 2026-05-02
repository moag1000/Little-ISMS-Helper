<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tri-State NotBlank-Cleanup + Index-Naming-Konsolidierung.
 *
 * Echte Schema-Aenderungen:
 *  - asset.owner / business_continuity_plan.plan_owner / incident.reported_by /
 *    training.trainer: NOT NULL → DEFAULT NULL (Tri-State semantik — Legacy
 *    string ist nicht mehr required, wenn *User oder *Person gesetzt sind).
 *  - identity_provider.created_at/updated_at + sso_user_approval.requested_at/
 *    reviewed_at: Drift-Korrektur (DC2Type-Comment-Drift).
 *  - corrective_actions.responsible_person_id: FK-Constraint umbenannt
 *    FK_CA_USER → FK_673EF8CEEF64F467 (Standard-Doctrine-Hash).
 *  - Restliche ~80 Statements: kosmetische Index-Umbennenungen — bringt DB
 *    auf Doctrine-Standard-Index-Namen. Kein Verhalten geaendert.
 */
final class Version20260502114544 extends AbstractMigration
{
    /**
     * Disable per-migration transaction wrapping. Diese Migration enthaelt
     * 100+ ALTER TABLE Statements; MySQL DDL committet implizit, was die
     * Doctrine-SAVEPOINT bricht. Ohne diesen Override fail die Migration
     * mit "There is no active transaction" beim ersten Index-Rename nach
     * dem ersten ALTER. Siehe CLAUDE.md Pitfall #6.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Tri-State NotBlank-Cleanup (4 Spalten nullable) + Doctrine-Standard-Index-Namen + minor drift fixes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE asset CHANGE owner owner VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE asset RENAME INDEX idx_asset_owner_person TO IDX_2AF5A5C2300C8F4');
        $this->addSql('ALTER TABLE asset_owner_deputy RENAME INDEX idx_asset_deputy_asset TO IDX_F457873C5DA1941');
        $this->addSql('ALTER TABLE asset_owner_deputy RENAME INDEX idx_asset_deputy_person TO IDX_F457873C217BBB47');
        $this->addSql('ALTER TABLE audit_findings RENAME INDEX fk_af_reported_by_person TO IDX_840710685F7F07C6');
        $this->addSql('ALTER TABLE audit_findings RENAME INDEX fk_af_assigned_person TO IDX_8407106858DA0EE5');
        $this->addSql('ALTER TABLE audit_finding_reported_by_deputies RENAME INDEX fk_af_rbd_person TO IDX_3CF4E7EA217BBB47');
        $this->addSql('ALTER TABLE audit_finding_assigned_deputies RENAME INDEX fk_af_ad_person TO IDX_EE7E86CA217BBB47');
        $this->addSql('ALTER TABLE business_continuity_plan CHANGE plan_owner plan_owner VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_continuity_plan RENAME INDEX idx_bc_plan_owner_person TO IDX_BC771CE6C36AFF8A');
        $this->addSql('ALTER TABLE bc_plan_owner_deputy RENAME INDEX idx_bc_plan_dep_plan TO IDX_DEEF3EEDAA853DD5');
        $this->addSql('ALTER TABLE bc_plan_owner_deputy RENAME INDEX idx_bc_plan_dep_person TO IDX_DEEF3EED217BBB47');
        $this->addSql('ALTER TABLE business_process RENAME INDEX idx_bp_owner_person TO IDX_DB2EA3DBEDBC70A3');
        $this->addSql('ALTER TABLE business_process_owner_deputy RENAME INDEX idx_bp_deputy_bp TO IDX_E5013E2ABE61FDDF');
        $this->addSql('ALTER TABLE business_process_owner_deputy RENAME INDEX idx_bp_deputy_person TO IDX_E5013E2A217BBB47');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment RENAME INDEX idx_crf_responsible_person TO IDX_308AE242DCC340AE');
        $this->addSql('ALTER TABLE crf_responsible_deputy RENAME INDEX idx_crf_dep_fulfillment TO IDX_FF842EFE6EA0B6CA');
        $this->addSql('ALTER TABLE crf_responsible_deputy RENAME INDEX idx_crf_dep_person TO IDX_FF842EFE217BBB47');
        $this->addSql('ALTER TABLE control RENAME INDEX idx_control_resp_person TO IDX_EDDB2C4B3E8AC76D');
        $this->addSql('ALTER TABLE control_evidence RENAME INDEX idx_control_evidence_control TO IDX_D3A1A75032BEC70E');
        $this->addSql('ALTER TABLE control_evidence RENAME INDEX idx_control_evidence_document TO IDX_D3A1A750C33F7837');
        $this->addSql('ALTER TABLE control_responsible_deputy RENAME INDEX idx_ctrl_deputy_ctrl TO IDX_1E85EB2A32BEC70E');
        $this->addSql('ALTER TABLE control_responsible_deputy RENAME INDEX idx_ctrl_deputy_person TO IDX_1E85EB2A217BBB47');
        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY `FK_CA_USER`');
        $this->addSql('ALTER TABLE corrective_actions ADD CONSTRAINT FK_673EF8CEEF64F467 FOREIGN KEY (responsible_person_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE corrective_actions RENAME INDEX idx_ca_responsible_person TO IDX_673EF8CEDCC340AE');
        $this->addSql('ALTER TABLE ca_responsible_deputy RENAME INDEX idx_ca_dep_action TO IDX_587A713068713F04');
        $this->addSql('ALTER TABLE ca_responsible_deputy RENAME INDEX idx_ca_dep_person TO IDX_587A7130217BBB47');
        $this->addSql('ALTER TABLE crisis_teams RENAME INDEX fk_ct_team_leader_person TO IDX_D9975D4EF2B5B0C2');
        $this->addSql('ALTER TABLE crisis_teams RENAME INDEX fk_ct_deputy_leader_person TO IDX_D9975D4E35C631C2');
        $this->addSql('ALTER TABLE crisis_team_leader_deputies RENAME INDEX fk_ct_ld_person TO IDX_340E969E217BBB47');
        $this->addSql('ALTER TABLE crisis_team_deputy_leader_deputies RENAME INDEX fk_ct_dld_person TO IDX_78557D19217BBB47');
        $this->addSql('ALTER TABLE custom_report RENAME INDEX fk_cr_owner_person TO IDX_F082A3802300C8F4');
        $this->addSql('ALTER TABLE custom_report_owner_deputies RENAME INDEX fk_cr_od_person TO IDX_9213D04A217BBB47');
        $this->addSql('ALTER TABLE data_breach RENAME INDEX idx_breach_dpo_person TO IDX_5F46FA6CF7E5E51B');
        $this->addSql('ALTER TABLE data_breach RENAME INDEX idx_breach_assessor_person TO IDX_5F46FA6C3D6E343B');
        $this->addSql('ALTER TABLE data_breach_dpo_deputy RENAME INDEX idx_breach_dpo_dep_breach TO IDX_912E9DEC56601AB9');
        $this->addSql('ALTER TABLE data_breach_dpo_deputy RENAME INDEX idx_breach_dpo_dep_person TO IDX_912E9DEC217BBB47');
        $this->addSql('ALTER TABLE data_breach_assessor_deputy RENAME INDEX idx_breach_assr_dep_breach TO IDX_D8E311256601AB9');
        $this->addSql('ALTER TABLE data_breach_assessor_deputy RENAME INDEX idx_breach_assr_dep_person TO IDX_D8E3112217BBB47');
        $this->addSql('ALTER TABLE data_protection_impact_assessment RENAME INDEX idx_dpia_dpo_person TO IDX_1ECB684CF7E5E51B');
        $this->addSql('ALTER TABLE data_protection_impact_assessment RENAME INDEX idx_dpia_conductor_person TO IDX_1ECB684C101865FA');
        $this->addSql('ALTER TABLE data_protection_impact_assessment RENAME INDEX idx_dpia_approver_person TO IDX_1ECB684C2300BD2B');
        $this->addSql('ALTER TABLE dpia_dpo_deputy RENAME INDEX idx_dpia_dpo_dep_dpia TO IDX_49E8CFBB670F2331');
        $this->addSql('ALTER TABLE dpia_dpo_deputy RENAME INDEX idx_dpia_dpo_dep_person TO IDX_49E8CFBB217BBB47');
        $this->addSql('ALTER TABLE dpia_conductor_deputy RENAME INDEX idx_dpia_cond_dep_dpia TO IDX_EA913982670F2331');
        $this->addSql('ALTER TABLE dpia_conductor_deputy RENAME INDEX idx_dpia_cond_dep_person TO IDX_EA913982217BBB47');
        $this->addSql('ALTER TABLE dpia_approver_deputy RENAME INDEX idx_dpia_appr_dep_dpia TO IDX_54BA1AB5670F2331');
        $this->addSql('ALTER TABLE dpia_approver_deputy RENAME INDEX idx_dpia_appr_dep_person TO IDX_54BA1AB5217BBB47');
        $this->addSql('ALTER TABLE data_subject_request RENAME INDEX fk_dsr_assigned_person TO IDX_EBA4CA2A58DA0EE5');
        $this->addSql('ALTER TABLE dsr_assigned_deputies RENAME INDEX fk_dsr_ad_person TO IDX_2AB66F9C217BBB47');
        $this->addSql('ALTER TABLE four_eyes_approval_request RENAME INDEX fk_fer_approver_person TO IDX_49AA0CD6F85ACD72');
        $this->addSql('ALTER TABLE four_eyes_approver_deputies RENAME INDEX fk_fer_ad_person TO IDX_260C0E7A217BBB47');
        $this->addSql('ALTER TABLE identity_provider CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE identity_provider RENAME INDEX idx_idp_tenant TO IDX_D12F2F559033212A');
        $this->addSql('ALTER TABLE incident CHANGE reported_by reported_by VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE incident RENAME INDEX idx_incident_reporter_person TO IDX_3D03A11A5F7F07C6');
        $this->addSql('ALTER TABLE incident_reporter_deputy RENAME INDEX idx_incident_dep_incident TO IDX_504F17CE59E53FB9');
        $this->addSql('ALTER TABLE incident_reporter_deputy RENAME INDEX idx_incident_dep_person TO IDX_504F17CE217BBB47');
        $this->addSql('ALTER TABLE management_review RENAME INDEX fk_mr_reviewed_by_person TO IDX_4F5A850CD2D4E194');
        $this->addSql('ALTER TABLE management_review_reviewed_by_deputies RENAME INDEX fk_mr_rbd_person TO IDX_13F79893217BBB47');
        $this->addSql('ALTER TABLE processing_activity RENAME INDEX idx_pa_contact_person TO IDX_CBC21BBA58A85279');
        $this->addSql('ALTER TABLE processing_activity RENAME INDEX idx_pa_dpo_person TO IDX_CBC21BBAF7E5E51B');
        $this->addSql('ALTER TABLE processing_activity_contact_deputy RENAME INDEX idx_pa_cont_dep_pa TO IDX_F439578572D4D63B');
        $this->addSql('ALTER TABLE processing_activity_contact_deputy RENAME INDEX idx_pa_cont_dep_person TO IDX_F4395785217BBB47');
        $this->addSql('ALTER TABLE processing_activity_dpo_deputy RENAME INDEX idx_pa_dpo_dep_pa TO IDX_3E97321372D4D63B');
        $this->addSql('ALTER TABLE processing_activity_dpo_deputy RENAME INDEX idx_pa_dpo_dep_person TO IDX_3E973213217BBB47');
        $this->addSql('ALTER TABLE prototype_protection_assessment RENAME INDEX fk_ppa_assessor_person TO IDX_B67315303D6E343B');
        $this->addSql('ALTER TABLE ppa_assessor_deputies RENAME INDEX fk_ppa_ad_person TO IDX_68F591FA217BBB47');
        $this->addSql('ALTER TABLE risk RENAME INDEX idx_risk_owner_person TO IDX_7906D541E8F7DFAB');
        $this->addSql('ALTER TABLE risk_owner_deputy RENAME INDEX idx_risk_deputy_risk TO IDX_91B64BAB235B6D1');
        $this->addSql('ALTER TABLE risk_owner_deputy RENAME INDEX idx_risk_deputy_person TO IDX_91B64BAB217BBB47');
        $this->addSql('ALTER TABLE risk_treatment_plan RENAME INDEX idx_rtp_responsible_person TO IDX_883D44DCDCC340AE');
        $this->addSql('ALTER TABLE rtp_responsible_deputy RENAME INDEX idx_rtp_dep_plan TO IDX_D38333492EAFCFFC');
        $this->addSql('ALTER TABLE rtp_responsible_deputy RENAME INDEX idx_rtp_dep_person TO IDX_D3833349217BBB47');
        $this->addSql('ALTER TABLE risk_treatment_plan_evidence RENAME INDEX idx_rtp_evidence_rtp TO IDX_46123E6D2EAFCFFC');
        $this->addSql('ALTER TABLE risk_treatment_plan_evidence RENAME INDEX idx_rtp_evidence_document TO IDX_46123E6DC33F7837');
        $this->addSql('ALTER TABLE sso_user_approval CHANGE requested_at requested_at DATETIME NOT NULL, CHANGE reviewed_at reviewed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE sso_user_approval RENAME INDEX idx_ssoa_tenant TO IDX_965FAD1D9033212A');
        $this->addSql('ALTER TABLE sso_user_approval RENAME INDEX idx_ssoa_reviewed_by TO IDX_965FAD1DFC6B21F1');
        $this->addSql('ALTER TABLE threat_intelligence RENAME INDEX fk_ti_assigned_person TO IDX_C2556D7F58DA0EE5');
        $this->addSql('ALTER TABLE threat_intelligence_assigned_deputies RENAME INDEX fk_ti_ad_person TO IDX_D1DE53E4217BBB47');
        $this->addSql('ALTER TABLE training CHANGE trainer trainer VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE training RENAME INDEX fk_training_trainer_person TO IDX_D5128A8F321C34F1');
        $this->addSql('ALTER TABLE training_trainer_deputies RENAME INDEX fk_training_td_person TO IDX_1A695691217BBB47');
        $this->addSql('ALTER TABLE users RENAME INDEX idx_users_sso_provider TO IDX_1483A5E95044F6FC');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE asset CHANGE owner owner VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE asset RENAME INDEX idx_2af5a5c2300c8f4 TO idx_asset_owner_person');
        $this->addSql('ALTER TABLE asset_owner_deputy RENAME INDEX idx_f457873c5da1941 TO idx_asset_deputy_asset');
        $this->addSql('ALTER TABLE asset_owner_deputy RENAME INDEX idx_f457873c217bbb47 TO idx_asset_deputy_person');
        $this->addSql('ALTER TABLE audit_findings RENAME INDEX idx_8407106858da0ee5 TO fk_af_assigned_person');
        $this->addSql('ALTER TABLE audit_findings RENAME INDEX idx_840710685f7f07c6 TO fk_af_reported_by_person');
        $this->addSql('ALTER TABLE audit_finding_assigned_deputies RENAME INDEX idx_ee7e86ca217bbb47 TO fk_af_ad_person');
        $this->addSql('ALTER TABLE audit_finding_reported_by_deputies RENAME INDEX idx_3cf4e7ea217bbb47 TO fk_af_rbd_person');
        $this->addSql('ALTER TABLE bc_plan_owner_deputy RENAME INDEX idx_deef3eedaa853dd5 TO idx_bc_plan_dep_plan');
        $this->addSql('ALTER TABLE bc_plan_owner_deputy RENAME INDEX idx_deef3eed217bbb47 TO idx_bc_plan_dep_person');
        $this->addSql('ALTER TABLE business_continuity_plan CHANGE plan_owner plan_owner VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE business_continuity_plan RENAME INDEX idx_bc771ce6c36aff8a TO idx_bc_plan_owner_person');
        $this->addSql('ALTER TABLE business_process RENAME INDEX idx_db2ea3dbedbc70a3 TO idx_bp_owner_person');
        $this->addSql('ALTER TABLE business_process_owner_deputy RENAME INDEX idx_e5013e2a217bbb47 TO idx_bp_deputy_person');
        $this->addSql('ALTER TABLE business_process_owner_deputy RENAME INDEX idx_e5013e2abe61fddf TO idx_bp_deputy_bp');
        $this->addSql('ALTER TABLE ca_responsible_deputy RENAME INDEX idx_587a713068713f04 TO idx_ca_dep_action');
        $this->addSql('ALTER TABLE ca_responsible_deputy RENAME INDEX idx_587a7130217bbb47 TO idx_ca_dep_person');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment RENAME INDEX idx_308ae242dcc340ae TO idx_crf_responsible_person');
        $this->addSql('ALTER TABLE control RENAME INDEX idx_eddb2c4b3e8ac76d TO idx_control_resp_person');
        $this->addSql('ALTER TABLE control_evidence RENAME INDEX idx_d3a1a750c33f7837 TO IDX_control_evidence_document');
        $this->addSql('ALTER TABLE control_evidence RENAME INDEX idx_d3a1a75032bec70e TO IDX_control_evidence_control');
        $this->addSql('ALTER TABLE control_responsible_deputy RENAME INDEX idx_1e85eb2a217bbb47 TO idx_ctrl_deputy_person');
        $this->addSql('ALTER TABLE control_responsible_deputy RENAME INDEX idx_1e85eb2a32bec70e TO idx_ctrl_deputy_ctrl');
        $this->addSql('ALTER TABLE corrective_actions DROP FOREIGN KEY FK_673EF8CEEF64F467');
        $this->addSql('ALTER TABLE corrective_actions ADD CONSTRAINT `FK_CA_USER` FOREIGN KEY (responsible_person_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE corrective_actions RENAME INDEX idx_673ef8cedcc340ae TO idx_ca_responsible_person');
        $this->addSql('ALTER TABLE crf_responsible_deputy RENAME INDEX idx_ff842efe6ea0b6ca TO idx_crf_dep_fulfillment');
        $this->addSql('ALTER TABLE crf_responsible_deputy RENAME INDEX idx_ff842efe217bbb47 TO idx_crf_dep_person');
        $this->addSql('ALTER TABLE crisis_teams RENAME INDEX idx_d9975d4ef2b5b0c2 TO fk_ct_team_leader_person');
        $this->addSql('ALTER TABLE crisis_teams RENAME INDEX idx_d9975d4e35c631c2 TO fk_ct_deputy_leader_person');
        $this->addSql('ALTER TABLE crisis_team_deputy_leader_deputies RENAME INDEX idx_78557d19217bbb47 TO fk_ct_dld_person');
        $this->addSql('ALTER TABLE crisis_team_leader_deputies RENAME INDEX idx_340e969e217bbb47 TO fk_ct_ld_person');
        $this->addSql('ALTER TABLE custom_report RENAME INDEX idx_f082a3802300c8f4 TO fk_cr_owner_person');
        $this->addSql('ALTER TABLE custom_report_owner_deputies RENAME INDEX idx_9213d04a217bbb47 TO fk_cr_od_person');
        $this->addSql('ALTER TABLE data_breach RENAME INDEX idx_5f46fa6c3d6e343b TO idx_breach_assessor_person');
        $this->addSql('ALTER TABLE data_breach RENAME INDEX idx_5f46fa6cf7e5e51b TO idx_breach_dpo_person');
        $this->addSql('ALTER TABLE data_breach_assessor_deputy RENAME INDEX idx_d8e311256601ab9 TO idx_breach_assr_dep_breach');
        $this->addSql('ALTER TABLE data_breach_assessor_deputy RENAME INDEX idx_d8e3112217bbb47 TO idx_breach_assr_dep_person');
        $this->addSql('ALTER TABLE data_breach_dpo_deputy RENAME INDEX idx_912e9dec217bbb47 TO idx_breach_dpo_dep_person');
        $this->addSql('ALTER TABLE data_breach_dpo_deputy RENAME INDEX idx_912e9dec56601ab9 TO idx_breach_dpo_dep_breach');
        $this->addSql('ALTER TABLE data_protection_impact_assessment RENAME INDEX idx_1ecb684c2300bd2b TO idx_dpia_approver_person');
        $this->addSql('ALTER TABLE data_protection_impact_assessment RENAME INDEX idx_1ecb684cf7e5e51b TO idx_dpia_dpo_person');
        $this->addSql('ALTER TABLE data_protection_impact_assessment RENAME INDEX idx_1ecb684c101865fa TO idx_dpia_conductor_person');
        $this->addSql('ALTER TABLE data_subject_request RENAME INDEX idx_eba4ca2a58da0ee5 TO fk_dsr_assigned_person');
        $this->addSql('ALTER TABLE dpia_approver_deputy RENAME INDEX idx_54ba1ab5670f2331 TO idx_dpia_appr_dep_dpia');
        $this->addSql('ALTER TABLE dpia_approver_deputy RENAME INDEX idx_54ba1ab5217bbb47 TO idx_dpia_appr_dep_person');
        $this->addSql('ALTER TABLE dpia_conductor_deputy RENAME INDEX idx_ea913982217bbb47 TO idx_dpia_cond_dep_person');
        $this->addSql('ALTER TABLE dpia_conductor_deputy RENAME INDEX idx_ea913982670f2331 TO idx_dpia_cond_dep_dpia');
        $this->addSql('ALTER TABLE dpia_dpo_deputy RENAME INDEX idx_49e8cfbb670f2331 TO idx_dpia_dpo_dep_dpia');
        $this->addSql('ALTER TABLE dpia_dpo_deputy RENAME INDEX idx_49e8cfbb217bbb47 TO idx_dpia_dpo_dep_person');
        $this->addSql('ALTER TABLE dsr_assigned_deputies RENAME INDEX idx_2ab66f9c217bbb47 TO fk_dsr_ad_person');
        $this->addSql('ALTER TABLE four_eyes_approval_request RENAME INDEX idx_49aa0cd6f85acd72 TO fk_fer_approver_person');
        $this->addSql('ALTER TABLE four_eyes_approver_deputies RENAME INDEX idx_260c0e7a217bbb47 TO fk_fer_ad_person');
        $this->addSql('ALTER TABLE identity_provider CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE identity_provider RENAME INDEX idx_d12f2f559033212a TO idx_idp_tenant');
        $this->addSql('ALTER TABLE incident CHANGE reported_by reported_by VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE incident RENAME INDEX idx_3d03a11a5f7f07c6 TO idx_incident_reporter_person');
        $this->addSql('ALTER TABLE incident_reporter_deputy RENAME INDEX idx_504f17ce59e53fb9 TO idx_incident_dep_incident');
        $this->addSql('ALTER TABLE incident_reporter_deputy RENAME INDEX idx_504f17ce217bbb47 TO idx_incident_dep_person');
        $this->addSql('ALTER TABLE management_review RENAME INDEX idx_4f5a850cd2d4e194 TO fk_mr_reviewed_by_person');
        $this->addSql('ALTER TABLE management_review_reviewed_by_deputies RENAME INDEX idx_13f79893217bbb47 TO fk_mr_rbd_person');
        $this->addSql('ALTER TABLE ppa_assessor_deputies RENAME INDEX idx_68f591fa217bbb47 TO fk_ppa_ad_person');
        $this->addSql('ALTER TABLE processing_activity RENAME INDEX idx_cbc21bba58a85279 TO idx_pa_contact_person');
        $this->addSql('ALTER TABLE processing_activity RENAME INDEX idx_cbc21bbaf7e5e51b TO idx_pa_dpo_person');
        $this->addSql('ALTER TABLE processing_activity_contact_deputy RENAME INDEX idx_f439578572d4d63b TO idx_pa_cont_dep_pa');
        $this->addSql('ALTER TABLE processing_activity_contact_deputy RENAME INDEX idx_f4395785217bbb47 TO idx_pa_cont_dep_person');
        $this->addSql('ALTER TABLE processing_activity_dpo_deputy RENAME INDEX idx_3e97321372d4d63b TO idx_pa_dpo_dep_pa');
        $this->addSql('ALTER TABLE processing_activity_dpo_deputy RENAME INDEX idx_3e973213217bbb47 TO idx_pa_dpo_dep_person');
        $this->addSql('ALTER TABLE prototype_protection_assessment RENAME INDEX idx_b67315303d6e343b TO fk_ppa_assessor_person');
        $this->addSql('ALTER TABLE risk RENAME INDEX idx_7906d541e8f7dfab TO idx_risk_owner_person');
        $this->addSql('ALTER TABLE risk_owner_deputy RENAME INDEX idx_91b64bab235b6d1 TO idx_risk_deputy_risk');
        $this->addSql('ALTER TABLE risk_owner_deputy RENAME INDEX idx_91b64bab217bbb47 TO idx_risk_deputy_person');
        $this->addSql('ALTER TABLE risk_treatment_plan RENAME INDEX idx_883d44dcdcc340ae TO idx_rtp_responsible_person');
        $this->addSql('ALTER TABLE risk_treatment_plan_evidence RENAME INDEX idx_46123e6d2eafcffc TO IDX_rtp_evidence_rtp');
        $this->addSql('ALTER TABLE risk_treatment_plan_evidence RENAME INDEX idx_46123e6dc33f7837 TO IDX_rtp_evidence_document');
        $this->addSql('ALTER TABLE rtp_responsible_deputy RENAME INDEX idx_d38333492eafcffc TO idx_rtp_dep_plan');
        $this->addSql('ALTER TABLE rtp_responsible_deputy RENAME INDEX idx_d3833349217bbb47 TO idx_rtp_dep_person');
        $this->addSql('ALTER TABLE sso_user_approval CHANGE requested_at requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE reviewed_at reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE sso_user_approval RENAME INDEX idx_965fad1d9033212a TO idx_ssoa_tenant');
        $this->addSql('ALTER TABLE sso_user_approval RENAME INDEX idx_965fad1dfc6b21f1 TO idx_ssoa_reviewed_by');
        $this->addSql('ALTER TABLE threat_intelligence RENAME INDEX idx_c2556d7f58da0ee5 TO fk_ti_assigned_person');
        $this->addSql('ALTER TABLE threat_intelligence_assigned_deputies RENAME INDEX idx_d1de53e4217bbb47 TO fk_ti_ad_person');
        $this->addSql('ALTER TABLE training CHANGE trainer trainer VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE training RENAME INDEX idx_d5128a8f321c34f1 TO fk_training_trainer_person');
        $this->addSql('ALTER TABLE training_trainer_deputies RENAME INDEX idx_1a695691217bbb47 TO fk_training_td_person');
        $this->addSql('ALTER TABLE users RENAME INDEX idx_1483a5e95044f6fc TO idx_users_sso_provider');
    }
}
