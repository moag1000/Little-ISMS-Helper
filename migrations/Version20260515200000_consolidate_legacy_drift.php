<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tech-Debt Consolidation: legacy PREPARE/EXECUTE schema drift
 *
 * Applies genuinely additive statements that earlier PREPARE/EXECUTE-style
 * migrations silently skipped (Doctrine records them as "executed" but the
 * DDL never ran).  Uses IF NOT EXISTS / IF EXISTS guards so this migration
 * is safe to re-run.
 *
 * Categories applied here:
 *   1. Missing tables: sla_deadline_monitor, nis2_registration_profile
 *   2. Missing FK constraints on bulk_import_batch
 *
 * Categories deferred (cosmetic serialization drift):
 *   - ALTER TABLE … CHANGE for DEFAULT NULL ordering / DOUBLE PRECISION
 *   - ALTER TABLE … RENAME INDEX for Doctrine-generated alias differences
 *   These produce no query-time errors and are tracked in the entity mapping.
 *   They will disappear once the relevant entity annotations are aligned with
 *   what Doctrine's DBAL schema comparison actually emits.
 *
 * @see docs/TECH_DEBT_MAY2026.md
 */
final class Version20260515200000_consolidate_legacy_drift extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolidate additive schema drift from PREPARE/EXECUTE legacy migrations (sla_deadline_monitor, nis2_registration_profile, bulk_import_batch FKs)';
    }

    public function isTransactional(): bool
    {
        // DDL (CREATE TABLE, ALTER TABLE) implicitly commits in MySQL/MariaDB.
        // Doctrine's per-migration SAVEPOINT is invalid after implicit commits,
        // so we must disable the transaction wrapper here.
        return false;
    }

    public function up(Schema $schema): void
    {
        // -------------------------------------------------------------------
        // 1. sla_deadline_monitor
        //    Added by Version20260514120000_f3_sla_deadline_monitor.php which
        //    used PREPARE/EXECUTE and silently failed on the test + staging DB.
        // -------------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sla_deadline_monitor (
                id                      INT AUTO_INCREMENT NOT NULL,
                entity_type             VARCHAR(80)  NOT NULL,
                entity_id               INT          NOT NULL,
                deadline_type           VARCHAR(40)  NOT NULL,
                triggered_at            DATETIME     NOT NULL,
                deadline_at             DATETIME     NOT NULL,
                notify_at_checkpoints   JSON         NOT NULL,
                last_notified_at_hours  INT          DEFAULT NULL,
                status                  VARCHAR(20)  NOT NULL,
                satisfied_at            DATETIME     DEFAULT NULL,
                tenant_id               INT          NOT NULL,
                satisfied_by_id         INT          DEFAULT NULL,
                INDEX IDX_CC28CBB238041CE (satisfied_by_id),
                INDEX idx_sla_tenant     (tenant_id),
                INDEX idx_sla_status_deadline (status, deadline_at),
                INDEX idx_sla_entity     (entity_type, entity_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE sla_deadline_monitor
                ADD CONSTRAINT FK_CC28CBB9033212A
                    FOREIGN KEY IF NOT EXISTS (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
            SQL
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE sla_deadline_monitor
                ADD CONSTRAINT FK_CC28CBB238041CE
                    FOREIGN KEY IF NOT EXISTS (satisfied_by_id) REFERENCES users (id)
            SQL
        );

        // -------------------------------------------------------------------
        // 2. nis2_registration_profile
        //    Added by Version20260514110000_f29_nis2_registration_profile.php
        // -------------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS nis2_registration_profile (
                id                              INT AUTO_INCREMENT NOT NULL,
                organization_legal_name         VARCHAR(255) NOT NULL,
                organization_legal_form         VARCHAR(100) NOT NULL,
                commercial_register_city        VARCHAR(255) NOT NULL,
                commercial_register_number      VARCHAR(100) NOT NULL,
                vat_id                          VARCHAR(50)  DEFAULT NULL,
                nace_codes                      JSON         NOT NULL,
                nis2_sector                     VARCHAR(100) NOT NULL,
                nis2_entity_category            VARCHAR(20)  NOT NULL,
                affected_headcount              INT          NOT NULL,
                affected_annual_turnover_eur    NUMERIC(15,2) DEFAULT NULL,
                ict_dependency_description      LONGTEXT     NOT NULL,
                last_reported_at                DATETIME     DEFAULT NULL,
                next_due_at                     DATETIME     NOT NULL,
                portal_confirmation_number      VARCHAR(80)  DEFAULT NULL,
                created_at                      DATETIME     NOT NULL,
                updated_at                      DATETIME     DEFAULT NULL,
                tenant_id                       INT          NOT NULL,
                incident_reporting_contact_id   INT          NOT NULL,
                security_responsible_contact_id INT          NOT NULL,
                backup_security_contact_id      INT          DEFAULT NULL,
                INDEX IDX_6C5D7621926E2D9E (incident_reporting_contact_id),
                INDEX IDX_6C5D7621D4F8E16F (security_responsible_contact_id),
                INDEX IDX_6C5D762172FE96B2 (backup_security_contact_id),
                INDEX idx_nis2_profile_next_due_at (next_due_at),
                UNIQUE INDEX uniq_nis2_profile_tenant (tenant_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE nis2_registration_profile
                ADD CONSTRAINT FK_6C5D76219033212A
                    FOREIGN KEY IF NOT EXISTS (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE
            SQL
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE nis2_registration_profile
                ADD CONSTRAINT FK_6C5D7621926E2D9E
                    FOREIGN KEY IF NOT EXISTS (incident_reporting_contact_id) REFERENCES users (id) ON DELETE RESTRICT
            SQL
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE nis2_registration_profile
                ADD CONSTRAINT FK_6C5D7621D4F8E16F
                    FOREIGN KEY IF NOT EXISTS (security_responsible_contact_id) REFERENCES users (id) ON DELETE RESTRICT
            SQL
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE nis2_registration_profile
                ADD CONSTRAINT FK_6C5D762172FE96B2
                    FOREIGN KEY IF NOT EXISTS (backup_security_contact_id) REFERENCES users (id) ON DELETE SET NULL
            SQL
        );

        // -------------------------------------------------------------------
        // 3. bulk_import_batch — missing FK constraints
        //    Table exists but FKs were never applied (PREPARE/EXECUTE failure).
        // -------------------------------------------------------------------
        $this->addSql(<<<'SQL'
            ALTER TABLE bulk_import_batch
                ADD CONSTRAINT FK_4F56D9899033212A
                    FOREIGN KEY IF NOT EXISTS (tenant_id) REFERENCES tenant (id)
            SQL
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE bulk_import_batch
                ADD CONSTRAINT FK_4F56D989FF402897
                    FOREIGN KEY IF NOT EXISTS (source_document_id) REFERENCES document (id) ON DELETE SET NULL
            SQL
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE bulk_import_batch
                ADD CONSTRAINT FK_4F56D9898B35AB5C
                    FOREIGN KEY IF NOT EXISTS (executed_by_id) REFERENCES users (id) ON DELETE SET NULL
            SQL
        );

        // -------------------------------------------------------------------
        // DEFERRED — cosmetic serialization drift (no query-time impact):
        //
        // ALTER TABLE tenant_branding CHANGE policy_doc_watermark_opacity …
        //   → DOUBLE PRECISION DEFAULT vs entity declaration difference
        // ALTER TABLE document CHANGE current_version_id …
        //   → DEFAULT NULL ordering cosmetic
        // ALTER TABLE document RENAME INDEX fk_doc_current_version …
        // ALTER TABLE authority_template CHANGE created_at …
        // ALTER TABLE evidence_reverification_task CHANGE … RENAME INDEX …
        // ALTER TABLE identity_provider_user_mapping CHANGE … RENAME INDEX …
        // ALTER TABLE document_version CHANGE … RENAME INDEX …
        // ALTER TABLE audit_finding_requirement RENAME INDEX …
        //
        // These will be addressed by aligning entity @ORM\Column declarations
        // with DBAL-emitted defaults (separate chore PR).
        // -------------------------------------------------------------------
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS sla_deadline_monitor');
        $this->addSql('DROP TABLE IF EXISTS nis2_registration_profile');

        $this->addSql(<<<'SQL'
            ALTER TABLE bulk_import_batch
                DROP FOREIGN KEY IF EXISTS FK_4F56D9899033212A,
                DROP FOREIGN KEY IF EXISTS FK_4F56D989FF402897,
                DROP FOREIGN KEY IF EXISTS FK_4F56D9898B35AB5C
            SQL
        );
    }
}
