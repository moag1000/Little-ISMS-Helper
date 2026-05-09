<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W1 — domain entities.
 *
 * Adds six tables that back the Policy-Wizard feature:
 *   - policy_template                          (system-shared catalogue)
 *   - wizard_run                               (execution log per run)
 *   - tenant_policy_setting                    (hierarchy-aware settings)
 *   - policy_acknowledgement                   (A.6.3 evidence)
 *   - tenant_branding                          (PDF letterhead)
 *   - tenant_policy_setting_change_attempt     (override audit trail)
 *
 * Plain SQL only (no PREPARE/EXECUTE — see CLAUDE.md pitfall #6).
 * isTransactional() returns false because the migration contains
 * multiple DDL statements (CREATE TABLE / ALTER TABLE).
 */
final class Version20260508120000_policy_wizard_w1_domain extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W1: add policy_template, wizard_run, tenant_policy_setting, '
            . 'policy_acknowledgement, tenant_branding, tenant_policy_setting_change_attempt.';
    }

    public function up(Schema $schema): void
    {
        // ── policy_template (system-shared, no tenant_id) ────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS policy_template (
                id INT AUTO_INCREMENT NOT NULL,
                key_name VARCHAR(191) NOT NULL,
                standard VARCHAR(32) NOT NULL,
                topic VARCHAR(100) NOT NULL,
                document_type VARCHAR(32) NOT NULL,
                norm_ref VARCHAR(64) DEFAULT NULL,
                title_translation_key VARCHAR(191) NOT NULL,
                body_translation_key VARCHAR(191) NOT NULL,
                required_variables JSON DEFAULT NULL,
                linked_annex_a_controls JSON DEFAULT NULL,
                linked_bausteine JSON DEFAULT NULL,
                linked_dora_articles JSON DEFAULT NULL,
                affected_functions JSON DEFAULT NULL,
                review_interval_months INT NOT NULL DEFAULT 12,
                approval_chain JSON DEFAULT NULL,
                climate_change_wording TINYINT(1) NOT NULL DEFAULT 0,
                dpo_section_required TINYINT(1) NOT NULL DEFAULT 0,
                superseded_by_id INT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                version INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uq_policy_template_key (key_name),
                INDEX idx_policy_template_standard (standard),
                INDEX idx_policy_template_topic (topic),
                INDEX idx_policy_template_active (is_active),
                INDEX idx_policy_template_superseded_by (superseded_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE policy_template
                ADD CONSTRAINT FK_policy_template_superseded_by
                FOREIGN KEY (superseded_by_id) REFERENCES policy_template (id) ON DELETE SET NULL
        SQL);

        // ── wizard_run ─────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wizard_run (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                started_by_user_id INT NOT NULL,
                standards_adopted JSON DEFAULT NULL,
                mode VARCHAR(16) NOT NULL DEFAULT 'full',
                targeted_topics JSON DEFAULT NULL,
                finding_reference VARCHAR(100) DEFAULT NULL,
                affected_functions JSON DEFAULT NULL,
                started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                step VARCHAR(64) NOT NULL DEFAULT 'welcome',
                inputs JSON DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'in_progress',
                generated_document_ids JSON DEFAULT NULL,
                error_message LONGTEXT DEFAULT NULL,
                INDEX idx_wizard_run_tenant (tenant_id),
                INDEX idx_wizard_run_status (status),
                INDEX idx_wizard_run_started_at (started_at),
                INDEX idx_wizard_run_started_by_user (started_by_user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE wizard_run
                ADD CONSTRAINT FK_wizard_run_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE wizard_run
                ADD CONSTRAINT FK_wizard_run_started_by_user
                FOREIGN KEY (started_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
        SQL);

        // ── tenant_policy_setting ──────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tenant_policy_setting (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                key_name VARCHAR(191) NOT NULL,
                value JSON DEFAULT NULL,
                inherited_from_tenant_id INT DEFAULT NULL,
                override_mode VARCHAR(32) NOT NULL DEFAULT 'free',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_by_user_id INT NOT NULL,
                UNIQUE INDEX uq_tenant_policy_setting_tenant_key (tenant_id, key_name),
                INDEX idx_tenant_policy_setting_tenant (tenant_id),
                INDEX idx_tenant_policy_setting_key (key_name),
                INDEX idx_tenant_policy_setting_inherited (inherited_from_tenant_id),
                INDEX idx_tenant_policy_setting_user (updated_by_user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_policy_setting
                ADD CONSTRAINT FK_tenant_policy_setting_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_policy_setting
                ADD CONSTRAINT FK_tenant_policy_setting_inherited_from_tenant
                FOREIGN KEY (inherited_from_tenant_id) REFERENCES tenant (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_policy_setting
                ADD CONSTRAINT FK_tenant_policy_setting_updated_by_user
                FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
        SQL);

        // ── policy_acknowledgement ─────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS policy_acknowledgement (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                document_id INT NOT NULL,
                user_id INT NOT NULL,
                acknowledged_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                acknowledgement_method VARCHAR(24) NOT NULL,
                document_version VARCHAR(32) NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                UNIQUE INDEX uq_policy_acknowledgement_tenant_doc_user_ver
                    (tenant_id, document_id, user_id, document_version),
                INDEX idx_policy_acknowledgement_tenant (tenant_id),
                INDEX idx_policy_acknowledgement_document (document_id),
                INDEX idx_policy_acknowledgement_user (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE policy_acknowledgement
                ADD CONSTRAINT FK_policy_acknowledgement_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE policy_acknowledgement
                ADD CONSTRAINT FK_policy_acknowledgement_document
                FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE policy_acknowledgement
                ADD CONSTRAINT FK_policy_acknowledgement_user
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);

        // ── tenant_branding (1:1 with tenant) ───────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tenant_branding (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                logo_path VARCHAR(255) DEFAULT NULL,
                header_html LONGTEXT DEFAULT NULL,
                footer_html LONGTEXT DEFAULT NULL,
                primary_color VARCHAR(16) NOT NULL DEFAULT '#0d6efd',
                secondary_color VARCHAR(16) NOT NULL DEFAULT '#6c757d',
                font_family VARCHAR(64) NOT NULL DEFAULT 'Inter',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_by_user_id INT NOT NULL,
                UNIQUE INDEX uq_tenant_branding_tenant (tenant_id),
                INDEX idx_tenant_branding_user (updated_by_user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_branding
                ADD CONSTRAINT FK_tenant_branding_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_branding
                ADD CONSTRAINT FK_tenant_branding_updated_by_user
                FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
        SQL);

        // ── tenant_policy_setting_change_attempt (audit log) ────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tenant_policy_setting_change_attempt (
                id INT AUTO_INCREMENT NOT NULL,
                tenant_id INT NOT NULL,
                key_name VARCHAR(191) NOT NULL,
                attempted_value JSON DEFAULT NULL,
                blocked_reason VARCHAR(100) NOT NULL,
                override_mode VARCHAR(32) NOT NULL,
                attempted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                attempted_by_user_id INT NOT NULL,
                INDEX idx_tps_change_attempt_tenant_key_at (tenant_id, key_name, attempted_at),
                INDEX idx_tps_change_attempt_tenant (tenant_id),
                INDEX idx_tps_change_attempt_user (attempted_by_user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_policy_setting_change_attempt
                ADD CONSTRAINT FK_tenant_policy_setting_change_attempt_tenant
                FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE tenant_policy_setting_change_attempt
                ADD CONSTRAINT FK_tenant_policy_setting_change_attempt_user
                FOREIGN KEY (attempted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop FKs first (children before parents), then indexes implicit
        // in DROP TABLE, then tables. Order matters: tenant_policy_setting
        // references itself? No — only inherited_from_tenant_id → tenant.

        // tenant_policy_setting_change_attempt
        $this->addSql('ALTER TABLE tenant_policy_setting_change_attempt
            DROP FOREIGN KEY FK_tenant_policy_setting_change_attempt_user');
        $this->addSql('ALTER TABLE tenant_policy_setting_change_attempt
            DROP FOREIGN KEY FK_tenant_policy_setting_change_attempt_tenant');

        // tenant_branding
        $this->addSql('ALTER TABLE tenant_branding DROP FOREIGN KEY FK_tenant_branding_updated_by_user');
        $this->addSql('ALTER TABLE tenant_branding DROP FOREIGN KEY FK_tenant_branding_tenant');

        // policy_acknowledgement
        $this->addSql('ALTER TABLE policy_acknowledgement DROP FOREIGN KEY FK_policy_acknowledgement_user');
        $this->addSql('ALTER TABLE policy_acknowledgement DROP FOREIGN KEY FK_policy_acknowledgement_document');
        $this->addSql('ALTER TABLE policy_acknowledgement DROP FOREIGN KEY FK_policy_acknowledgement_tenant');

        // tenant_policy_setting
        $this->addSql('ALTER TABLE tenant_policy_setting
            DROP FOREIGN KEY FK_tenant_policy_setting_updated_by_user');
        $this->addSql('ALTER TABLE tenant_policy_setting
            DROP FOREIGN KEY FK_tenant_policy_setting_inherited_from_tenant');
        $this->addSql('ALTER TABLE tenant_policy_setting
            DROP FOREIGN KEY FK_tenant_policy_setting_tenant');

        // wizard_run
        $this->addSql('ALTER TABLE wizard_run DROP FOREIGN KEY FK_wizard_run_started_by_user');
        $this->addSql('ALTER TABLE wizard_run DROP FOREIGN KEY FK_wizard_run_tenant');

        // policy_template (self-FK must drop before TABLE drop)
        $this->addSql('ALTER TABLE policy_template DROP FOREIGN KEY FK_policy_template_superseded_by');

        // Now drop the tables themselves.
        $this->addSql('DROP TABLE IF EXISTS tenant_policy_setting_change_attempt');
        $this->addSql('DROP TABLE IF EXISTS tenant_branding');
        $this->addSql('DROP TABLE IF EXISTS policy_acknowledgement');
        $this->addSql('DROP TABLE IF EXISTS tenant_policy_setting');
        $this->addSql('DROP TABLE IF EXISTS wizard_run');
        $this->addSql('DROP TABLE IF EXISTS policy_template');
    }
}
