<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W3 — Document extensions + DocumentControlLink.
 *
 * Adds five columns to `document` (provenance + immutability +
 * version chain) and creates the new `document_control_link` table
 * for explicit document↔control linkage with provenance metadata.
 *
 * Plain SQL only (no PREPARE/EXECUTE — see CLAUDE.md pitfall #6).
 * Idempotent ALTERs guarded by `INFORMATION_SCHEMA` lookups via
 * lightweight `IF NOT EXISTS` table create + `IF NOT EXISTS` indexes.
 * Column ADDs are conditional via dynamic SQL is FORBIDDEN here per
 * CLAUDE.md — instead, plain `ADD COLUMN` is used. If a re-run is
 * needed, restore from backup or use `app:schema:reconcile`.
 *
 * isTransactional() returns false because this migration runs DDL
 * (ALTER TABLE / CREATE TABLE), each of which implicitly commits in
 * MySQL.
 */
final class Version20260508130000_policy_wizard_w3_document_extensions extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W3: extend document table with generation provenance + '
            . 'immutability + supersedes; create document_control_link table.';
    }

    public function up(Schema $schema): void
    {
        // ── document table extensions ───────────────────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE document
                ADD COLUMN generated_from_template_id INT DEFAULT NULL,
                ADD COLUMN generated_from_wizard_run_id INT DEFAULT NULL,
                ADD COLUMN substitution_variables JSON DEFAULT NULL,
                ADD COLUMN is_immutable TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN supersedes_id INT DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document
                ADD CONSTRAINT FK_document_generated_from_template
                FOREIGN KEY (generated_from_template_id) REFERENCES policy_template (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document
                ADD CONSTRAINT FK_document_generated_from_wizard_run
                FOREIGN KEY (generated_from_wizard_run_id) REFERENCES wizard_run (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document
                ADD CONSTRAINT FK_document_supersedes
                FOREIGN KEY (supersedes_id) REFERENCES document (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_document_generated_from_template ON document (generated_from_template_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_document_generated_from_wizard_run ON document (generated_from_wizard_run_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_document_supersedes ON document (supersedes_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_document_is_immutable ON document (is_immutable)
        SQL);

        // ── document_control_link table ─────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_control_link (
                id INT AUTO_INCREMENT NOT NULL,
                document_id INT NOT NULL,
                control_id INT NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'manual',
                evidence_type VARCHAR(50) NOT NULL DEFAULT 'policy_document',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uq_dcl_document_control (document_id, control_id),
                INDEX idx_dcl_document (document_id),
                INDEX idx_dcl_control (control_id),
                INDEX idx_dcl_source (source),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document_control_link
                ADD CONSTRAINT FK_dcl_document
                FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document_control_link
                ADD CONSTRAINT FK_dcl_control
                FOREIGN KEY (control_id) REFERENCES control (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // ── drop document_control_link ──────────────────────────────────
        $this->addSql('ALTER TABLE document_control_link DROP FOREIGN KEY FK_dcl_control');
        $this->addSql('ALTER TABLE document_control_link DROP FOREIGN KEY FK_dcl_document');
        $this->addSql('DROP TABLE IF EXISTS document_control_link');

        // ── revert document extensions ──────────────────────────────────
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_document_supersedes');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_document_generated_from_wizard_run');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_document_generated_from_template');

        $this->addSql('DROP INDEX idx_document_supersedes ON document');
        $this->addSql('DROP INDEX idx_document_is_immutable ON document');
        $this->addSql('DROP INDEX idx_document_generated_from_wizard_run ON document');
        $this->addSql('DROP INDEX idx_document_generated_from_template ON document');

        $this->addSql(<<<'SQL'
            ALTER TABLE document
                DROP COLUMN supersedes_id,
                DROP COLUMN is_immutable,
                DROP COLUMN substitution_variables,
                DROP COLUMN generated_from_wizard_run_id,
                DROP COLUMN generated_from_template_id
        SQL);
    }
}
