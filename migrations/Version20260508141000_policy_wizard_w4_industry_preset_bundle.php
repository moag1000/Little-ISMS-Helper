<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W4-B — IndustryPresetBundle as first-class entity.
 *
 * Adds the `industry_preset_bundle` table. Bundles are global catalogue
 * rows (no `tenant_id`) that pre-fill the wizard's Step 1 + Step 4
 * with sector-specific defaults. v1 ships four bundles via
 * {@see \App\Command\SeedIndustryPresetBundlesCommand}: Healthcare,
 * Public-Sector / KRITIS, B2C-SaaS, OT / IEC 62443.
 *
 * Plain SQL only (no PREPARE/EXECUTE — see CLAUDE.md pitfall #6).
 * isTransactional() = false because CREATE TABLE commits implicitly
 * which would invalidate Doctrine's per-migration SAVEPOINT.
 */
final class Version20260508141000_policy_wizard_w4_industry_preset_bundle extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W4-B: add industry_preset_bundle table for sector-specific wizard pre-fills.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS industry_preset_bundle (
                id INT AUTO_INCREMENT NOT NULL,
                bundle_key VARCHAR(50) NOT NULL,
                label VARCHAR(200) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                standard VARCHAR(32) NOT NULL DEFAULT 'iso27001',
                preselected_standards JSON NOT NULL,
                default_risk_appetite_tier SMALLINT NOT NULL DEFAULT 3,
                default_data_classification_levels SMALLINT NOT NULL DEFAULT 3,
                default_backup_rpo_hours SMALLINT NOT NULL DEFAULT 24,
                default_patch_sla_critical_hours SMALLINT NOT NULL DEFAULT 72,
                annex_a_applicability_overrides JSON NOT NULL,
                topic_audience_overrides JSON NOT NULL,
                dpo_sections_auto_enabled TINYINT(1) NOT NULL DEFAULT 0,
                regulatory_references JSON NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                version INT NOT NULL DEFAULT 1,
                UNIQUE INDEX uq_industry_preset_bundle_key (bundle_key),
                INDEX idx_industry_preset_bundle_active (is_active),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS industry_preset_bundle');
    }
}
