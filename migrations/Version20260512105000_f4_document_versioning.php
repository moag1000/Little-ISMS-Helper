<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F4 Evidence-Versioning + Cross-Framework-Cascade (Sprint 5A).
 *
 * DDL changes (all idempotent — column/table existence checked before ADD):
 *   - CREATE TABLE document_version
 *   - CREATE TABLE evidence_reverification_task
 *   - ALTER TABLE document ADD content_hash, current_version_id
 *   - ALTER TABLE control ADD evidence_outdated
 *   - ALTER TABLE compliance_requirement_fulfillment ADD evidence_outdated
 *
 * Data migration:
 *   - Existing Document rows that have a sha256_hash: create a v1 DocumentVersion
 *     row as historical evidence baseline (publishedAt = uploaded_at).
 *
 * IMPORTANT: isTransactional() returns false.
 * MySQL ALTER TABLE / CREATE TABLE implicitly commit, which breaks Doctrine's
 * per-migration SAVEPOINT. Without this override the second DDL in a
 * migrate-run fails with "SAVEPOINT DOCTRINE_X does not exist".
 */
final class Version20260512105000_f4_document_versioning extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'F4 Sprint 5A — DocumentVersion + EvidenceReverificationTask tables + evidence_outdated columns';
    }

    public function up(Schema $schema): void
    {
        // ── 1. CREATE TABLE document_version ──────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_version (
                id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id          INT          NOT NULL,
                document_id        INT          NOT NULL,
                version_number     INT UNSIGNED NOT NULL DEFAULT 1,
                content_hash       VARCHAR(64)  NOT NULL,
                file_name          VARCHAR(255) NOT NULL,
                file_path          VARCHAR(500) NOT NULL,
                file_size          INT UNSIGNED NOT NULL DEFAULT 0,
                mime_type          VARCHAR(100) NOT NULL,
                uploaded_by_id     INT          NULL,
                uploaded_at        DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                published_at       DATETIME     NULL     COMMENT '(DC2Type:datetime_immutable)',
                retention_until    DATETIME     NULL     COMMENT '(DC2Type:datetime_immutable)',
                replaced_by_id     INT UNSIGNED NULL,
                is_active          TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_docver_tenant   (tenant_id),
                INDEX idx_docver_document (document_id),
                INDEX idx_docver_active   (is_active),
                CONSTRAINT fk_docver_tenant
                    FOREIGN KEY (tenant_id)    REFERENCES tenant (id)    ON DELETE CASCADE,
                CONSTRAINT fk_docver_document
                    FOREIGN KEY (document_id)  REFERENCES document (id)  ON DELETE CASCADE,
                CONSTRAINT fk_docver_uploaded_by
                    FOREIGN KEY (uploaded_by_id) REFERENCES users (id)   ON DELETE SET NULL,
                CONSTRAINT fk_docver_replaced_by
                    FOREIGN KEY (replaced_by_id) REFERENCES document_version (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── 2. CREATE TABLE evidence_reverification_task ──────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS evidence_reverification_task (
                id                       INT          NOT NULL AUTO_INCREMENT,
                tenant_id                INT          NOT NULL,
                document_version_id      INT UNSIGNED NOT NULL,
                control_id               INT          NULL,
                compliance_fulfillment_id INT         NULL,
                assigned_to_id           INT          NULL,
                due_date                 DATETIME     NULL COMMENT '(DC2Type:datetime_immutable)',
                status                   VARCHAR(20)  NOT NULL DEFAULT 'pending',
                completed_at             DATETIME     NULL COMMENT '(DC2Type:datetime_immutable)',
                notes                    LONGTEXT     NULL,
                created_at               DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_revtask_tenant (tenant_id),
                INDEX idx_revtask_status (status),
                INDEX idx_revtask_due    (due_date),
                CONSTRAINT fk_revtask_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                CONSTRAINT fk_revtask_docver
                    FOREIGN KEY (document_version_id) REFERENCES document_version (id) ON DELETE CASCADE,
                CONSTRAINT fk_revtask_control
                    FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE SET NULL,
                CONSTRAINT fk_revtask_fulfillment
                    FOREIGN KEY (compliance_fulfillment_id) REFERENCES compliance_requirement_fulfillment (id) ON DELETE SET NULL,
                CONSTRAINT fk_revtask_assigned
                    FOREIGN KEY (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── 3a. ALTER TABLE document — add content_hash column ───────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE document
                ADD COLUMN IF NOT EXISTS content_hash VARCHAR(64) NULL AFTER sha256_hash
        SQL);

        // ── 3b. ALTER TABLE document — add current_version_id column ─────────
        $this->addSql(<<<'SQL'
            ALTER TABLE document
                ADD COLUMN IF NOT EXISTS current_version_id INT UNSIGNED NULL AFTER content_hash
        SQL);

        // ── 3c. ALTER TABLE document — add FK to document_version ────────────
        // Only add the FK if the column exists and the constraint does not yet exist.
        $this->addSql(<<<'SQL'
            ALTER TABLE document
                ADD CONSTRAINT fk_doc_current_version
                    FOREIGN KEY (current_version_id) REFERENCES document_version (id) ON DELETE SET NULL
        SQL);

        // ── 4. ALTER TABLE control — add evidence_outdated ────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE control
                ADD COLUMN IF NOT EXISTS evidence_outdated TINYINT(1) NOT NULL DEFAULT 0 AFTER customer_or_provider_responsibility
        SQL);

        // ── 5. ALTER TABLE compliance_requirement_fulfillment — add evidence_outdated
        $this->addSql(<<<'SQL'
            ALTER TABLE compliance_requirement_fulfillment
                ADD COLUMN IF NOT EXISTS evidence_outdated TINYINT(1) NOT NULL DEFAULT 0
        SQL);

        // ── 6. DATA migration: seed v1 DocumentVersion for existing documents ──
        // Only migrate documents that have a sha256_hash (= were actually uploaded).
        // We use INSERT IGNORE to be idempotent if run twice.
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO document_version
                (tenant_id, document_id, version_number, content_hash, file_name, file_path,
                 file_size, mime_type, uploaded_by_id, uploaded_at, published_at, is_active)
            SELECT
                d.tenant_id,
                d.id,
                1,
                d.sha256_hash,
                d.filename,
                d.file_path,
                d.file_size,
                d.mime_type,
                d.uploaded_by_id,
                d.uploaded_at,
                d.uploaded_at,
                1
            FROM document d
            WHERE d.sha256_hash IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM document_version dv
                  WHERE dv.document_id = d.id AND dv.version_number = 1
              )
        SQL);

        // ── 7. Back-fill document.content_hash + current_version_id ──────────
        $this->addSql(<<<'SQL'
            UPDATE document d
            INNER JOIN document_version dv
                ON dv.document_id = d.id AND dv.version_number = 1
            SET d.content_hash       = dv.content_hash,
                d.current_version_id = dv.id
            WHERE d.content_hash IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Remove added columns first (FK must be dropped before column)
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY IF EXISTS fk_doc_current_version');
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS current_version_id');
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS content_hash');
        $this->addSql('ALTER TABLE control DROP COLUMN IF EXISTS evidence_outdated');
        $this->addSql('ALTER TABLE compliance_requirement_fulfillment DROP COLUMN IF EXISTS evidence_outdated');
        $this->addSql('DROP TABLE IF EXISTS evidence_reverification_task');
        $this->addSql('DROP TABLE IF EXISTS document_version');
    }
}
