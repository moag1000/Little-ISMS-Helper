<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tri-State NotBlank-Cleanup + Index-Naming-Konsolidierung.
 *
 * Echte Schema-Aenderungen (idempotent / critical):
 *  - asset.owner / business_continuity_plan.plan_owner / incident.reported_by /
 *    training.trainer: NOT NULL → DEFAULT NULL (Tri-State semantik — Legacy
 *    string ist nicht mehr required, wenn *User oder *Person gesetzt sind).
 *  - identity_provider.created_at/updated_at + sso_user_approval.requested_at/
 *    reviewed_at: Drift-Korrektur (DC2Type-Comment-Drift).
 *  - corrective_actions.responsible_person_id: FK-Constraint umbenannt
 *    FK_CA_USER → FK_673EF8CEEF64F467 (Standard-Doctrine-Hash).
 *  - Restliche ~80 Statements: kosmetische Index-Umbennenungen — bringt DB
 *    auf Doctrine-Standard-Index-Namen. Kein Verhalten geaendert.
 *
 * Index-Renames laufen defensiv per Connection->executeStatement() mit
 * try-catch; eine fehlende Quelle (Phantom-Index aus früherer fehlerhaft
 * applizierter Migration) wird übersprungen statt das Migrationsfile zu
 * killen. So bleibt die Migration auf inkonsistenten DBs anwendbar.
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
        // 1) Echte Schema-Aenderungen — kritisch, müssen immer laufen.
        $this->addSql('ALTER TABLE asset CHANGE owner owner VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_continuity_plan CHANGE plan_owner plan_owner VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE incident CHANGE reported_by reported_by VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE training CHANGE trainer trainer VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE identity_provider CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE sso_user_approval CHANGE requested_at requested_at DATETIME NOT NULL, CHANGE reviewed_at reviewed_at DATETIME DEFAULT NULL');

        // 2) FK-Rename (corrective_actions): defensiv per Connection mit try-catch,
        // damit Re-Run nicht crashed wenn FK_CA_USER schon abgehängt ist.
        $this->safeFkRename(
            'corrective_actions',
            'FK_CA_USER',
            'FK_673EF8CEEF64F467',
            'FOREIGN KEY (responsible_person_id) REFERENCES users (id) ON DELETE SET NULL',
        );

        // 3) Index-Renames: pro Tripel try-catch, damit Phantom-Indexes
        // aus inkonsistenter DB die Migration nicht killen.
        foreach (self::INDEX_RENAMES_UP as [$table, $oldIndex, $newIndex]) {
            $this->safeRenameIndex($table, $oldIndex, $newIndex);
        }
    }

    public function down(Schema $schema): void
    {
        // Echte Schema-Aenderungen reversed.
        $this->addSql('ALTER TABLE asset CHANGE owner owner VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE business_continuity_plan CHANGE plan_owner plan_owner VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE incident CHANGE reported_by reported_by VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE training CHANGE trainer trainer VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE identity_provider CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE sso_user_approval CHANGE requested_at requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE reviewed_at reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        $this->safeFkRename(
            'corrective_actions',
            'FK_673EF8CEEF64F467',
            'FK_CA_USER',
            'FOREIGN KEY (responsible_person_id) REFERENCES users (id)',
        );

        foreach (self::INDEX_RENAMES_UP as [$table, $oldIndex, $newIndex]) {
            // Reverse direction.
            $this->safeRenameIndex($table, $newIndex, $oldIndex);
        }
    }

    /**
     * Renames a MySQL index defensively. Failure (index missing or already
     * renamed) is swallowed — kosmetic, not blocking.
     */
    private function safeRenameIndex(string $table, string $oldIndex, string $newIndex): void
    {
        try {
            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s RENAME INDEX %s TO %s',
                $table,
                $oldIndex,
                $newIndex,
            ));
        } catch (\Throwable $e) {
            // Index missing on this DB — nothing to do. Logged for trail.
            $this->write(sprintf(
                "  -- skipped RENAME INDEX %s.%s -> %s (%s)",
                $table,
                $oldIndex,
                $newIndex,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Drops + re-adds an FK constraint defensively. Skips DROP when the
     * old FK doesn't exist; ADD always runs (re-run protected by MySQL
     * "duplicate constraint name" — caught and skipped).
     */
    private function safeFkRename(string $table, string $oldFk, string $newFk, string $newFkSpec): void
    {
        try {
            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s DROP FOREIGN KEY `%s`',
                $table,
                $oldFk,
            ));
        } catch (\Throwable $e) {
            $this->write(sprintf("  -- skipped DROP FK %s.%s (%s)", $table, $oldFk, $e->getMessage()));
        }
        try {
            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s %s',
                $table,
                $newFk,
                $newFkSpec,
            ));
        } catch (\Throwable $e) {
            $this->write(sprintf("  -- skipped ADD FK %s.%s (%s)", $table, $newFk, $e->getMessage()));
        }
    }

    /**
     * @var list<array{0:string, 1:string, 2:string}>
     */
    private const INDEX_RENAMES_UP = [
        ['asset', 'idx_asset_owner_person', 'IDX_2AF5A5C2300C8F4'],
        ['asset_owner_deputy', 'idx_asset_deputy_asset', 'IDX_F457873C5DA1941'],
        ['asset_owner_deputy', 'idx_asset_deputy_person', 'IDX_F457873C217BBB47'],
        ['audit_findings', 'fk_af_reported_by_person', 'IDX_840710685F7F07C6'],
        ['audit_findings', 'fk_af_assigned_person', 'IDX_8407106858DA0EE5'],
        ['audit_finding_reported_by_deputies', 'fk_af_rbd_person', 'IDX_3CF4E7EA217BBB47'],
        ['audit_finding_assigned_deputies', 'fk_af_ad_person', 'IDX_EE7E86CA217BBB47'],
        ['business_continuity_plan', 'idx_bc_plan_owner_person', 'IDX_BC771CE6C36AFF8A'],
        ['bc_plan_owner_deputy', 'idx_bc_plan_dep_plan', 'IDX_DEEF3EEDAA853DD5'],
        ['bc_plan_owner_deputy', 'idx_bc_plan_dep_person', 'IDX_DEEF3EED217BBB47'],
        ['business_process', 'idx_bp_owner_person', 'IDX_DB2EA3DBEDBC70A3'],
        ['business_process_owner_deputy', 'idx_bp_deputy_bp', 'IDX_E5013E2ABE61FDDF'],
        ['business_process_owner_deputy', 'idx_bp_deputy_person', 'IDX_E5013E2A217BBB47'],
        ['compliance_requirement_fulfillment', 'idx_crf_responsible_person', 'IDX_308AE242DCC340AE'],
        ['crf_responsible_deputy', 'idx_crf_dep_fulfillment', 'IDX_FF842EFE6EA0B6CA'],
        ['crf_responsible_deputy', 'idx_crf_dep_person', 'IDX_FF842EFE217BBB47'],
        ['control', 'idx_control_resp_person', 'IDX_EDDB2C4B3E8AC76D'],
        ['control_evidence', 'idx_control_evidence_control', 'IDX_D3A1A75032BEC70E'],
        ['control_evidence', 'idx_control_evidence_document', 'IDX_D3A1A750C33F7837'],
        ['control_responsible_deputy', 'idx_ctrl_deputy_ctrl', 'IDX_1E85EB2A32BEC70E'],
        ['control_responsible_deputy', 'idx_ctrl_deputy_person', 'IDX_1E85EB2A217BBB47'],
        ['corrective_actions', 'idx_ca_responsible_person', 'IDX_673EF8CEDCC340AE'],
        ['ca_responsible_deputy', 'idx_ca_dep_action', 'IDX_587A713068713F04'],
        ['ca_responsible_deputy', 'idx_ca_dep_person', 'IDX_587A7130217BBB47'],
        ['crisis_teams', 'fk_ct_team_leader_person', 'IDX_D9975D4EF2B5B0C2'],
        ['crisis_teams', 'fk_ct_deputy_leader_person', 'IDX_D9975D4E35C631C2'],
        ['crisis_team_leader_deputies', 'fk_ct_ld_person', 'IDX_340E969E217BBB47'],
        ['crisis_team_deputy_leader_deputies', 'fk_ct_dld_person', 'IDX_78557D19217BBB47'],
        ['custom_report', 'fk_cr_owner_person', 'IDX_F082A3802300C8F4'],
        ['custom_report_owner_deputies', 'fk_cr_od_person', 'IDX_9213D04A217BBB47'],
        ['data_breach', 'idx_breach_dpo_person', 'IDX_5F46FA6CF7E5E51B'],
        ['data_breach', 'idx_breach_assessor_person', 'IDX_5F46FA6C3D6E343B'],
        ['data_breach_dpo_deputy', 'idx_breach_dpo_dep_breach', 'IDX_912E9DEC56601AB9'],
        ['data_breach_dpo_deputy', 'idx_breach_dpo_dep_person', 'IDX_912E9DEC217BBB47'],
        ['data_breach_assessor_deputy', 'idx_breach_assr_dep_breach', 'IDX_D8E311256601AB9'],
        ['data_breach_assessor_deputy', 'idx_breach_assr_dep_person', 'IDX_D8E3112217BBB47'],
        ['data_protection_impact_assessment', 'idx_dpia_dpo_person', 'IDX_1ECB684CF7E5E51B'],
        ['data_protection_impact_assessment', 'idx_dpia_conductor_person', 'IDX_1ECB684C101865FA'],
        ['data_protection_impact_assessment', 'idx_dpia_approver_person', 'IDX_1ECB684C2300BD2B'],
        ['dpia_dpo_deputy', 'idx_dpia_dpo_dep_dpia', 'IDX_49E8CFBB670F2331'],
        ['dpia_dpo_deputy', 'idx_dpia_dpo_dep_person', 'IDX_49E8CFBB217BBB47'],
        ['dpia_conductor_deputy', 'idx_dpia_cond_dep_dpia', 'IDX_EA913982670F2331'],
        ['dpia_conductor_deputy', 'idx_dpia_cond_dep_person', 'IDX_EA913982217BBB47'],
        ['dpia_approver_deputy', 'idx_dpia_appr_dep_dpia', 'IDX_54BA1AB5670F2331'],
        ['dpia_approver_deputy', 'idx_dpia_appr_dep_person', 'IDX_54BA1AB5217BBB47'],
        ['data_subject_request', 'fk_dsr_assigned_person', 'IDX_EBA4CA2A58DA0EE5'],
        ['dsr_assigned_deputies', 'fk_dsr_ad_person', 'IDX_2AB66F9C217BBB47'],
        ['four_eyes_approval_request', 'fk_fer_approver_person', 'IDX_49AA0CD6F85ACD72'],
        ['four_eyes_approver_deputies', 'fk_fer_ad_person', 'IDX_260C0E7A217BBB47'],
        ['identity_provider', 'idx_idp_tenant', 'IDX_D12F2F559033212A'],
        ['incident', 'idx_incident_reporter_person', 'IDX_3D03A11A5F7F07C6'],
        ['incident_reporter_deputy', 'idx_incident_dep_incident', 'IDX_504F17CE59E53FB9'],
        ['incident_reporter_deputy', 'idx_incident_dep_person', 'IDX_504F17CE217BBB47'],
        ['management_review', 'fk_mr_reviewed_by_person', 'IDX_4F5A850CD2D4E194'],
        ['management_review_reviewed_by_deputies', 'fk_mr_rbd_person', 'IDX_13F79893217BBB47'],
        ['processing_activity', 'idx_pa_contact_person', 'IDX_CBC21BBA58A85279'],
        ['processing_activity', 'idx_pa_dpo_person', 'IDX_CBC21BBAF7E5E51B'],
        ['processing_activity_contact_deputy', 'idx_pa_cont_dep_pa', 'IDX_F439578572D4D63B'],
        ['processing_activity_contact_deputy', 'idx_pa_cont_dep_person', 'IDX_F4395785217BBB47'],
        ['processing_activity_dpo_deputy', 'idx_pa_dpo_dep_pa', 'IDX_3E97321372D4D63B'],
        ['processing_activity_dpo_deputy', 'idx_pa_dpo_dep_person', 'IDX_3E973213217BBB47'],
        ['prototype_protection_assessment', 'fk_ppa_assessor_person', 'IDX_B67315303D6E343B'],
        ['ppa_assessor_deputies', 'fk_ppa_ad_person', 'IDX_68F591FA217BBB47'],
        ['risk', 'idx_risk_owner_person', 'IDX_7906D541E8F7DFAB'],
        ['risk_owner_deputy', 'idx_risk_deputy_risk', 'IDX_91B64BAB235B6D1'],
        ['risk_owner_deputy', 'idx_risk_deputy_person', 'IDX_91B64BAB217BBB47'],
        ['risk_treatment_plan', 'idx_rtp_responsible_person', 'IDX_883D44DCDCC340AE'],
        ['rtp_responsible_deputy', 'idx_rtp_dep_plan', 'IDX_D38333492EAFCFFC'],
        ['rtp_responsible_deputy', 'idx_rtp_dep_person', 'IDX_D3833349217BBB47'],
        ['risk_treatment_plan_evidence', 'idx_rtp_evidence_rtp', 'IDX_46123E6D2EAFCFFC'],
        ['risk_treatment_plan_evidence', 'idx_rtp_evidence_document', 'IDX_46123E6DC33F7837'],
        ['sso_user_approval', 'idx_ssoa_tenant', 'IDX_965FAD1D9033212A'],
        ['sso_user_approval', 'idx_ssoa_reviewed_by', 'IDX_965FAD1DFC6B21F1'],
        ['threat_intelligence', 'fk_ti_assigned_person', 'IDX_C2556D7F58DA0EE5'],
        ['threat_intelligence_assigned_deputies', 'fk_ti_ad_person', 'IDX_D1DE53E4217BBB47'],
        ['training', 'fk_training_trainer_person', 'IDX_D5128A8F321C34F1'],
        ['training_trainer_deputies', 'fk_training_td_person', 'IDX_1A695691217BBB47'],
        ['users', 'idx_users_sso_provider', 'IDX_1483A5E95044F6FC'],
    ];
}
