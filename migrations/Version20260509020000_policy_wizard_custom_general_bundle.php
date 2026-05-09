<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard May-2026 follow-up — Junior-ISB-friendly defaults.
 *
 * Adds the 5th IndustryPresetBundle row, `custom_general`. This row is
 * the safe fallback for users who do not match any of the four sector
 * presets (Healthcare / Public-Sector / B2C-SaaS / OT-IEC62443) — it
 * pre-selects only the mandatory ISO 27001 baseline and keeps risk
 * appetite + RPO/SLA at neutral defaults so the user fills Step 4+5
 * manually without sector assumptions.
 *
 * INSERT IF NOT EXISTS keeps the migration idempotent — the seeder
 * command can later update the row in-place via UPSERT semantics.
 *
 * Plain SQL only (no PREPARE/EXECUTE — see CLAUDE.md pitfall #6).
 * isTransactional() = false because INSERT ... commits implicitly when
 * the table itself was created in a non-transactional migration; we
 * keep the same posture for consistency with the W4-B migration that
 * created the parent table.
 */
final class Version20260509020000_policy_wizard_custom_general_bundle extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard: seed the custom_general IndustryPresetBundle (Junior-ISB fallback).';
    }

    public function up(Schema $schema): void
    {
        // Idempotent insert via INSERT ... SELECT ... WHERE NOT EXISTS.
        // The seeder command (`app:policy-wizard:seed-bundles`) is the
        // authoritative source for bundle data and will update this row
        // in-place when it runs; this migration only guarantees the row
        // exists so a fresh deploy without seeder-run still has the
        // bundle available in the wizard's Step-1 dropdown.
        $this->addSql(<<<'SQL'
            INSERT INTO industry_preset_bundle
                (bundle_key, label, description, standard, preselected_standards,
                 default_risk_appetite_tier, default_data_classification_levels,
                 default_backup_rpo_hours, default_patch_sla_critical_hours,
                 annex_a_applicability_overrides, topic_audience_overrides,
                 dpo_sections_auto_enabled, regulatory_references,
                 is_active, version)
            SELECT
                'custom_general',
                'Allgemein / Custom',
                'Generic preset without industry assumptions — you fill Steps 4 and 5 manually. Pre-selects only the mandatory ISO 27001 baseline; risk appetite stays balanced (tier 3) and no sector-specific Annex A overrides are applied.',
                'iso27001',
                JSON_ARRAY('iso27001'),
                3, 3, 24, 72,
                JSON_OBJECT(),
                JSON_OBJECT(),
                0,
                JSON_ARRAY(),
                1, 1
            WHERE NOT EXISTS (
                SELECT 1 FROM industry_preset_bundle WHERE bundle_key = 'custom_general'
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM industry_preset_bundle WHERE bundle_key = 'custom_general'");
    }
}
