<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drift-Consolidation: bring DB schema in sync with entity-metadata.
 *
 * Background: Prior migrations (some using PREPARE/EXECUTE pattern that silently
 * skipped DDL — CLAUDE.md pitfall #6) left 74 pending schema-update statements
 * after running on a fresh DB. This migration consolidates all remaining drift.
 *
 * Categories resolved:
 *  - 1 CREATE TABLE  (audit_finding_controls join table)
 *  - 1 DROP TABLE    (lifecycle_config — orphaned, entity removed)
 *  - 10 DROP INDEX   (stale custom indexes Doctrine no longer generates)
 *  - 32 RENAME INDEX (Doctrine regenerated internal index names)
 *  - 13 ALTER COLUMN (type/default/nullability drift)
 *  - FK renames/re-adds (old named FKs → Doctrine-generated names)
 *
 * All operations are idempotent:
 *  - Uses information_schema checks for FK existence (reliable across MariaDB versions)
 *  - Uses hasForeignKey/hasTable/hasIndex from Schema for structural checks
 *  - FOREIGN KEY IF NOT EXISTS (MariaDB 10.5+ syntax) for FK additions
 *
 * `isTransactional() = false` required — MySQL/MariaDB implicitly commits ALTER
 * TABLE / CREATE TABLE, invalidating Doctrine's per-migration SAVEPOINT (CLAUDE.md #6).
 */
final class Version20260525000000_drift_consolidation extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Consolidate 74-statement entity-vs-DB drift (stale indexes, FK renames, column type drift, orphaned lifecycle_config).';
    }

    /**
     * Check if a FK exists directly via information_schema (reliable, avoids
     * Doctrine introspection cache inconsistencies with MariaDB FK naming).
     */
    private function fkExists(string $table, string $constraintName): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table, $constraintName]
        );
    }

    /**
     * Check if an index exists directly via information_schema.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?",
            [$table, $indexName]
        );
    }

    public function up(Schema $schema): void
    {
        // =========================================================
        // 1. CREATE TABLE — audit_finding_controls join table
        //    (Version20260524100000 may have created this already)
        // =========================================================
        if (!$schema->hasTable('audit_finding_controls')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE audit_finding_controls (
                    audit_finding_id INT NOT NULL,
                    control_id INT NOT NULL,
                    INDEX IDX_A39BFCA23EFDAD18 (audit_finding_id),
                    INDEX IDX_A39BFCA232BEC70E (control_id),
                    PRIMARY KEY (audit_finding_id, control_id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
            $this->addSql('ALTER TABLE audit_finding_controls ADD CONSTRAINT FK_A39BFCA23EFDAD18 FOREIGN KEY IF NOT EXISTS (audit_finding_id) REFERENCES audit_findings (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE audit_finding_controls ADD CONSTRAINT FK_A39BFCA232BEC70E FOREIGN KEY IF NOT EXISTS (control_id) REFERENCES control (id) ON DELETE CASCADE');
        }

        // =========================================================
        // 2. DROP TABLE — lifecycle_config (entity removed, orphaned)
        // =========================================================
        if ($schema->hasTable('lifecycle_config')) {
            foreach ($schema->getTable('lifecycle_config')->getForeignKeys() as $fk) {
                $this->addSql(sprintf('ALTER TABLE lifecycle_config DROP FOREIGN KEY `%s`', $fk->getName()));
            }
            $this->addSql('DROP TABLE lifecycle_config');
        }

        // =========================================================
        // 3. ALTER COLUMN — tenant_branding watermark opacity defaults
        // =========================================================
        if ($schema->hasTable('tenant_branding')) {
            $this->addSql('ALTER TABLE tenant_branding CHANGE policy_doc_watermark_opacity policy_doc_watermark_opacity DOUBLE PRECISION DEFAULT 0.08 NOT NULL, CHANGE report_doc_watermark_opacity report_doc_watermark_opacity DOUBLE PRECISION DEFAULT 0.08 NOT NULL');
        }

        // =========================================================
        // 4. document_version — column type + index rename + FK re-add
        //    Sequence: drop self-FK (blocks id CHANGE) → CHANGE columns
        //    → rename indexes → re-add FK.
        // =========================================================
        if ($schema->hasTable('document_version')) {
            // Drop self-referencing FK if still present (blocks id column CHANGE)
            if ($this->fkExists('document_version', 'fk_docver_replaced_by')) {
                $this->addSql('ALTER TABLE document_version DROP FOREIGN KEY `fk_docver_replaced_by`');
            }
            $this->addSql('ALTER TABLE document_version CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE version_number version_number INT UNSIGNED NOT NULL, CHANGE file_size file_size INT UNSIGNED NOT NULL, CHANGE uploaded_at uploaded_at DATETIME NOT NULL, CHANGE published_at published_at DATETIME DEFAULT NULL, CHANGE retention_until retention_until DATETIME DEFAULT NULL, CHANGE replaced_by_id replaced_by_id INT DEFAULT NULL');
            if ($this->indexExists('document_version', 'fk_docver_uploaded_by')) {
                $this->addSql('ALTER TABLE document_version RENAME INDEX fk_docver_uploaded_by TO IDX_1B73751FA2B28FE8');
            }
            if ($this->indexExists('document_version', 'fk_docver_replaced_by')) {
                $this->addSql('ALTER TABLE document_version RENAME INDEX fk_docver_replaced_by TO IDX_1B73751F9AC69B54');
            }
            // Re-add self-FK with Doctrine name
            $this->addSql('ALTER TABLE document_version ADD CONSTRAINT FK_1B73751F9AC69B54 FOREIGN KEY IF NOT EXISTS (replaced_by_id) REFERENCES document_version (id) ON DELETE SET NULL');
        }

        // =========================================================
        // 5. document — drop old FK, CHANGE column, rename index, re-add FK
        //    Requires document_version.id to be plain INT (done in section 4)
        // =========================================================
        if ($schema->hasTable('document')) {
            if ($this->fkExists('document', 'fk_doc_current_version')) {
                $this->addSql('ALTER TABLE document DROP FOREIGN KEY `fk_doc_current_version`');
            }
            $this->addSql('ALTER TABLE document CHANGE current_version_id current_version_id INT DEFAULT NULL');
            if ($this->indexExists('document', 'fk_doc_current_version')) {
                $this->addSql('ALTER TABLE document RENAME INDEX fk_doc_current_version TO IDX_D8698A769407EE77');
            }
            $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A769407EE77 FOREIGN KEY IF NOT EXISTS (current_version_id) REFERENCES document_version (id) ON DELETE SET NULL');
        }

        // =========================================================
        // 6. DROP stale indexes — compliance_mapping
        // =========================================================
        if ($this->indexExists('compliance_mapping', 'idx_cm_source')) {
            $this->addSql('DROP INDEX idx_cm_source ON compliance_mapping');
        }
        if ($this->indexExists('compliance_mapping', 'idx_cm_valid')) {
            $this->addSql('DROP INDEX idx_cm_valid ON compliance_mapping');
        }

        // =========================================================
        // 7. DROP stale indexes — internal_audit
        // =========================================================
        if ($this->indexExists('internal_audit', 'idx_audit_parent')) {
            $this->addSql('DROP INDEX idx_audit_parent ON internal_audit');
        }
        if ($this->indexExists('internal_audit', 'idx_internal_audit_lead_auditor_user')) {
            $this->addSql('DROP INDEX idx_internal_audit_lead_auditor_user ON internal_audit');
        }
        if ($this->indexExists('internal_audit', 'idx_internal_audit_lead_auditor_person')) {
            $this->addSql('DROP INDEX idx_internal_audit_lead_auditor_person ON internal_audit');
        }

        // =========================================================
        // 8. bc_exercise — drop stale indexes + rename remaining
        // =========================================================
        if ($this->indexExists('bc_exercise', 'idx_bc_exercise_leader_user')) {
            $this->addSql('DROP INDEX idx_bc_exercise_leader_user ON bc_exercise');
        }
        if ($this->indexExists('bc_exercise', 'idx_bc_exercise_leader_person')) {
            $this->addSql('DROP INDEX idx_bc_exercise_leader_person ON bc_exercise');
        }
        if ($this->indexExists('bc_exercise', 'idx_bc_exercise_facilitator_user')) {
            $this->addSql('ALTER TABLE bc_exercise RENAME INDEX idx_bc_exercise_facilitator_user TO IDX_3FFCA5B4A387B67');
        }
        if ($this->indexExists('bc_exercise', 'idx_bc_exercise_facilitator_person')) {
            $this->addSql('ALTER TABLE bc_exercise RENAME INDEX idx_bc_exercise_facilitator_person TO IDX_3FFCA5B4DFFCBEF4');
        }

        // =========================================================
        // 9. bc_exercise_participant_person — RENAME INDEX
        // =========================================================
        if ($this->indexExists('bc_exercise_participant_person', 'idx_bcepp_exercise')) {
            $this->addSql('ALTER TABLE bc_exercise_participant_person RENAME INDEX idx_bcepp_exercise TO IDX_43DCC0D0C36D6558');
        }
        if ($this->indexExists('bc_exercise_participant_person', 'idx_bcepp_person')) {
            $this->addSql('ALTER TABLE bc_exercise_participant_person RENAME INDEX idx_bcepp_person TO IDX_43DCC0D0217BBB47');
        }

        // =========================================================
        // 10. bc_exercise_observer_person — RENAME INDEX
        // =========================================================
        if ($this->indexExists('bc_exercise_observer_person', 'idx_bceop_exercise')) {
            $this->addSql('ALTER TABLE bc_exercise_observer_person RENAME INDEX idx_bceop_exercise TO IDX_7C28E66DC36D6558');
        }
        if ($this->indexExists('bc_exercise_observer_person', 'idx_bceop_person')) {
            $this->addSql('ALTER TABLE bc_exercise_observer_person RENAME INDEX idx_bceop_person TO IDX_7C28E66D217BBB47');
        }

        // =========================================================
        // 11. processing_activity_processor_supplier — RENAME INDEX
        // =========================================================
        if ($this->indexExists('processing_activity_processor_supplier', 'idx_pa_proc_sup_pa')) {
            $this->addSql('ALTER TABLE processing_activity_processor_supplier RENAME INDEX idx_pa_proc_sup_pa TO IDX_7E2A809D72D4D63B');
        }
        if ($this->indexExists('processing_activity_processor_supplier', 'idx_pa_proc_sup_sup')) {
            $this->addSql('ALTER TABLE processing_activity_processor_supplier RENAME INDEX idx_pa_proc_sup_sup TO IDX_7E2A809D2ADD6D8C');
        }

        // =========================================================
        // 12. authority_template — CHANGE datetime columns
        // =========================================================
        if ($schema->hasTable('authority_template')) {
            $this->addSql('ALTER TABLE authority_template CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        }

        // =========================================================
        // 13. compliance_requirement_control — drop+re-add FKs with CASCADE
        // =========================================================
        if ($schema->hasTable('compliance_requirement_control')) {
            if ($this->fkExists('compliance_requirement_control', 'FK_57D957D32BEC70E')) {
                $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY `FK_57D957D32BEC70E`');
            }
            $this->addSql('ALTER TABLE compliance_requirement_control ADD CONSTRAINT FK_57D957D32BEC70E FOREIGN KEY IF NOT EXISTS (control_id) REFERENCES control (id) ON DELETE CASCADE');

            if ($this->fkExists('compliance_requirement_control', 'FK_57D957D492951C7')) {
                $this->addSql('ALTER TABLE compliance_requirement_control DROP FOREIGN KEY `FK_57D957D492951C7`');
            }
            $this->addSql('ALTER TABLE compliance_requirement_control ADD CONSTRAINT FK_57D957D492951C7 FOREIGN KEY IF NOT EXISTS (compliance_requirement_id) REFERENCES compliance_requirement (id) ON DELETE CASCADE');
        }

        // =========================================================
        // 14. bsi_2004_exercise_log — ALTER COLUMN + RENAME INDEX
        // =========================================================
        if ($schema->hasTable('bsi_2004_exercise_log')) {
            $this->addSql('ALTER TABLE bsi_2004_exercise_log CHANGE exercise_type exercise_type VARCHAR(50) NOT NULL, CHANGE bsi2004_template bsi2004_template VARCHAR(30) NOT NULL, CHANGE submitted_at submitted_at DATETIME DEFAULT NULL, CHANGE confirmed_at confirmed_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
            if ($this->indexExists('bsi_2004_exercise_log', 'uniq_bsi_log_exercise')) {
                $this->addSql('ALTER TABLE bsi_2004_exercise_log RENAME INDEX uniq_bsi_log_exercise TO UNIQ_5F58C893C36D6558');
            }
            if ($this->indexExists('bsi_2004_exercise_log', 'fk_bsi_log_submitted_by')) {
                $this->addSql('ALTER TABLE bsi_2004_exercise_log RENAME INDEX fk_bsi_log_submitted_by TO IDX_5F58C89379F7D87D');
            }
            if ($this->indexExists('bsi_2004_exercise_log', 'fk_bsi_log_confirmed_by')) {
                $this->addSql('ALTER TABLE bsi_2004_exercise_log RENAME INDEX fk_bsi_log_confirmed_by TO IDX_5F58C893457EC3C7');
            }
        }

        // =========================================================
        // 15. evidence_reverification_task — drop FKs, CHANGE column,
        //     rename indexes, re-add FKs
        //     Sequence: drop → CHANGE → rename → re-add
        // =========================================================
        if ($schema->hasTable('evidence_reverification_task')) {
            if ($this->fkExists('evidence_reverification_task', 'fk_revtask_docver')) {
                $this->addSql('ALTER TABLE evidence_reverification_task DROP FOREIGN KEY `fk_revtask_docver`');
            }
            if ($this->fkExists('evidence_reverification_task', 'fk_revtask_control')) {
                $this->addSql('ALTER TABLE evidence_reverification_task DROP FOREIGN KEY `fk_revtask_control`');
            }
            if ($this->fkExists('evidence_reverification_task', 'fk_revtask_fulfillment')) {
                $this->addSql('ALTER TABLE evidence_reverification_task DROP FOREIGN KEY `fk_revtask_fulfillment`');
            }
            if ($this->fkExists('evidence_reverification_task', 'fk_revtask_assigned')) {
                $this->addSql('ALTER TABLE evidence_reverification_task DROP FOREIGN KEY `fk_revtask_assigned`');
            }
            $this->addSql('ALTER TABLE evidence_reverification_task CHANGE document_version_id document_version_id INT NOT NULL, CHANGE due_date due_date DATETIME DEFAULT NULL, CHANGE completed_at completed_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
            if ($this->indexExists('evidence_reverification_task', 'fk_revtask_docver')) {
                $this->addSql('ALTER TABLE evidence_reverification_task RENAME INDEX fk_revtask_docver TO IDX_C4A9D6DCEA7F8C53');
            }
            if ($this->indexExists('evidence_reverification_task', 'fk_revtask_control')) {
                $this->addSql('ALTER TABLE evidence_reverification_task RENAME INDEX fk_revtask_control TO IDX_C4A9D6DC32BEC70E');
            }
            if ($this->indexExists('evidence_reverification_task', 'fk_revtask_fulfillment')) {
                $this->addSql('ALTER TABLE evidence_reverification_task RENAME INDEX fk_revtask_fulfillment TO IDX_C4A9D6DC5CDE887A');
            }
            if ($this->indexExists('evidence_reverification_task', 'fk_revtask_assigned')) {
                $this->addSql('ALTER TABLE evidence_reverification_task RENAME INDEX fk_revtask_assigned TO IDX_C4A9D6DCF4BD7827');
            }
            $this->addSql('ALTER TABLE evidence_reverification_task ADD CONSTRAINT FK_C4A9D6DCEA7F8C53 FOREIGN KEY IF NOT EXISTS (document_version_id) REFERENCES document_version (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE evidence_reverification_task ADD CONSTRAINT FK_C4A9D6DC32BEC70E FOREIGN KEY IF NOT EXISTS (control_id) REFERENCES control (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE evidence_reverification_task ADD CONSTRAINT FK_C4A9D6DC5CDE887A FOREIGN KEY IF NOT EXISTS (compliance_fulfillment_id) REFERENCES compliance_requirement_fulfillment (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE evidence_reverification_task ADD CONSTRAINT FK_C4A9D6DCF4BD7827 FOREIGN KEY IF NOT EXISTS (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        // =========================================================
        // 16. corrective_actions — CHANGE column + RENAME INDEX
        // =========================================================
        if ($schema->hasTable('corrective_actions')) {
            $this->addSql('ALTER TABLE corrective_actions CHANGE verified_at verified_at DATETIME DEFAULT NULL');
            if ($this->indexExists('corrective_actions', 'idx_ca_verified_by')) {
                $this->addSql('ALTER TABLE corrective_actions RENAME INDEX idx_ca_verified_by TO IDX_673EF8CE69F4B775');
            }
            if ($this->indexExists('corrective_actions', 'idx_ca_previous_capa')) {
                $this->addSql('ALTER TABLE corrective_actions RENAME INDEX idx_ca_previous_capa TO IDX_673EF8CE364953A1');
            }
        }

        // =========================================================
        // 17. four_eyes_approval_request — DROP stale FK (if exists)
        //     Note: may have been absent in some DB instances already.
        // =========================================================
        if ($this->fkExists('four_eyes_approval_request', 'fk_feyes_requested_approver')) {
            $this->addSql('ALTER TABLE four_eyes_approval_request DROP FOREIGN KEY `fk_feyes_requested_approver`');
        }

        // =========================================================
        // 18. supplier — CHANGE column + DROP stale indexes
        // =========================================================
        if ($schema->hasTable('supplier')) {
            $this->addSql('ALTER TABLE supplier CHANGE is_dora_relevant is_dora_relevant TINYINT NOT NULL');
            if ($this->indexExists('supplier', 'idx_supplier_ict_criticality')) {
                $this->addSql('DROP INDEX idx_supplier_ict_criticality ON supplier');
            }
            if ($this->indexExists('supplier', 'idx_supplier_gdpr_processor_status')) {
                $this->addSql('DROP INDEX idx_supplier_gdpr_processor_status ON supplier');
            }
        }

        // =========================================================
        // 19. identity_provider_user_mapping — CHANGE columns + RENAME INDEX
        // =========================================================
        if ($schema->hasTable('identity_provider_user_mapping')) {
            $this->addSql('ALTER TABLE identity_provider_user_mapping CHANGE last_synced_at last_synced_at DATETIME DEFAULT NULL, CHANGE first_logged_in_at first_logged_in_at DATETIME DEFAULT NULL');
            if ($this->indexExists('identity_provider_user_mapping', 'idx_ipum_tenant')) {
                $this->addSql('ALTER TABLE identity_provider_user_mapping RENAME INDEX idx_ipum_tenant TO IDX_90070FA49033212A');
            }
        }

        // =========================================================
        // 20. threat_led_penetration_test — DROP stale FK
        // =========================================================
        if ($this->fkExists('threat_led_penetration_test', 'FK_TLPT_TENANT')) {
            $this->addSql('ALTER TABLE threat_led_penetration_test DROP FOREIGN KEY `FK_TLPT_TENANT`');
        }

        // =========================================================
        // 21. workflow_instances — DROP stale index
        // =========================================================
        if ($this->indexExists('workflow_instances', 'idx_workflow_instances_witness_user')) {
            $this->addSql('DROP INDEX idx_workflow_instances_witness_user ON workflow_instances');
        }

        // =========================================================
        // 22. nis2_registration_profile — drop+re-add FKs with ON DELETE SET NULL
        // =========================================================
        if ($schema->hasTable('nis2_registration_profile')) {
            if ($this->fkExists('nis2_registration_profile', 'FK_6C5D7621926E2D9E')) {
                $this->addSql('ALTER TABLE nis2_registration_profile DROP FOREIGN KEY `FK_6C5D7621926E2D9E`');
                $this->addSql('ALTER TABLE nis2_registration_profile ADD CONSTRAINT FK_6C5D7621926E2D9E FOREIGN KEY IF NOT EXISTS (incident_reporting_contact_id) REFERENCES users (id) ON DELETE SET NULL');
            }
            if ($this->fkExists('nis2_registration_profile', 'FK_6C5D7621D4F8E16F')) {
                $this->addSql('ALTER TABLE nis2_registration_profile DROP FOREIGN KEY `FK_6C5D7621D4F8E16F`');
                $this->addSql('ALTER TABLE nis2_registration_profile ADD CONSTRAINT FK_6C5D7621D4F8E16F FOREIGN KEY IF NOT EXISTS (security_responsible_contact_id) REFERENCES users (id) ON DELETE SET NULL');
            }
        }

        // =========================================================
        // 23. dora_register_of_information — CHANGE columns + RENAME INDEX
        // =========================================================
        if ($schema->hasTable('dora_register_of_information')) {
            $this->addSql('ALTER TABLE dora_register_of_information CHANGE reporting_scope reporting_scope VARCHAR(30) NOT NULL, CHANGE submitted_at submitted_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
            if ($this->indexExists('dora_register_of_information', 'fk_dora_roi_submitted_by')) {
                $this->addSql('ALTER TABLE dora_register_of_information RENAME INDEX fk_dora_roi_submitted_by TO IDX_6828CFB879F7D87D');
            }
        }

        // =========================================================
        // 24. kpi_snapshot — DROP stale FK
        // =========================================================
        if ($this->fkExists('kpi_snapshot', 'FK_KPI_SNAPSHOT_TENANT')) {
            $this->addSql('ALTER TABLE kpi_snapshot DROP FOREIGN KEY `FK_KPI_SNAPSHOT_TENANT`');
        }

        // =========================================================
        // 25. audit_finding_requirement — RENAME INDEX
        // =========================================================
        if ($this->indexExists('audit_finding_requirement', 'idx_afr_finding')) {
            $this->addSql('ALTER TABLE audit_finding_requirement RENAME INDEX idx_afr_finding TO IDX_B8ADD3553EFDAD18');
        }
        if ($this->indexExists('audit_finding_requirement', 'idx_afr_requirement')) {
            $this->addSql('ALTER TABLE audit_finding_requirement RENAME INDEX idx_afr_requirement TO IDX_B8ADD355492951C7');
        }

        // =========================================================
        // 26. risk_incident_link — DROP old FK, CHANGE columns,
        //     RENAME INDEX, ADD new FK
        // =========================================================
        if ($schema->hasTable('risk_incident_link')) {
            if ($this->fkExists('risk_incident_link', 'fk_ril_linked_by')) {
                $this->addSql('ALTER TABLE risk_incident_link DROP FOREIGN KEY `fk_ril_linked_by`');
            }
            $this->addSql('ALTER TABLE risk_incident_link CHANGE link_type link_type VARCHAR(32) NOT NULL, CHANGE linked_at linked_at DATETIME NOT NULL, CHANGE notes notes LONGTEXT DEFAULT NULL');
            $this->addSql('ALTER TABLE risk_incident_link ADD CONSTRAINT FK_71DBD0041AE3CFF3 FOREIGN KEY IF NOT EXISTS (linked_by_id) REFERENCES users (id)');
            if ($this->indexExists('risk_incident_link', 'idx_risk_incident_link_risk')) {
                $this->addSql('ALTER TABLE risk_incident_link RENAME INDEX idx_risk_incident_link_risk TO IDX_71DBD004235B6D1');
            }
            if ($this->indexExists('risk_incident_link', 'idx_risk_incident_link_incident')) {
                $this->addSql('ALTER TABLE risk_incident_link RENAME INDEX idx_risk_incident_link_incident TO IDX_71DBD00459E53FB9');
            }
            if ($this->indexExists('risk_incident_link', 'idx_risk_incident_link_linked_by')) {
                $this->addSql('ALTER TABLE risk_incident_link RENAME INDEX idx_risk_incident_link_linked_by TO IDX_71DBD0041AE3CFF3');
            }
        }

        // =========================================================
        // 27. corporate_governance — CHANGE columns + ADD FK + RENAME INDEX
        // =========================================================
        if ($schema->hasTable('corporate_governance')) {
            $this->addSql('ALTER TABLE corporate_governance CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE corporate_governance ADD CONSTRAINT FK_F759C417B03A8386 FOREIGN KEY IF NOT EXISTS (created_by_id) REFERENCES users (id)');
            if ($this->indexExists('corporate_governance', 'idx_9815e5739033212a')) {
                $this->addSql('ALTER TABLE corporate_governance RENAME INDEX idx_9815e5739033212a TO IDX_F759C4179033212A');
            }
            if ($this->indexExists('corporate_governance', 'idx_9815e573727aca70')) {
                $this->addSql('ALTER TABLE corporate_governance RENAME INDEX idx_9815e573727aca70 TO IDX_F759C417727ACA70');
            }
            if ($this->indexExists('corporate_governance', 'idx_9815e573b03a8386')) {
                $this->addSql('ALTER TABLE corporate_governance RENAME INDEX idx_9815e573b03a8386 TO IDX_F759C417B03A8386');
            }
        }

        // =========================================================
        // 28. asset — CHANGE is_dora_relevant column
        // =========================================================
        if ($schema->hasTable('asset')) {
            $this->addSql('ALTER TABLE asset CHANGE is_dora_relevant is_dora_relevant TINYINT NOT NULL');
        }

        // =========================================================
        // 29. document_acknowledgement_audience — RENAME INDEX
        // =========================================================
        if ($this->indexExists('document_acknowledgement_audience', 'idx_doc_ack_aud_doc')) {
            $this->addSql('ALTER TABLE document_acknowledgement_audience RENAME INDEX idx_doc_ack_aud_doc TO IDX_D5FCD194C33F7837');
        }
        if ($this->indexExists('document_acknowledgement_audience', 'idx_doc_ack_aud_usr')) {
            $this->addSql('ALTER TABLE document_acknowledgement_audience RENAME INDEX idx_doc_ack_aud_usr TO IDX_D5FCD194A76ED395');
        }
    }

    public function down(Schema $schema): void
    {
        // Best-effort partial revert. Not all changes are reversible.
        // This migration is never rolled back in production.
        if ($schema->hasTable('audit_finding_controls')) {
            $this->addSql('DROP TABLE audit_finding_controls');
        }
        // Reverting stale index drops / FK renames would require knowing original
        // constraint names — omitted intentionally. The schema is the source of truth.
    }
}
