<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ISB-Review Sprint-2 gate MINOR-1 (docs/DATA_REUSE_PLAN_REVIEW_ISB.md):
 * per-row audit trail for compliance-mapping imports. Creates two tables:
 *
 *   import_session   — file-level header (uploader, tenant, hash, counts)
 *   import_row_event — row-level trail (decision, before/after, line#)
 *
 * Lands on top of AuditLog.actor_role (Version20260418190000) — both changes
 * are part of the ISB Sprint-2 audit hardening.
 */
final class Version20260418200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create import_session + import_row_event (ISB MINOR-1 per-row import trail).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE import_session (
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
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE import_row_event (
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
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE import_session
                ADD CONSTRAINT FK_IMPORT_SESSION_TENANT
                FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE import_session
                ADD CONSTRAINT FK_IMPORT_SESSION_UPLOADED_BY
                FOREIGN KEY (uploaded_by_id) REFERENCES users (id)
                ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE import_session
                ADD CONSTRAINT FK_IMPORT_SESSION_FOUR_EYES
                FOREIGN KEY (four_eyes_approver_id) REFERENCES users (id)
                ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE import_row_event
                ADD CONSTRAINT FK_IMPORT_ROW_EVENT_SESSION
                FOREIGN KEY (session_id) REFERENCES import_session (id)
                ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_row_event DROP FOREIGN KEY FK_IMPORT_ROW_EVENT_SESSION');
        $this->addSql('ALTER TABLE import_session DROP FOREIGN KEY FK_IMPORT_SESSION_TENANT');
        $this->addSql('ALTER TABLE import_session DROP FOREIGN KEY FK_IMPORT_SESSION_UPLOADED_BY');
        $this->addSql('ALTER TABLE import_session DROP FOREIGN KEY FK_IMPORT_SESSION_FOUR_EYES');
        $this->addSql('DROP TABLE import_row_event');
        $this->addSql('DROP TABLE import_session');
    }
}
