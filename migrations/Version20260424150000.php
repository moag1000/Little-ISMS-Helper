<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Squash-Migration: konsolidiert alle 47 Schema-Änderungen seit v2.6.0
 * (Tag 97dd7ae5, 2026-04-17) in einen idempotenten Lauf.
 *
 * Sicher anwendbar auf:
 *   - fresh v2.6.0-DB (führt alle 47 Schema-States durch)
 *   - existing DB (alle Statements IF NOT EXISTS / information_schema-Checks — no-op)
 *
 * Alte 47 Migration-Files liegen in migrations/legacy/ (nicht gelöscht für
 * Rollback-Referenzen in doctrine_migration_versions).
 *
 * KEIN PREPARE/EXECUTE-Pattern — stattdessen safeAddColumn() / safeAddFK()
 * via information_schema-Checks vor addSql(). Verhindert silent-fail.
 *
 * Reihenfolge: CREATE TABLE → ALTER TABLE (columns) → Data-Backfills → FKs
 */
final class Version20260424150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squash since v2.6.0 — 47 migrations consolidated (idempotent, no PREPARE/EXECUTE).';
    }

    // -------------------------------------------------------------------------
    // UP
    // -------------------------------------------------------------------------

    public function up(Schema $schema): void
    {
        // =====================================================================
        // SECTION 1 — NEW TABLES (CREATE TABLE IF NOT EXISTS)
        // =====================================================================

        // --- 1.1 data_subject_request (GDPR Art. 15-22) ---
        $this->addSql('CREATE TABLE IF NOT EXISTS data_subject_request (
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

        // --- 1.2 elementary_threat (BSI 200-3) ---
        $this->addSql('CREATE TABLE IF NOT EXISTS elementary_threat (
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

        // --- 1.3 business_process_dependencies (Self-referencing ManyToMany) ---
        $this->addSql('CREATE TABLE IF NOT EXISTS business_process_dependencies (
            process_id INT NOT NULL,
            depends_on_id INT NOT NULL,
            INDEX IDX_820E09667EC2F574 (process_id),
            INDEX IDX_820E09661E088F8 (depends_on_id),
            PRIMARY KEY (process_id, depends_on_id)
        ) DEFAULT CHARACTER SET utf8mb4');

        // --- 1.4 fulfillment_inheritance_log ---
        $this->addSql("CREATE TABLE IF NOT EXISTS fulfillment_inheritance_log (
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

        // --- 1.5 four_eyes_approval_request ---
        $this->addSql("CREATE TABLE IF NOT EXISTS four_eyes_approval_request (
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

        // --- 1.6 tag ---
        $this->addSql("CREATE TABLE IF NOT EXISTS tag (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT DEFAULT NULL,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(30) DEFAULT 'framework' NOT NULL,
            framework_code VARCHAR(50) DEFAULT NULL,
            color VARCHAR(20) DEFAULT 'secondary' NOT NULL,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_tag_tenant (tenant_id),
            INDEX idx_tag_type (type),
            INDEX idx_tag_framework_code (framework_code),
            UNIQUE INDEX uniq_tag_tenant_name (tenant_id, name),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4");

        // --- 1.7 entity_tag (polymorphic) ---
        $this->addSql("CREATE TABLE IF NOT EXISTS entity_tag (
            id INT AUTO_INCREMENT NOT NULL,
            tag_id INT NOT NULL,
            entity_class VARCHAR(150) NOT NULL,
            entity_id INT NOT NULL,
            tagged_by_id INT DEFAULT NULL,
            tagged_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            tagged_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            removal_reason LONGTEXT DEFAULT NULL,
            active_marker INT AS (CASE WHEN tagged_until IS NULL THEN entity_id ELSE NULL END) PERSISTENT,
            INDEX idx_entity_tag_entity (entity_class, entity_id),
            INDEX idx_entity_tag_active (tagged_until),
            INDEX idx_entity_tag_tag (tag_id),
            INDEX idx_entity_tag_tagged_by (tagged_by_id),
            UNIQUE INDEX uniq_entity_tag_active (tag_id, entity_class, active_marker),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4");

        // --- 1.8 incident_vulnerability (join table) ---
        $this->addSql('CREATE TABLE IF NOT EXISTS incident_vulnerability (
            incident_id INT NOT NULL,
            vulnerability_id INT NOT NULL,
            INDEX IDX_IV_INCIDENT (incident_id),
            INDEX IDX_IV_VULN (vulnerability_id),
            PRIMARY KEY (incident_id, vulnerability_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // --- 1.9 audit_findings ---
        $this->addSql("CREATE TABLE IF NOT EXISTS audit_findings (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            audit_id INT NOT NULL,
            related_control_id INT DEFAULT NULL,
            reported_by_id INT DEFAULT NULL,
            assigned_to_id INT DEFAULT NULL,
            finding_number VARCHAR(50) DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            type VARCHAR(50) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            status VARCHAR(30) NOT NULL,
            clause_reference VARCHAR(100) DEFAULT NULL,
            evidence LONGTEXT DEFAULT NULL,
            due_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            closed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_af_tenant (tenant_id),
            INDEX idx_af_audit (audit_id),
            INDEX idx_af_status (status),
            INDEX idx_af_severity (severity),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.10 corrective_actions ---
        $this->addSql("CREATE TABLE IF NOT EXISTS corrective_actions (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            finding_id INT NOT NULL,
            responsible_person_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NOT NULL,
            root_cause_analysis LONGTEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL,
            planned_completion_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            actual_completion_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            effectiveness_review_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            effectiveness_notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_ca_tenant (tenant_id),
            INDEX idx_ca_finding (finding_id),
            INDEX idx_ca_status (status),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.11 asset_dependencies (join table) ---
        $this->addSql('CREATE TABLE IF NOT EXISTS asset_dependencies (
            dependent_asset_id INT NOT NULL,
            depends_on_asset_id INT NOT NULL,
            INDEX idx_asset_dep_dependent (dependent_asset_id),
            INDEX idx_asset_dep_depends_on (depends_on_asset_id),
            PRIMARY KEY (dependent_asset_id, depends_on_asset_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // --- 1.12 import_session ---
        $this->addSql("CREATE TABLE IF NOT EXISTS import_session (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            uploaded_by_id INT DEFAULT NULL,
            four_eyes_approver_id INT DEFAULT NULL,
            uploaded_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            file_sha256 VARCHAR(64) NOT NULL,
            file_size_bytes INT NOT NULL,
            format VARCHAR(32) NOT NULL,
            row_count_total INT DEFAULT 0 NOT NULL,
            row_count_imported INT DEFAULT 0 NOT NULL,
            row_count_superseded INT DEFAULT 0 NOT NULL,
            row_count_skipped INT DEFAULT 0 NOT NULL,
            status VARCHAR(20) NOT NULL,
            committed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_import_session_tenant_uploaded (tenant_id, uploaded_at),
            INDEX idx_import_session_status (status),
            INDEX IDX_IMPORT_SESSION_UPLOADED_BY (uploaded_by_id),
            INDEX IDX_IMPORT_SESSION_FOUR_EYES (four_eyes_approver_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.13 import_row_event ---
        $this->addSql("CREATE TABLE IF NOT EXISTS import_row_event (
            id INT AUTO_INCREMENT NOT NULL,
            session_id INT NOT NULL,
            line_number INT NOT NULL,
            decision VARCHAR(20) NOT NULL,
            target_entity_type VARCHAR(100) DEFAULT NULL,
            target_entity_id INT DEFAULT NULL,
            before_state LONGTEXT DEFAULT NULL,
            after_state LONGTEXT DEFAULT NULL,
            source_row_raw LONGTEXT DEFAULT NULL,
            error_message LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_import_row_event_session_decision (session_id, decision),
            INDEX idx_import_row_event_target (target_entity_type, target_entity_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.14 kpi_threshold_config ---
        $this->addSql("CREATE TABLE IF NOT EXISTS kpi_threshold_config (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            kpi_key VARCHAR(100) NOT NULL,
            good_threshold INT NOT NULL,
            warning_threshold INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_kpi_threshold_tenant_key (tenant_id, kpi_key),
            INDEX idx_kpi_threshold_tenant (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.15 kpi_snapshot ---
        $this->addSql("CREATE TABLE IF NOT EXISTS kpi_snapshot (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            snapshot_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
            kpi_data JSON NOT NULL COMMENT '(DC2Type:json)',
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_kpi_snapshot_tenant_date (tenant_id, snapshot_date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.16 compliance_requirement_evidence (join table) ---
        $this->addSql('CREATE TABLE IF NOT EXISTS compliance_requirement_evidence (
            compliance_requirement_id INT NOT NULL,
            document_id INT NOT NULL,
            INDEX idx_cre_requirement (compliance_requirement_id),
            INDEX idx_cre_document (document_id),
            PRIMARY KEY (compliance_requirement_id, document_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // --- 1.17 threat_led_penetration_test (DORA TLPT) ---
        $this->addSql("CREATE TABLE IF NOT EXISTS threat_led_penetration_test (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            engagement_number VARCHAR(50) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            scope LONGTEXT DEFAULT NULL,
            threat_intelligence_basis LONGTEXT DEFAULT NULL,
            provider_type VARCHAR(20) NOT NULL,
            test_provider VARCHAR(255) DEFAULT NULL,
            jurisdiction_codes JSON DEFAULT NULL COMMENT '(DC2Type:json)',
            status VARCHAR(30) NOT NULL,
            planned_start_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            planned_end_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            actual_start_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            actual_end_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            executive_summary LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_tlpt_tenant (tenant_id),
            INDEX idx_tlpt_status (status),
            INDEX idx_tlpt_planned_date (planned_start_date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.18 tlpt_finding (join table) ---
        $this->addSql('CREATE TABLE IF NOT EXISTS tlpt_finding (
            threat_led_penetration_test_id INT NOT NULL,
            audit_finding_id INT NOT NULL,
            INDEX idx_tf_tlpt (threat_led_penetration_test_id),
            INDEX idx_tf_finding (audit_finding_id),
            PRIMARY KEY (threat_led_penetration_test_id, audit_finding_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // --- 1.19 portfolio_snapshot ---
        $this->addSql("CREATE TABLE IF NOT EXISTS portfolio_snapshot (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            snapshot_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
            framework_code VARCHAR(50) NOT NULL,
            nist_csf_category VARCHAR(20) NOT NULL,
            fulfillment_percentage INT NOT NULL,
            requirement_count INT NOT NULL,
            gap_count INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_portfolio_snapshot_tenant_date (tenant_id, snapshot_date),
            INDEX idx_portfolio_snapshot_framework (tenant_id, framework_code),
            UNIQUE INDEX uniq_portfolio_snapshot_day (tenant_id, snapshot_date, framework_code, nist_csf_category),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.20 audit_freeze ---
        $this->addSql("CREATE TABLE IF NOT EXISTS audit_freeze (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            created_by_id INT NOT NULL,
            freeze_name VARCHAR(200) NOT NULL,
            stichtag DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            framework_codes JSON NOT NULL,
            purpose VARCHAR(50) NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            payload_json JSON NOT NULL,
            payload_sha256 VARCHAR(64) NOT NULL,
            pdf_generated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            pdf_path VARCHAR(255) DEFAULT NULL,
            INDEX idx_audit_freeze_tenant (tenant_id),
            INDEX idx_audit_freeze_stichtag (tenant_id, stichtag),
            UNIQUE INDEX uniq_audit_freeze_tenant_date_name (tenant_id, stichtag, freeze_name),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.21 industry_baseline ---
        $this->addSql("CREATE TABLE IF NOT EXISTS industry_baseline (
            id INT AUTO_INCREMENT NOT NULL,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(200) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            industry VARCHAR(30) NOT NULL,
            source VARCHAR(30) NOT NULL,
            required_frameworks JSON NOT NULL,
            recommended_frameworks JSON NOT NULL,
            preset_risks JSON NOT NULL,
            preset_assets JSON NOT NULL,
            preset_applicable_controls JSON NOT NULL,
            fte_days_saved_estimate DOUBLE PRECISION NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            version VARCHAR(20) NOT NULL,
            UNIQUE INDEX uniq_industry_baseline_code (code),
            INDEX idx_industry_baseline_industry (industry),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.22 applied_baseline ---
        $this->addSql("CREATE TABLE IF NOT EXISTS applied_baseline (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            applied_by_id INT DEFAULT NULL,
            baseline_code VARCHAR(50) NOT NULL,
            baseline_version VARCHAR(20) NOT NULL,
            applied_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            created_summary JSON NOT NULL,
            UNIQUE INDEX uniq_applied_baseline_tenant_code (tenant_id, baseline_code),
            INDEX idx_applied_baseline_tenant (tenant_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.23 prototype_protection_assessment (TISAX) ---
        $this->addSql("CREATE TABLE IF NOT EXISTS prototype_protection_assessment (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            supplier_id INT DEFAULT NULL,
            location_id INT DEFAULT NULL,
            assessor_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            scope LONGTEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            tisax_level VARCHAR(5) DEFAULT NULL,
            required_labels JSON DEFAULT NULL COMMENT '(DC2Type:json)',
            assessment_date DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            next_assessment_due DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
            overall_result VARCHAR(30) DEFAULT NULL,
            physical_result VARCHAR(30) DEFAULT NULL,
            physical_notes LONGTEXT DEFAULT NULL,
            organisation_result VARCHAR(30) DEFAULT NULL,
            organisation_notes LONGTEXT DEFAULT NULL,
            handling_result VARCHAR(30) DEFAULT NULL,
            handling_notes LONGTEXT DEFAULT NULL,
            trial_operation_result VARCHAR(30) DEFAULT NULL,
            trial_operation_notes LONGTEXT DEFAULT NULL,
            events_result VARCHAR(30) DEFAULT NULL,
            events_notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_ppa_tenant (tenant_id),
            INDEX idx_ppa_status (status),
            INDEX idx_ppa_assessment_date (assessment_date),
            INDEX idx_ppa_supplier (supplier_id),
            INDEX idx_ppa_location (location_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.24 prototype_protection_evidence (join table) ---
        $this->addSql('CREATE TABLE IF NOT EXISTS prototype_protection_evidence (
            prototype_protection_assessment_id INT NOT NULL,
            document_id INT NOT NULL,
            INDEX idx_ppe_assessment (prototype_protection_assessment_id),
            INDEX idx_ppe_document (document_id),
            PRIMARY KEY(prototype_protection_assessment_id, document_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // --- 1.25 reuse_trend_snapshot ---
        $this->addSql("CREATE TABLE IF NOT EXISTS reuse_trend_snapshot (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            captured_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            captured_day DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
            fte_saved_total DOUBLE PRECISION NOT NULL DEFAULT 0,
            inherited_count INT NOT NULL DEFAULT 0,
            fulfillments_total INT NOT NULL DEFAULT 0,
            inheritance_rate_pct INT NOT NULL DEFAULT 0,
            INDEX idx_rts_tenant_date (tenant_id, captured_at),
            UNIQUE INDEX uniq_rts_tenant_day (tenant_id, captured_day),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.26 guided_tour_step_override ---
        $this->addSql("CREATE TABLE IF NOT EXISTS guided_tour_step_override (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT DEFAULT NULL,
            tour_id VARCHAR(32) NOT NULL,
            step_id VARCHAR(64) NOT NULL,
            locale VARCHAR(5) NOT NULL,
            title_override VARCHAR(255) DEFAULT NULL,
            body_override LONGTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_by_email VARCHAR(180) DEFAULT NULL,
            UNIQUE INDEX uniq_tour_step_override (tenant_id, tour_id, step_id, locale),
            INDEX idx_tour_step_tenant (tenant_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`");

        // --- 1.27 supplier_criticality_level ---
        $this->addSql("CREATE TABLE IF NOT EXISTS supplier_criticality_level (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            code VARCHAR(50) NOT NULL,
            label_de VARCHAR(100) NOT NULL,
            label_en VARCHAR(100) NOT NULL,
            sort_order SMALLINT NOT NULL DEFAULT 50,
            color VARCHAR(30) DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE INDEX uniq_scl_tenant_code (tenant_id, code),
            INDEX idx_scl_tenant (tenant_id),
            INDEX idx_scl_sort (tenant_id, sort_order),
            INDEX idx_scl_active (tenant_id, is_active),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.28 risk_approval_config ---
        $this->addSql("CREATE TABLE IF NOT EXISTS risk_approval_config (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            threshold_automatic INT NOT NULL,
            threshold_manager INT NOT NULL,
            threshold_executive INT NOT NULL,
            updated_by_id INT DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            note LONGTEXT DEFAULT NULL,
            UNIQUE INDEX uniq_risk_approval_config_tenant (tenant_id),
            INDEX IDX_risk_approval_config_updated_by (updated_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // --- 1.29 incident_sla_config ---
        $this->addSql("CREATE TABLE IF NOT EXISTS incident_sla_config (
            id INT AUTO_INCREMENT NOT NULL,
            tenant_id INT NOT NULL,
            severity VARCHAR(20) NOT NULL,
            response_hours INT NOT NULL,
            escalation_hours INT DEFAULT NULL,
            resolution_hours INT DEFAULT NULL,
            updated_by_id INT DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            note LONGTEXT DEFAULT NULL,
            INDEX idx_incident_sla_tenant (tenant_id),
            UNIQUE INDEX uniq_incident_sla_tenant_severity (tenant_id, severity),
            INDEX IDX_incident_sla_updated_by (updated_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        // =====================================================================
        // SECTION 2 — ALTER EXISTING TABLES: ADD COLUMNS (idempotent)
        // =====================================================================

        // --- 2.1 compliance_mapping: source, version, valid_from, valid_until ---
        $this->safeAddColumn('compliance_mapping', 'source', "VARCHAR(100) DEFAULT 'algorithm_generated_v1.0' NOT NULL");
        $this->safeAddColumn('compliance_mapping', 'version', 'INT DEFAULT 1 NOT NULL');
        $this->safeAddColumn('compliance_mapping', 'valid_from', "DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->safeAddColumn('compliance_mapping', 'valid_until', "DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        // --- 2.2 risk: new fields ---
        $this->safeAddColumn('risk', 'review_interval_days', 'INT DEFAULT NULL');
        $this->safeAddColumn('risk', 'communication_plan', 'JSON DEFAULT NULL');
        $this->safeAddColumn('risk', 'threat_intelligence_id', 'INT DEFAULT NULL');
        $this->safeAddColumn('risk', 'linked_vulnerability_id', 'INT DEFAULT NULL');

        // --- 2.3 compliance_requirement: BSI fields + effort + assessment_level ---
        $this->safeAddColumn('compliance_requirement', 'anforderungs_typ', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('compliance_requirement', 'absicherungs_stufe', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('compliance_requirement', 'base_effort_days', 'INT DEFAULT NULL');
        $this->safeAddColumn('compliance_requirement', 'assessment_level', 'VARCHAR(10) DEFAULT NULL');

        // --- 2.4 compliance_requirement_fulfillment: adjusted effort ---
        $this->safeAddColumn('compliance_requirement_fulfillment', 'adjusted_effort_days', 'INT DEFAULT NULL');
        $this->safeAddColumn('compliance_requirement_fulfillment', 'adjusted_effort_reason', 'LONGTEXT DEFAULT NULL');

        // --- 2.5 business_process: MBCO (ISO 22301) ---
        $this->safeAddColumn('business_process', 'mbco', 'VARCHAR(255) DEFAULT NULL');
        $this->safeAddColumn('business_process', 'mbco_percentage', 'INT DEFAULT NULL');

        // --- 2.6 business_continuity_plan: BSI 200-4 phase ---
        $this->safeAddColumn('business_continuity_plan', 'bsi_phase', 'VARCHAR(30) DEFAULT NULL');

        // --- 2.7 supplier: DORA + BCM + extra fields ---
        $this->safeAddColumn('supplier', 'lei_code', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'ict_criticality', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'ict_function_type', 'VARCHAR(100) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'substitutability', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'has_subcontractors', 'TINYINT(1) DEFAULT 0 NOT NULL');
        $this->safeAddColumn('supplier', 'subcontractor_chain', 'JSON DEFAULT NULL');
        $this->safeAddColumn('supplier', 'processing_locations', 'JSON DEFAULT NULL');
        $this->safeAddColumn('supplier', 'last_dora_audit_date', 'DATE DEFAULT NULL');
        $this->safeAddColumn('supplier', 'has_exit_strategy', 'TINYINT(1) DEFAULT 0 NOT NULL');
        $this->safeAddColumn('supplier', 'exit_strategy_document_id', 'INT DEFAULT NULL');
        $this->safeAddColumn('supplier', 'gdpr_processor_status', 'VARCHAR(30) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'gdpr_transfer_mechanism', 'VARCHAR(50) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'gdpr_av_contract_signed', 'TINYINT(1) DEFAULT 0 NOT NULL');
        $this->safeAddColumn('supplier', 'gdpr_av_contract_date', 'DATE DEFAULT NULL');
        $this->safeAddColumn('supplier', 'supplier_rto', 'INT DEFAULT NULL');
        $this->safeAddColumn('supplier', 'supplier_recovery_capability', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'alternative_supplier', 'VARCHAR(255) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'bcm_assessment_date', 'DATETIME DEFAULT NULL');
        $this->safeAddColumn('supplier', 'bcm_assessment_result', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'nace_code', 'VARCHAR(10) DEFAULT NULL');
        $this->safeAddColumn('supplier', 'country_of_head_office', 'VARCHAR(2) DEFAULT NULL');

        // --- 2.8 scheduled_report: payload + tls_verified_at ---
        $this->safeAddColumn('scheduled_report', 'payload', "JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
        $this->safeAddColumn('scheduled_report', 'tls_verified_at', 'DATETIME DEFAULT NULL');

        // --- 2.9 audit_log: HMAC chain + actor_role ---
        $this->safeAddColumn('audit_log', 'hmac', 'VARCHAR(64) DEFAULT NULL');
        $this->safeAddColumn('audit_log', 'previous_hmac', 'VARCHAR(64) DEFAULT NULL');
        $this->safeAddColumn('audit_log', 'actor_role', 'VARCHAR(30) DEFAULT NULL');

        // --- 2.10 Pattern A: *_user_id dual-state columns (7 entities) ---
        $this->safeAddColumn('asset', 'owner_user_id', 'INT DEFAULT NULL');
        $this->safeAddColumn('business_continuity_plan', 'plan_owner_user_id', 'INT DEFAULT NULL');
        $this->safeAddColumn('business_process', 'process_owner_user_id', 'INT DEFAULT NULL');
        $this->safeAddColumn('control', 'responsible_person_user_id', 'INT DEFAULT NULL');
        $this->safeAddColumn('incident', 'reported_by_user_id', 'INT DEFAULT NULL');
        $this->safeAddColumn('risk', 'acceptance_approved_by_user_id', 'INT DEFAULT NULL');
        $this->safeAddColumn('training', 'trainer_user_id', 'INT DEFAULT NULL');

        // --- 2.11 users: skip_welcome_page + completed_tours ---
        $this->safeAddColumn('users', 'skip_welcome_page', 'TINYINT(1) DEFAULT 0 NOT NULL');
        $this->safeAddColumn('users', 'completed_tours', "JSON NOT NULL DEFAULT (JSON_ARRAY()) COMMENT '(DC2Type:json) Guided-Tour-IDs already completed by this user'");

        // --- 2.12 incident: DORA Art. 18 classification fields + visible_to_holding ---
        $this->safeAddColumn('incident', 'dora_clients_impacted', 'INT DEFAULT NULL');
        $this->safeAddColumn('incident', 'dora_reputation_impact', 'VARCHAR(30) DEFAULT NULL');
        $this->safeAddColumn('incident', 'dora_service_downtime_minutes', 'INT DEFAULT NULL');
        $this->safeAddColumn('incident', 'dora_geographical_spread', "JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
        $this->safeAddColumn('incident', 'dora_data_loss_occurred', 'TINYINT(1) DEFAULT NULL');
        $this->safeAddColumn('incident', 'dora_economic_impact_eur', 'INT DEFAULT NULL');
        $this->safeAddColumn('incident', 'dora_classification', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('incident', 'visible_to_holding', 'TINYINT(1) NOT NULL DEFAULT 1');

        // --- 2.13 compliance_framework: lifecycle_state + successor_id ---
        $this->safeAddColumn('compliance_framework', 'lifecycle_state', "VARCHAR(20) NOT NULL DEFAULT 'active'");
        $this->safeAddColumn('compliance_framework', 'successor_id', 'INT DEFAULT NULL');

        // --- 2.14 mapping_gap_item: risk_treatment_plan_id + remediation_control_id ---
        $this->safeAddColumn('mapping_gap_item', 'risk_treatment_plan_id', 'INT DEFAULT NULL');
        $this->safeAddColumn('mapping_gap_item', 'remediation_control_id', 'INT DEFAULT NULL');

        // --- 2.15 asset + document: TISAX classification ---
        $this->safeAddColumn('asset', 'tisax_information_classification', 'VARCHAR(30) DEFAULT NULL');
        $this->safeAddColumn('document', 'tisax_information_classification', 'VARCHAR(30) DEFAULT NULL');

        // --- 2.16 document: inheritable + override_allowed ---
        $this->safeAddColumn('document', 'inheritable', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->safeAddColumn('document', 'override_allowed', 'TINYINT(1) NOT NULL DEFAULT 1');

        // --- 2.17 tenant: NIS2 + BSI + email branding fields ---
        $this->safeAddColumn('tenant', 'nis2_classification', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'nis2_sector', 'VARCHAR(150) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'nace_code', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'legal_name', 'VARCHAR(255) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'legal_form', 'VARCHAR(50) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'nis2_contact_point', 'VARCHAR(255) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'nis2_registered_at', 'DATE DEFAULT NULL');
        $this->safeAddColumn('tenant', 'bsi_phase', 'VARCHAR(20) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'email_from_name', 'VARCHAR(100) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'email_from_address', 'VARCHAR(180) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'email_logo_url', 'VARCHAR(500) DEFAULT NULL');
        $this->safeAddColumn('tenant', 'email_footer_text', 'LONGTEXT DEFAULT NULL');
        $this->safeAddColumn('tenant', 'email_support_address', 'VARCHAR(180) DEFAULT NULL');

        // --- 2.18 internal_audit: parent_audit_id (self-reference) ---
        $this->safeAddColumn('internal_audit', 'parent_audit_id', 'INT DEFAULT NULL');

        // --- 2.19 risk_appetite: review_buffer_multiplier ---
        $this->safeAddColumn('risk_appetite', 'review_buffer_multiplier', "NUMERIC(4, 2) NOT NULL DEFAULT '1.50'");

        // =====================================================================
        // SECTION 3 — INDEXES ON ALTERED TABLES (idempotent via IF NOT EXISTS)
        // =====================================================================

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cm_source ON compliance_mapping (source)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_cm_valid ON compliance_mapping (valid_from, valid_until)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_supplier_ict_criticality ON supplier (ict_criticality)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_supplier_gdpr_processor_status ON supplier (gdpr_processor_status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_audit_actor_role ON audit_log (actor_role)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_audit_parent ON internal_audit (parent_audit_id)');

        // =====================================================================
        // SECTION 4 — DATA BACKFILLS (idempotent)
        // =====================================================================

        // --- 4.1 compliance_mapping: set valid_from = created_at where NULL, then NOT NULL ---
        // Only run UPDATE if the column exists (it will after Section 2 above)
        $this->addSql('UPDATE compliance_mapping SET valid_from = created_at WHERE valid_from IS NULL');

        // --- 4.2 compliance_framework: seed lifecycle states ---
        $this->addSql("UPDATE compliance_framework SET lifecycle_state = 'deprecated' WHERE code = 'BSI-C5' AND lifecycle_state = 'active'");

        // --- 4.3 compliance_requirement: TISAX AL3 backfill ---
        $this->addSql("UPDATE compliance_requirement cr
            INNER JOIN compliance_framework cf ON cf.id = cr.framework_id
            SET cr.assessment_level = 'AL3'
            WHERE cf.code = 'TISAX-AL3' AND cr.assessment_level IS NULL");

        // --- 4.4 Pattern A: backfill *_user_id from legacy string fields ---
        $patternAMappings = [
            ['table' => 'asset',                    'str' => 'owner',                  'user' => 'owner_user_id',                   'tenant' => 'tenant_id'],
            ['table' => 'business_continuity_plan', 'str' => 'plan_owner',             'user' => 'plan_owner_user_id',              'tenant' => 'tenant_id'],
            ['table' => 'business_process',         'str' => 'process_owner',          'user' => 'process_owner_user_id',           'tenant' => 'tenant_id'],
            ['table' => 'control',                  'str' => 'responsible_person',     'user' => 'responsible_person_user_id',      'tenant' => 'tenant_id'],
            ['table' => 'incident',                 'str' => 'reported_by',            'user' => 'reported_by_user_id',             'tenant' => 'tenant_id'],
            ['table' => 'risk',                     'str' => 'acceptance_approved_by', 'user' => 'acceptance_approved_by_user_id',  'tenant' => 'tenant_id'],
            ['table' => 'training',                 'str' => 'trainer',                'user' => 'trainer_user_id',                 'tenant' => 'tenant_id'],
        ];
        foreach ($patternAMappings as $m) {
            // Match by "First Last"
            $this->addSql(sprintf(
                "UPDATE `%s` t INNER JOIN users u
                   ON u.tenant_id = t.%s
                  AND LOWER(TRIM(t.%s)) = LOWER(CONCAT(u.first_name, ' ', u.last_name))
                 SET t.%s = u.id
                 WHERE t.%s IS NULL AND t.%s IS NOT NULL AND t.%s <> ''",
                $m['table'], $m['tenant'], $m['str'], $m['user'], $m['user'], $m['str'], $m['str']
            ));
            // Fallback: match by email
            $this->addSql(sprintf(
                "UPDATE `%s` t INNER JOIN users u
                   ON u.tenant_id = t.%s
                  AND LOWER(TRIM(t.%s)) = LOWER(u.email)
                 SET t.%s = u.id
                 WHERE t.%s IS NULL AND t.%s IS NOT NULL AND t.%s <> ''",
                $m['table'], $m['tenant'], $m['str'], $m['user'], $m['user'], $m['str'], $m['str']
            ));
        }

        // --- 4.5 system_settings: document.default_classification ---
        $this->addSql("INSERT INTO system_settings (category, setting_key, value, is_encrypted, description, updated_by, created_at, updated_at)
            SELECT 'document', 'default_classification', '\"internal\"', 0,
                   'Default information classification applied when uploading a new document (public, internal, confidential, strictly_confidential)',
                   NULL, NOW(), NOW()
            WHERE NOT EXISTS (
                SELECT 1 FROM system_settings WHERE category = 'document' AND setting_key = 'default_classification'
            )");

        // --- 4.6 system_settings: audit.retention_days ---
        $this->addSql("INSERT INTO system_settings (category, setting_key, value, is_encrypted, description, created_at, updated_at)
            SELECT 'audit', 'retention_days', '730', 0,
                   'Audit-Log Retention in Tagen. Min 365 (NIS2 Art. 21.2), Default 730 (ISO 27001 Clause 9.1). Editierbar unter /admin/audit-log/retention.',
                   NOW(), NOW()
            WHERE NOT EXISTS (
                SELECT 1 FROM (SELECT 1 FROM system_settings WHERE category = 'audit' AND setting_key = 'retention_days') AS existing
            )");

        // --- 4.7 supplier_criticality_level: 4 default levels per tenant ---
        $levels = [
            ['critical', 'Kritisch', 'Critical', 10, 'danger', 0],
            ['high', 'Hoch', 'High', 20, 'warning', 0],
            ['medium', 'Mittel', 'Medium', 30, 'info', 1],
            ['low', 'Gering', 'Low', 40, 'secondary', 0],
        ];
        foreach ($levels as [$code, $labelDe, $labelEn, $sort, $color, $isDefault]) {
            $this->addSql("INSERT INTO supplier_criticality_level (tenant_id, code, label_de, label_en, sort_order, color, is_default, is_active)
                SELECT t.id, '{$code}', '{$labelDe}', '{$labelEn}', {$sort}, '{$color}', {$isDefault}, 1
                FROM tenant t
                WHERE NOT EXISTS (
                    SELECT 1 FROM supplier_criticality_level scl WHERE scl.tenant_id = t.id AND scl.code = '{$code}'
                )");
        }

        // --- 4.8 risk_approval_config: defaults (3/7/25) per tenant ---
        $this->addSql("INSERT INTO risk_approval_config (tenant_id, threshold_automatic, threshold_manager, threshold_executive, created_at)
            SELECT t.id, 3, 7, 25, NOW()
            FROM tenant t
            WHERE NOT EXISTS (
                SELECT 1 FROM risk_approval_config rac WHERE rac.tenant_id = t.id
            )");

        // --- 4.9 incident_sla_config: defaults per tenant ---
        $slaDefaults = [
            ['low', 48],
            ['medium', 24],
            ['high', 8],
            ['critical', 2],
            ['breach', 1],
        ];
        foreach ($slaDefaults as [$severity, $hours]) {
            $this->addSql(sprintf("INSERT INTO incident_sla_config (tenant_id, severity, response_hours, created_at)
                SELECT t.id, '%s', %d, NOW()
                FROM tenant t
                WHERE NOT EXISTS (
                    SELECT 1 FROM incident_sla_config isc WHERE isc.tenant_id = t.id AND isc.severity = '%s'
                )", $severity, $hours, $severity));
        }

        // =====================================================================
        // SECTION 5 — FK CHANGES ON EXISTING TABLES (idempotent)
        // =====================================================================

        // --- 5.1 risk: DROP CASCADE, ADD SET NULL FKs ---
        // Drop old FKs if they exist (safe guard: ignore error if already dropped)
        $this->safeDropFK('risk', 'FK_7906D5415DA1941');
        $this->safeDropFK('risk', 'FK_7906D541217BBB47');
        $this->safeDropFK('risk', 'FK_7906D54164D218E');
        $this->safeDropFK('risk', 'FK_7906D5412ADD6D8C');
        // Re-add as SET NULL
        $this->safeAddFK('risk', 'FK_7906D5415DA1941', 'FOREIGN KEY (asset_id) REFERENCES asset (id) ON DELETE SET NULL');
        $this->safeAddFK('risk', 'FK_7906D541217BBB47', 'FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->safeAddFK('risk', 'FK_7906D54164D218E', 'FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL');
        $this->safeAddFK('risk', 'FK_7906D5412ADD6D8C', 'FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE SET NULL');

        // --- 5.2 compliance_requirement: parent FK SET NULL ---
        $this->safeDropFK('compliance_requirement', 'FK_D115DC52658A1B7C');
        $this->safeAddFK('compliance_requirement', 'FK_D115DC52658A1B7C', 'FOREIGN KEY (parent_requirement_id) REFERENCES compliance_requirement (id) ON DELETE SET NULL');

        // --- 5.3 document: uploaded_by nullable + SET NULL ---
        $this->safeDropFK('document', 'FK_D8698A76A2B28FE8');
        $this->safeModifyColumn('document', 'uploaded_by_id', 'INT DEFAULT NULL');
        $this->safeAddFK('document', 'FK_D8698A76A2B28FE8', 'FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL');

        // =====================================================================
        // SECTION 6 — FKs FOR NEW TABLES
        // =====================================================================

        // data_subject_request
        $this->safeAddFK('data_subject_request', 'FK_EBA4CA2A9033212A', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->safeAddFK('data_subject_request', 'FK_EBA4CA2AF4BD7827', 'FOREIGN KEY (assigned_to_id) REFERENCES users (id)');
        $this->safeAddFK('data_subject_request', 'FK_EBA4CA2A72D4D63B', 'FOREIGN KEY (processing_activity_id) REFERENCES processing_activity (id) ON DELETE SET NULL');

        // business_process_dependencies
        $this->safeAddFK('business_process_dependencies', 'FK_820E09667EC2F574', 'FOREIGN KEY (process_id) REFERENCES business_process (id) ON DELETE CASCADE');
        $this->safeAddFK('business_process_dependencies', 'FK_820E09661E088F8', 'FOREIGN KEY (depends_on_id) REFERENCES business_process (id) ON DELETE CASCADE');

        // fulfillment_inheritance_log
        $this->safeAddFK('fulfillment_inheritance_log', 'fk_fil_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->safeAddFK('fulfillment_inheritance_log', 'fk_fil_fulfillment', 'FOREIGN KEY (fulfillment_id) REFERENCES compliance_requirement_fulfillment (id) ON DELETE CASCADE');
        $this->safeAddFK('fulfillment_inheritance_log', 'fk_fil_mapping', 'FOREIGN KEY (derived_from_mapping_id) REFERENCES compliance_mapping (id) ON DELETE RESTRICT');
        $this->safeAddFK('fulfillment_inheritance_log', 'fk_fil_reviewed_by', 'FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->safeAddFK('fulfillment_inheritance_log', 'fk_fil_feyes_by', 'FOREIGN KEY (four_eyes_approved_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->safeAddFK('fulfillment_inheritance_log', 'fk_fil_overridden_by', 'FOREIGN KEY (overridden_by_id) REFERENCES users (id) ON DELETE SET NULL');

        // four_eyes_approval_request
        $this->safeAddFK('four_eyes_approval_request', 'fk_feyes_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->safeAddFK('four_eyes_approval_request', 'fk_feyes_requested_by', 'FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->safeAddFK('four_eyes_approval_request', 'fk_feyes_requested_approver', 'FOREIGN KEY (requested_approver_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->safeAddFK('four_eyes_approval_request', 'fk_feyes_approved_by', 'FOREIGN KEY (approved_by_id) REFERENCES users (id) ON DELETE SET NULL');

        // tag
        $this->safeAddFK('tag', 'fk_tag_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // entity_tag
        $this->safeAddFK('entity_tag', 'fk_entity_tag_tag', 'FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->safeAddFK('entity_tag', 'fk_entity_tag_tagged_by', 'FOREIGN KEY (tagged_by_id) REFERENCES users (id) ON DELETE SET NULL');

        // incident_vulnerability
        $this->safeAddFK('incident_vulnerability', 'FK_IV_INCIDENT', 'FOREIGN KEY (incident_id) REFERENCES incident (id) ON DELETE CASCADE');
        $this->safeAddFK('incident_vulnerability', 'FK_IV_VULN', 'FOREIGN KEY (vulnerability_id) REFERENCES vulnerabilities (id) ON DELETE CASCADE');

        // audit_findings
        $this->safeAddFK('audit_findings', 'FK_AF_TENANT', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->safeAddFK('audit_findings', 'FK_AF_AUDIT', 'FOREIGN KEY (audit_id) REFERENCES internal_audit (id) ON DELETE CASCADE');
        $this->safeAddFK('audit_findings', 'FK_AF_CONTROL', 'FOREIGN KEY (related_control_id) REFERENCES control (id) ON DELETE SET NULL');
        $this->safeAddFK('audit_findings', 'FK_AF_REPORTER', 'FOREIGN KEY (reported_by_id) REFERENCES users (id)');
        $this->safeAddFK('audit_findings', 'FK_AF_ASSIGNEE', 'FOREIGN KEY (assigned_to_id) REFERENCES users (id)');

        // corrective_actions
        $this->safeAddFK('corrective_actions', 'FK_CA_TENANT', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->safeAddFK('corrective_actions', 'FK_CA_FINDING', 'FOREIGN KEY (finding_id) REFERENCES audit_findings (id) ON DELETE CASCADE');
        $this->safeAddFK('corrective_actions', 'FK_CA_USER', 'FOREIGN KEY (responsible_person_id) REFERENCES users (id)');

        // asset_dependencies
        $this->safeAddFK('asset_dependencies', 'FK_ASSET_DEP_DEPENDENT', 'FOREIGN KEY (dependent_asset_id) REFERENCES asset (id) ON DELETE CASCADE');
        $this->safeAddFK('asset_dependencies', 'FK_ASSET_DEP_DEPENDS_ON', 'FOREIGN KEY (depends_on_asset_id) REFERENCES asset (id) ON DELETE CASCADE');

        // import_session
        $this->safeAddFK('import_session', 'FK_IMPORT_SESSION_TENANT', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->safeAddFK('import_session', 'FK_IMPORT_SESSION_UPLOADED_BY', 'FOREIGN KEY (uploaded_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->safeAddFK('import_session', 'FK_IMPORT_SESSION_FOUR_EYES', 'FOREIGN KEY (four_eyes_approver_id) REFERENCES users (id) ON DELETE SET NULL');

        // import_row_event
        $this->safeAddFK('import_row_event', 'FK_IMPORT_ROW_EVENT_SESSION', 'FOREIGN KEY (session_id) REFERENCES import_session (id) ON DELETE CASCADE');

        // kpi_threshold_config
        $this->safeAddFK('kpi_threshold_config', 'FK_KPI_THRESHOLD_TENANT', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // kpi_snapshot
        $this->safeAddFK('kpi_snapshot', 'FK_KPI_SNAPSHOT_TENANT', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // compliance_requirement_evidence
        $this->safeAddFK('compliance_requirement_evidence', 'FK_CRE_REQUIREMENT', 'FOREIGN KEY (compliance_requirement_id) REFERENCES compliance_requirement (id) ON DELETE CASCADE');
        $this->safeAddFK('compliance_requirement_evidence', 'FK_CRE_DOCUMENT', 'FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');

        // threat_led_penetration_test
        $this->safeAddFK('threat_led_penetration_test', 'FK_TLPT_TENANT', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // tlpt_finding
        $this->safeAddFK('tlpt_finding', 'FK_TF_TLPT', 'FOREIGN KEY (threat_led_penetration_test_id) REFERENCES threat_led_penetration_test (id) ON DELETE CASCADE');
        $this->safeAddFK('tlpt_finding', 'FK_TF_FINDING', 'FOREIGN KEY (audit_finding_id) REFERENCES audit_findings (id) ON DELETE CASCADE');

        // portfolio_snapshot (FK was inline in CREATE TABLE — add defensively)
        $this->safeAddFK('portfolio_snapshot', 'FK_portfolio_snapshot_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // audit_freeze (FKs were inline in CREATE TABLE — add defensively)
        $this->safeAddFK('audit_freeze', 'FK_audit_freeze_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->safeAddFK('audit_freeze', 'FK_audit_freeze_created_by', 'FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        // applied_baseline (FKs were inline in CREATE TABLE — add defensively)
        $this->safeAddFK('applied_baseline', 'FK_applied_baseline_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->safeAddFK('applied_baseline', 'FK_applied_baseline_user', 'FOREIGN KEY (applied_by_id) REFERENCES users (id) ON DELETE SET NULL');

        // prototype_protection_assessment (FKs were inline in CREATE TABLE)
        $this->safeAddFK('prototype_protection_assessment', 'fk_ppa_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id)');
        $this->safeAddFK('prototype_protection_assessment', 'fk_ppa_supplier', 'FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE SET NULL');
        $this->safeAddFK('prototype_protection_assessment', 'fk_ppa_location', 'FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL');
        $this->safeAddFK('prototype_protection_assessment', 'fk_ppa_assessor', 'FOREIGN KEY (assessor_id) REFERENCES users (id) ON DELETE SET NULL');

        // prototype_protection_evidence (FKs were inline in CREATE TABLE)
        $this->safeAddFK('prototype_protection_evidence', 'fk_ppe_assessment', 'FOREIGN KEY (prototype_protection_assessment_id) REFERENCES prototype_protection_assessment (id) ON DELETE CASCADE');
        $this->safeAddFK('prototype_protection_evidence', 'fk_ppe_document', 'FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');

        // reuse_trend_snapshot (FK was inline in CREATE TABLE)
        $this->safeAddFK('reuse_trend_snapshot', 'fk_rts_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // guided_tour_step_override (FK was inline in CREATE TABLE)
        $this->safeAddFK('guided_tour_step_override', 'fk_tour_step_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // supplier_criticality_level
        $this->safeAddFK('supplier_criticality_level', 'FK_scl_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');

        // risk: new FKs for threat_intelligence + vulnerability
        $this->safeAddFK('risk', 'FK_7906D541F2BE5A0E', 'FOREIGN KEY (threat_intelligence_id) REFERENCES threat_intelligence (id) ON DELETE SET NULL');
        $this->safeAddFK('risk', 'FK_7906D5419F82DE33', 'FOREIGN KEY (linked_vulnerability_id) REFERENCES vulnerabilities (id) ON DELETE SET NULL');

        // risk: Pattern A user FK
        $this->safeAddFK('risk', 'FK_RISK_APPROVER_USER', 'FOREIGN KEY (acceptance_approved_by_user_id) REFERENCES users (id) ON DELETE SET NULL');

        // asset: Pattern A user FK
        $this->safeAddFK('asset', 'FK_ASSET_OWNER_USER', 'FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL');

        // business_continuity_plan: Pattern A user FK
        $this->safeAddFK('business_continuity_plan', 'FK_BCP_OWNER_USER', 'FOREIGN KEY (plan_owner_user_id) REFERENCES users (id) ON DELETE SET NULL');

        // business_process: Pattern A user FK
        $this->safeAddFK('business_process', 'FK_BP_OWNER_USER', 'FOREIGN KEY (process_owner_user_id) REFERENCES users (id) ON DELETE SET NULL');

        // control: Pattern A user FK
        $this->safeAddFK('control', 'FK_CONTROL_OWNER_USER', 'FOREIGN KEY (responsible_person_user_id) REFERENCES users (id) ON DELETE SET NULL');

        // incident: Pattern A user FK
        $this->safeAddFK('incident', 'FK_INCIDENT_REPORTER_USER', 'FOREIGN KEY (reported_by_user_id) REFERENCES users (id) ON DELETE SET NULL');

        // training: Pattern A user FK
        $this->safeAddFK('training', 'FK_TRAINING_TRAINER_USER', 'FOREIGN KEY (trainer_user_id) REFERENCES users (id) ON DELETE SET NULL');

        // supplier: exit_strategy_document FK
        $this->safeAddFK('supplier', 'fk_supplier_exit_strategy_document', 'FOREIGN KEY (exit_strategy_document_id) REFERENCES document (id) ON DELETE SET NULL');

        // compliance_framework: successor FK (self-reference)
        $this->safeAddFK('compliance_framework', 'FK_CF_SUCCESSOR', 'FOREIGN KEY (successor_id) REFERENCES compliance_framework (id) ON DELETE SET NULL');

        // mapping_gap_item: risk_treatment_plan + remediation_control FKs
        $this->safeAddFK('mapping_gap_item', 'FK_MGI_TREATMENT_PLAN', 'FOREIGN KEY (risk_treatment_plan_id) REFERENCES risk_treatment_plan (id) ON DELETE SET NULL');
        $this->safeAddFK('mapping_gap_item', 'FK_MGI_REMEDIATION_CONTROL', 'FOREIGN KEY (remediation_control_id) REFERENCES control (id) ON DELETE SET NULL');

        // internal_audit: parent_audit FK
        $this->safeAddFK('internal_audit', 'fk_audit_parent', 'FOREIGN KEY (parent_audit_id) REFERENCES internal_audit (id) ON DELETE SET NULL');

        // internal_audit_additional_framework join table
        $this->addSql("CREATE TABLE IF NOT EXISTS internal_audit_additional_framework (
            internal_audit_id INT NOT NULL,
            compliance_framework_id INT NOT NULL,
            INDEX idx_iaaf_audit (internal_audit_id),
            INDEX idx_iaaf_framework (compliance_framework_id),
            PRIMARY KEY(internal_audit_id, compliance_framework_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->safeAddFK('internal_audit_additional_framework', 'fk_iaaf_audit', 'FOREIGN KEY (internal_audit_id) REFERENCES internal_audit (id) ON DELETE CASCADE');
        $this->safeAddFK('internal_audit_additional_framework', 'fk_iaaf_framework', 'FOREIGN KEY (compliance_framework_id) REFERENCES compliance_framework (id) ON DELETE CASCADE');

        // risk_approval_config FKs
        $this->safeAddFK('risk_approval_config', 'FK_risk_approval_config_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->safeAddFK('risk_approval_config', 'FK_risk_approval_config_updated_by', 'FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL');

        // incident_sla_config FKs
        $this->safeAddFK('incident_sla_config', 'FK_incident_sla_tenant', 'FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->safeAddFK('incident_sla_config', 'FK_incident_sla_updated_by', 'FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    // -------------------------------------------------------------------------
    // DOWN — intentionally non-functional
    // -------------------------------------------------------------------------

    public function down(Schema $schema): void
    {
        // Squash-Rollback ist nicht sicher automatisierbar.
        // Restore from backup oder reapply einzelne legacy migrations aus migrations/legacy/.
        throw new \RuntimeException(
            'Squash-Rollback nicht automatisch unterstützt. '
            . 'Restore from backup oder rollback via legacy migrations in migrations/legacy/.'
        );
    }

    // -------------------------------------------------------------------------
    // HELPERS — kein PREPARE/EXECUTE
    // -------------------------------------------------------------------------

    /**
     * Idempotentes ALTER TABLE ADD COLUMN.
     * Prüft via information_schema ob Spalte existiert, bevor addSql() aufgerufen wird.
     */
    private function safeAddColumn(string $table, string $column, string $definition): void
    {
        $existing = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column',
            ['table' => $table, 'column' => $column]
        );
        if ((int) $existing === 0) {
            $this->addSql(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        }
    }

    /**
     * Idempotentes ALTER TABLE MODIFY COLUMN.
     */
    private function safeModifyColumn(string $table, string $column, string $definition): void
    {
        $existing = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column',
            ['table' => $table, 'column' => $column]
        );
        if ((int) $existing > 0) {
            $this->addSql(sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` %s', $table, $column, $definition));
        }
    }

    /**
     * Idempotentes ADD CONSTRAINT (FOREIGN KEY).
     */
    private function safeAddFK(string $table, string $constraintName, string $ddl): void
    {
        $existing = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND CONSTRAINT_NAME = :name',
            ['table' => $table, 'name' => $constraintName]
        );
        if ((int) $existing === 0) {
            $this->addSql(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` %s', $table, $constraintName, $ddl));
        }
    }

    /**
     * Idempotentes DROP FOREIGN KEY (ignoriert wenn nicht vorhanden).
     */
    private function safeDropFK(string $table, string $constraintName): void
    {
        $existing = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND CONSTRAINT_NAME = :name',
            ['table' => $table, 'name' => $constraintName]
        );
        if ((int) $existing > 0) {
            $this->addSql(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $constraintName));
        }
    }
}
