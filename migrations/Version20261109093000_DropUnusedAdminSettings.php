<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops admin settings that were stored but never read by any document, mail or planning
 * code (KISS: remove instead of wiring):
 *
 *   - tenant_branding: policy-doc font / cover pattern / custom CSS, report-doc cover pattern,
 *     default audience, watermark opacity, font, page orientation, chart colour scheme, custom CSS
 *   - tenant: e-mail logo URL
 *   - planning_settings: default recurrence months
 *
 * Re-runnable: only columns that still exist are dropped; down() only re-adds missing ones,
 * with the definitions of the migrations that created them.
 */
final class Version20261109093000_DropUnusedAdminSettings extends AbstractMigration
{
    /** @var array<string, array<string, string>> table => column => former definition */
    private const array COLUMNS = [
        'tenant_branding' => [
            'policy_doc_font_family' => "VARCHAR(64) DEFAULT 'Inter' NOT NULL",
            'policy_doc_cover_pattern' => "VARCHAR(32) DEFAULT 'branded' NOT NULL",
            'policy_doc_custom_css' => 'LONGTEXT DEFAULT NULL',
            'report_doc_cover_pattern' => "VARCHAR(32) DEFAULT 'branded' NOT NULL",
            'report_doc_default_audience' => "VARCHAR(16) DEFAULT 'internal' NOT NULL",
            'report_doc_watermark_opacity' => 'DOUBLE PRECISION DEFAULT 0.08 NOT NULL',
            'report_doc_font_family' => "VARCHAR(64) DEFAULT 'Inter' NOT NULL",
            'report_doc_page_orientation' => "VARCHAR(16) DEFAULT 'auto' NOT NULL",
            'report_doc_chart_color_scheme' => "VARCHAR(32) DEFAULT 'aurora' NOT NULL",
            'report_doc_custom_css' => 'LONGTEXT DEFAULT NULL',
        ],
        'tenant' => [
            'email_logo_url' => 'VARCHAR(500) DEFAULT NULL',
        ],
        'planning_settings' => [
            'default_recurrence_months' => 'INT DEFAULT 12 NOT NULL',
        ],
    ];

    public function getDescription(): string
    {
        return 'Drop admin settings that were stored but never read (policy/report style, e-mail logo URL, '
            . 'planning default recurrence)';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            $drops = [];
            foreach (array_keys($columns) as $column) {
                if ($schema->getTable($table)->hasColumn($column)) {
                    $drops[] = 'DROP COLUMN ' . $column;
                }
            }
            if ($drops !== []) {
                $this->addSql(sprintf('ALTER TABLE %s %s', $table, implode(', ', $drops)));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            $adds = [];
            foreach ($columns as $column => $definition) {
                if (!$schema->getTable($table)->hasColumn($column)) {
                    $adds[] = sprintf('ADD %s %s', $column, $definition);
                }
            }
            if ($adds !== []) {
                $this->addSql(sprintf('ALTER TABLE %s %s', $table, implode(', ', $adds)));
            }
        }
    }
}
