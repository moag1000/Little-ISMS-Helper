<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * C-09 fix: Backfill NULL `tenant_branding.policy_doc_watermark_opacity` and
 * `tenant_branding.report_doc_watermark_opacity` to the entity default (0.08)
 * and re-pin the NOT NULL DEFAULT 0.08 constraint.
 *
 * Background:
 *   `doctrine:schema:update --dump-sql` produced a recurring
 *   `ALTER TABLE tenant_branding CHANGE policy_doc_watermark_opacity ...
 *    DOUBLE PRECISION DEFAULT 0.08 NOT NULL` diff. On instances that received
 *   the column via an earlier migration that allowed NULL (or where a manual
 *   schema-reconcile relaxed the column), the existing rows can hold NULL and
 *   the next `ALTER` then fails with `Invalid use of NULL value`. Pre-flighting
 *   the UPDATE makes the ALTER deterministic.
 *
 * `isTransactional() = false` because every ALTER TABLE implicitly commits in
 * MySQL — see CLAUDE.md Pitfall #6.
 */
final class Version20260604100000_tenant_branding_watermark_null_backfill extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Backfill NULL policy_doc_watermark_opacity / report_doc_watermark_opacity '
            . 'to 0.08 then re-pin the NOT NULL DEFAULT 0.08 constraint on tenant_branding.';
    }

    public function up(Schema $schema): void
    {
        // 1) Data-fix: backfill NULL rows before the constraint tighten. Guarded
        //    by columnExists() so the migration is a no-op on schemas that
        //    never had the column (fresh installs use the consolidated DDL).
        if ($this->columnExists('tenant_branding', 'policy_doc_watermark_opacity')) {
            $this->addSql(<<<'SQL'
                UPDATE tenant_branding
                   SET policy_doc_watermark_opacity = 0.08
                 WHERE policy_doc_watermark_opacity IS NULL
            SQL);
        }
        if ($this->columnExists('tenant_branding', 'report_doc_watermark_opacity')) {
            $this->addSql(<<<'SQL'
                UPDATE tenant_branding
                   SET report_doc_watermark_opacity = 0.08
                 WHERE report_doc_watermark_opacity IS NULL
            SQL);
        }

        // 2) DDL-fix: re-pin the entity-declared constraint so the schema and
        //    Doctrine metadata agree (eliminates the recurring diff).
        if ($this->columnExists('tenant_branding', 'policy_doc_watermark_opacity')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    MODIFY policy_doc_watermark_opacity DOUBLE PRECISION NOT NULL DEFAULT 0.08
            SQL);
        }
        if ($this->columnExists('tenant_branding', 'report_doc_watermark_opacity')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    MODIFY report_doc_watermark_opacity DOUBLE PRECISION NOT NULL DEFAULT 0.08
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        // No-op: relaxing the NOT NULL again would re-introduce the data-loss
        // window this migration closes. Backfilled rows stay at 0.08.
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
        return (int) $row > 0;
    }
}
