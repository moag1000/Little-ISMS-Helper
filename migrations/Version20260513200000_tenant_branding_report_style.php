<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint report-style-admin — Per-tenant Report-Doc style configuration.
 *
 * Adds 12 `report_doc_*` columns to `tenant_branding` so admins can
 * re-skin generated report documents (cover pattern, default audience,
 * watermark, exec-summary/appendix/distribution-list toggles, font,
 * page orientation, chart color scheme, footer disclaimer, custom CSS)
 * without code changes. Sister-agent's policy-style migration uses
 * timestamp 20260513120000; this one uses 20260513200000 to avoid
 * collision.
 *
 * Idempotent: each ADD COLUMN guarded via information_schema lookup so
 * partial-state DBs (e.g. dev environments where one column was added
 * manually) don't fail. Plain ALTER TABLE per CLAUDE.md pitfall #6.
 * isTransactional()=false because MySQL ALTER TABLE commits implicitly.
 */
final class Version20260513200000_tenant_branding_report_style extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Sprint report-style-admin: 12 report_doc_* style columns on tenant_branding.';
    }

    public function up(Schema $schema): void
    {
        $columns = [
            'report_doc_cover_pattern'           => "VARCHAR(32) DEFAULT 'branded' NOT NULL",
            'report_doc_default_audience'        => "VARCHAR(16) DEFAULT 'internal' NOT NULL",
            'report_doc_watermark_enabled'       => 'TINYINT(1) DEFAULT 1 NOT NULL',
            'report_doc_watermark_opacity'       => 'DOUBLE PRECISION DEFAULT 0.08 NOT NULL',
            'report_doc_show_exec_summary'       => 'TINYINT(1) DEFAULT 1 NOT NULL',
            'report_doc_show_appendix'           => 'TINYINT(1) DEFAULT 1 NOT NULL',
            'report_doc_show_distribution_list'  => 'TINYINT(1) DEFAULT 1 NOT NULL',
            'report_doc_font_family'             => "VARCHAR(64) DEFAULT 'Inter' NOT NULL",
            'report_doc_page_orientation'        => "VARCHAR(16) DEFAULT 'auto' NOT NULL",
            'report_doc_chart_color_scheme'      => "VARCHAR(32) DEFAULT 'aurora' NOT NULL",
            'report_doc_footer_disclaimer'       => 'LONGTEXT DEFAULT NULL',
            'report_doc_custom_css'              => 'LONGTEXT DEFAULT NULL',
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->columnExists('tenant_branding', $name)) {
                $this->addSql(sprintf('ALTER TABLE tenant_branding ADD %s %s', $name, $definition));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $columns = [
            'report_doc_custom_css',
            'report_doc_footer_disclaimer',
            'report_doc_chart_color_scheme',
            'report_doc_page_orientation',
            'report_doc_font_family',
            'report_doc_show_distribution_list',
            'report_doc_show_appendix',
            'report_doc_show_exec_summary',
            'report_doc_watermark_opacity',
            'report_doc_watermark_enabled',
            'report_doc_default_audience',
            'report_doc_cover_pattern',
        ];

        foreach ($columns as $name) {
            if ($this->columnExists('tenant_branding', $name)) {
                $this->addSql(sprintf('ALTER TABLE tenant_branding DROP COLUMN %s', $name));
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column],
        );
        return (int) $count > 0;
    }
}
