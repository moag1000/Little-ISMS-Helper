<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint policy-style-admin — extend `tenant_branding` with 12 columns
 * controlling per-tenant policy-document optics (font, cover pattern,
 * watermark, signature lines, TOC/history/Annex-A toggles, page margin,
 * cover logo size, custom-CSS override).
 *
 * Idempotent: each ADD COLUMN guarded against re-runs via
 * information_schema lookup (CLAUDE.md pitfall #6 — no PREPARE/EXECUTE).
 *
 * `isTransactional() = false` because every ALTER TABLE implicitly
 * commits in MySQL; running >1 DDL migration in a single migrate call
 * without this override fails on the SAVEPOINT.
 */
final class Version20260513120000_tenant_branding_policy_style extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add 12 policy-doc style columns to tenant_branding for per-tenant '
            . 'policy-document configurator (font, cover pattern, watermark, '
            . 'signature lines, toggles, margin, logo size, custom CSS).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('tenant_branding', 'policy_doc_font_family')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_font_family VARCHAR(64) NOT NULL DEFAULT 'Inter'
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_cover_pattern')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_cover_pattern VARCHAR(32) NOT NULL DEFAULT 'branded'
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_watermark_enabled')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_watermark_enabled TINYINT(1) NOT NULL DEFAULT 1
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_watermark_opacity')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_watermark_opacity DOUBLE PRECISION NOT NULL DEFAULT 0.08
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_signature_lines')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_signature_lines SMALLINT NOT NULL DEFAULT 3
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_show_toc')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_show_toc TINYINT(1) NOT NULL DEFAULT 1
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_show_history')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_show_history TINYINT(1) NOT NULL DEFAULT 1
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_show_annex_a_refs')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_show_annex_a_refs TINYINT(1) NOT NULL DEFAULT 1
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_footer_text')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_footer_text LONGTEXT DEFAULT NULL
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_cover_logo_size')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_cover_logo_size VARCHAR(16) NOT NULL DEFAULT 'medium'
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_page_margin')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_page_margin VARCHAR(16) NOT NULL DEFAULT 'standard'
            SQL);
        }
        if (!$this->columnExists('tenant_branding', 'policy_doc_custom_css')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tenant_branding
                    ADD COLUMN policy_doc_custom_css LONGTEXT DEFAULT NULL
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'policy_doc_custom_css',
            'policy_doc_page_margin',
            'policy_doc_cover_logo_size',
            'policy_doc_footer_text',
            'policy_doc_show_annex_a_refs',
            'policy_doc_show_history',
            'policy_doc_show_toc',
            'policy_doc_signature_lines',
            'policy_doc_watermark_opacity',
            'policy_doc_watermark_enabled',
            'policy_doc_cover_pattern',
            'policy_doc_font_family',
        ] as $col) {
            if ($this->columnExists('tenant_branding', $col)) {
                $this->addSql(sprintf('ALTER TABLE tenant_branding DROP COLUMN %s', $col));
            }
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
}
