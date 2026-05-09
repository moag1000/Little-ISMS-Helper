<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W6-A — DPO Veto sub-workflow extensions.
 *
 * Implements `docs/plans/policy-wizard/06-dpo-input.md` §0.A.6
 * "Migration & version-bump safety". Adds three columns:
 *
 *   • policy_template.dpo_gated_section_keys (JSON, nullable)
 *     Explicit list of section keys that need DPO sign-off. Null = use
 *     the legacy single-key default (`privacy_addendum`) when
 *     `dpo_section_required=1`.
 *
 *   • document_section.approval_role        (VARCHAR(16), nullable)
 *     Split-state role: `ciso|dpo|joint`. Existing legacy rows are
 *     backfilled to `ciso` so the existing CISO-only behaviour is
 *     preserved.
 *
 *   • document_section.edit_locked          (TINYINT(1), default 0)
 *     Set after DPO sign-off so the CISO is locked out of further
 *     edits (per §0.A.4). Cleared on DPO veto / re-edit.
 *
 *   • document_section.authored_by_user_id  (INT, nullable, FK users.id)
 *     Author of the section content; checked at approval time to
 *     enforce the §0.A.5 self-approval prohibition (Art. 38(3) GDPR).
 *
 * Idempotent: every column-add is guarded by an INFORMATION_SCHEMA
 * lookup so the migration is safe to re-run on a partially-applied DB.
 *
 * Per CLAUDE.md pitfall #6: plain SQL only (no PREPARE/EXECUTE), and
 * `isTransactional()=false` because the ALTER TABLE statements
 * implicitly commit in MySQL — running this migration in the same
 * `migrate` invocation as another DDL migration otherwise blows the
 * Doctrine-Migrations SAVEPOINT.
 */
final class Version20260508160000_policy_wizard_w6_dpo_veto_extensions extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W6-A: extend policy_template + document_section '
            . 'with DPO veto sub-workflow columns (approval_role, edit_locked, '
            . 'authored_by_user_id, dpo_gated_section_keys).';
    }

    public function up(Schema $schema): void
    {
        // ── Pre-flight: required tables MUST exist ───────────────────────
        foreach (['policy_template', 'document_section'] as $table) {
            $exists = (bool) $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ?
                    SQL,
                [$table],
            );
            $this->abortIf(
                !$exists,
                sprintf('%s table missing — run earlier W1/W3 migrations first', $table),
            );
        }

        // ── policy_template.dpo_gated_section_keys ───────────────────────
        if (!$this->columnExists('policy_template', 'dpo_gated_section_keys')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE policy_template
                    ADD COLUMN dpo_gated_section_keys JSON DEFAULT NULL
            SQL);
        }

        // ── document_section.approval_role ───────────────────────────────
        if (!$this->columnExists('document_section', 'approval_role')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document_section
                    ADD COLUMN approval_role VARCHAR(16) DEFAULT NULL
            SQL);
            // Backfill: existing legacy sections default to `ciso` so the
            // current CISO-only approval behaviour is preserved (§0.A.6).
            // We deliberately use UPDATE with a NULL-guard so re-running
            // never overwrites a freshly-set value.
            $this->addSql(<<<'SQL'
                UPDATE document_section
                   SET approval_role = 'ciso'
                 WHERE approval_role IS NULL
            SQL);
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_document_section_approval_role
                    ON document_section (approval_role)
            SQL);
        }

        // ── document_section.edit_locked ─────────────────────────────────
        if (!$this->columnExists('document_section', 'edit_locked')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document_section
                    ADD COLUMN edit_locked TINYINT(1) NOT NULL DEFAULT 0
            SQL);
        }

        // ── document_section.authored_by_user_id ─────────────────────────
        if (!$this->columnExists('document_section', 'authored_by_user_id')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE document_section
                    ADD COLUMN authored_by_user_id INT DEFAULT NULL
            SQL);
            $this->addSql(<<<'SQL'
                ALTER TABLE document_section
                    ADD CONSTRAINT FK_document_section_authored_by_user
                    FOREIGN KEY (authored_by_user_id) REFERENCES users (id) ON DELETE SET NULL
            SQL);
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_document_section_authored_by
                    ON document_section (authored_by_user_id)
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        // policy_template.dpo_gated_section_keys
        if ($this->columnExists('policy_template', 'dpo_gated_section_keys')) {
            $this->addSql('ALTER TABLE policy_template DROP COLUMN dpo_gated_section_keys');
        }

        // document_section.authored_by_user_id (drop FK first)
        if ($this->indexExists('document_section', 'idx_document_section_authored_by')) {
            $this->addSql('DROP INDEX idx_document_section_authored_by ON document_section');
        }
        if ($this->foreignKeyExists('document_section', 'FK_document_section_authored_by_user')) {
            $this->addSql('ALTER TABLE document_section DROP FOREIGN KEY FK_document_section_authored_by_user');
        }
        if ($this->columnExists('document_section', 'authored_by_user_id')) {
            $this->addSql('ALTER TABLE document_section DROP COLUMN authored_by_user_id');
        }

        // document_section.edit_locked
        if ($this->columnExists('document_section', 'edit_locked')) {
            $this->addSql('ALTER TABLE document_section DROP COLUMN edit_locked');
        }

        // document_section.approval_role
        if ($this->indexExists('document_section', 'idx_document_section_approval_role')) {
            $this->addSql('DROP INDEX idx_document_section_approval_role ON document_section');
        }
        if ($this->columnExists('document_section', 'approval_role')) {
            $this->addSql('ALTER TABLE document_section DROP COLUMN approval_role');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                SQL,
            [$table, $column],
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND INDEX_NAME = ?
                SQL,
            [$table, $index],
        );
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CONSTRAINT_NAME = ?
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                SQL,
            [$table, $constraint],
        );
    }
}
