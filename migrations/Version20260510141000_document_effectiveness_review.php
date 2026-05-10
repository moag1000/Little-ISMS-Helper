<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Document effectiveness-review tracking (Auditor MINOR-NC reply,
 * 2026-05-10).
 *
 * Adds three columns to `document` so the operational evidence of a
 * policy's effectiveness review (Wirksamkeitspruefung) can be persisted
 * separately from the Policy-Wizard's `in_progress` SoA bump:
 *
 *  - `last_effectiveness_review_at` (DATETIME, NULL): timestamp of the
 *     most recent review. NULL = never reviewed (the controller treats
 *     this as the worst possible age for overdue ranking).
 *  - `last_effectiveness_review_by_id` (INT, NULL, FK users(id) ON
 *     DELETE SET NULL): the User (ISB / Auditor) who performed the
 *     review. SET NULL preserves the timestamp evidence after user
 *     lifecycle changes.
 *  - `effectiveness_review_notes` (TEXT, NULL): free-text rationale.
 *
 * Idempotent: every column / FK / index step is wrapped in an
 * INFORMATION_SCHEMA lookup so partial re-runs never fail. Plain
 * `ALTER TABLE` only — no PREPARE/EXECUTE (CLAUDE.md pitfall #6).
 *
 * `isTransactional() = false`: every ALTER TABLE implicitly commits
 * in MySQL; running >1 DDL migration in a single `migrate` call
 * without this override fails on the SAVEPOINT (CLAUDE.md pitfall #6).
 */
final class Version20260510141000_document_effectiveness_review extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Document: add effectiveness-review tracking columns '
            . '(last_effectiveness_review_at + last_effectiveness_review_by_id + '
            . 'effectiveness_review_notes) so ISB/Auditor reviews are persisted '
            . 'as evidence separate from the Policy-Wizard SoA bump.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('document', 'last_effectiveness_review_at')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD COLUMN last_effectiveness_review_at DATETIME DEFAULT NULL
                    COMMENT '(DC2Type:datetime_immutable)'
            SQL);
        }

        if (!$this->columnExists('document', 'last_effectiveness_review_by_id')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD COLUMN last_effectiveness_review_by_id INT DEFAULT NULL
            SQL);
            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD CONSTRAINT FK_document_effectiveness_review_by
                    FOREIGN KEY (last_effectiveness_review_by_id) REFERENCES users (id) ON DELETE SET NULL
            SQL);
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_document_effectiveness_review_by
                    ON document (last_effectiveness_review_by_id)
            SQL);
        }

        if (!$this->columnExists('document', 'effectiveness_review_notes')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document
                    ADD COLUMN effectiveness_review_notes LONGTEXT DEFAULT NULL
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->indexExists('document', 'idx_document_effectiveness_review_by')) {
            $this->addSql('DROP INDEX idx_document_effectiveness_review_by ON document');
        }
        if ($this->foreignKeyExists('document', 'FK_document_effectiveness_review_by')) {
            $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_document_effectiveness_review_by');
        }
        if ($this->columnExists('document', 'last_effectiveness_review_by_id')) {
            $this->addSql('ALTER TABLE document DROP COLUMN last_effectiveness_review_by_id');
        }
        if ($this->columnExists('document', 'last_effectiveness_review_at')) {
            $this->addSql('ALTER TABLE document DROP COLUMN last_effectiveness_review_at');
        }
        if ($this->columnExists('document', 'effectiveness_review_notes')) {
            $this->addSql('ALTER TABLE document DROP COLUMN effectiveness_review_notes');
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
