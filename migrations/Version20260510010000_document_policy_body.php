<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard — editable policy bodies post-generation.
 *
 * Adds three columns to `document` that persist the rendered policy
 * body alongside the existing translation-driven re-render path:
 *
 *  - `policy_body` (TEXT, NULL): the rendered Markdown body. The
 *     {@see \App\Service\PolicyWizard\DocumentGenerator} writes this
 *     on first generation. Until now the body lived ONLY in the
 *     translation file + substitutionVariables JSON, re-rendered on
 *     every PDF export. Persisting it allows tenant-specific
 *     post-generation customisation without losing the wizard
 *     baseline.
 *  - `policy_body_edited_at` (DATETIME, NULL): timestamp of the most
 *     recent manual edit (NULL = never manually edited).
 *  - `policy_body_edited_by_id` (INT, NULL, FK users(id) ON DELETE
 *     SET NULL): the User who performed the most recent edit. Paired
 *     with `policy_body_edited_at` for the audit-trail surface.
 *
 * Idempotent: column-add steps are guarded by INFORMATION_SCHEMA
 * lookups so a partial re-run does not error. FK + index creation
 * use `IF NOT EXISTS` style guards via dedicated check queries.
 *
 * Plain `ALTER TABLE` only — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 *
 * `isTransactional() = false`: every ALTER TABLE implicitly commits in
 * MySQL; running >1 DDL migration in a single `migrate` call without
 * this override fails on the SAVEPOINT (CLAUDE.md pitfall #6).
 */
final class Version20260510010000_document_policy_body extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard: extend document table with policy_body + edit-tracking columns '
            . 'so wizard-generated policies can be customised post-generation while preserving '
            . 'the wizard baseline for re-generation diffing.';
    }

    public function up(Schema $schema): void
    {
        $hasPolicyBody = $this->columnExists('document', 'policy_body');
        $hasEditedAt = $this->columnExists('document', 'policy_body_edited_at');
        $hasEditedBy = $this->columnExists('document', 'policy_body_edited_by_id');

        if (!$hasPolicyBody) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD COLUMN policy_body LONGTEXT DEFAULT NULL
            SQL);
        }

        if (!$hasEditedAt) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD COLUMN policy_body_edited_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
            SQL);
        }

        if (!$hasEditedBy) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD COLUMN policy_body_edited_by_id INT DEFAULT NULL
            SQL);

            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD CONSTRAINT FK_document_policy_body_edited_by
                    FOREIGN KEY (policy_body_edited_by_id) REFERENCES users (id) ON DELETE SET NULL
            SQL);

            $this->addSql(<<<'SQL'
                CREATE INDEX idx_document_policy_body_edited_by
                    ON document (policy_body_edited_by_id)
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->indexExists('document', 'idx_document_policy_body_edited_by')) {
            $this->addSql('DROP INDEX idx_document_policy_body_edited_by ON document');
        }

        if ($this->foreignKeyExists('document', 'FK_document_policy_body_edited_by')) {
            $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_document_policy_body_edited_by');
        }

        if ($this->columnExists('document', 'policy_body_edited_by_id')) {
            $this->addSql('ALTER TABLE document DROP COLUMN policy_body_edited_by_id');
        }
        if ($this->columnExists('document', 'policy_body_edited_at')) {
            $this->addSql('ALTER TABLE document DROP COLUMN policy_body_edited_at');
        }
        if ($this->columnExists('document', 'policy_body')) {
            $this->addSql('ALTER TABLE document DROP COLUMN policy_body');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND column_name = :c',
            ['t' => $table, 'c' => $column],
        );
        return ((int) $row) > 0;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND index_name = :i',
            ['t' => $table, 'i' => $indexName],
        );
        return ((int) $row) > 0;
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $row = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE table_schema = DATABASE()
                  AND table_name = :t
                  AND constraint_name = :c
                  AND constraint_type = \'FOREIGN KEY\'',
            ['t' => $table, 'c' => $constraint],
        );
        return ((int) $row) > 0;
    }
}
