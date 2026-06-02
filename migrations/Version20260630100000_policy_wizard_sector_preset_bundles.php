<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard W4-B — seed the 7 SECTOR IndustryPresetBundles.
 *
 * Background: the parent table (Version20260508141000) ships empty and the
 * `custom_general` fallback row is seeded by Version20260509020000. The
 * remaining 7 sector bundles (Healthcare, Public-Sector/KRITIS, B2C-SaaS,
 * OT/IEC 62443, DE-Mittelstand NIS-2, BaFin DORA+MaRisk, KRITIS Energie)
 * were only ever inserted by the manual `app:policy-wizard:seed-bundles`
 * command — which is NOT part of setup/migrate. Result: a fresh deploy (or
 * any DB where the command was never run) shows only "Allgemein / Custom"
 * in the Wizard Step-1 industry-preset dropdown.
 *
 * This migration closes that gap: it seeds all 7 sector bundles so every
 * `doctrine:migrations:migrate` run makes them available, exactly like the
 * custom_general row. The seeder command remains the authoritative source
 * and updates these rows in-place (matched by bundle_key) when it runs.
 *
 * Data is a verbatim copy of SeedIndustryPresetBundlesCommand::definitions()
 * (minus custom_general). Idempotent via INSERT ... SELECT ... WHERE NOT
 * EXISTS keyed on bundle_key.
 *
 * Plain SQL only (no PREPARE/EXECUTE — CLAUDE.md pitfall #6).
 * isTransactional() = false for consistency with the W4-B table migrations
 * (INSERT commits implicitly there).
 */
final class Version20260630100000_policy_wizard_sector_preset_bundles extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard: seed the 7 sector IndustryPresetBundles so the Step-1 dropdown is complete on every deploy.';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->bundles() as $b) {
            $this->addSql($this->buildInsert($b));
        }
    }

    public function down(Schema $schema): void
    {
        $keys = array_map(
            fn (array $b): string => "'" . $b['key'] . "'",
            $this->bundles(),
        );
        $this->addSql(
            'DELETE FROM industry_preset_bundle WHERE bundle_key IN (' . implode(', ', $keys) . ')',
        );
    }

    /**
     * @param array{
     *     key: string, label: string, description: string, standard: string,
     *     preselected_standards: list<string>, default_risk_appetite_tier: int,
     *     default_data_classification_levels: int, default_backup_rpo_hours: int,
     *     default_patch_sla_critical_hours: int,
     *     annex_a_applicability_overrides: array<string, string>,
     *     topic_audience_overrides: array<string, list<string>>,
     *     dpo_sections_auto_enabled: bool, regulatory_references: list<string>,
     *     version: int
     * } $b
     */
    private function buildInsert(array $b): string
    {
        $key = $this->q($b['key']);

        return sprintf(
            <<<'SQL'
                INSERT INTO industry_preset_bundle
                    (bundle_key, label, description, standard, preselected_standards,
                     default_risk_appetite_tier, default_data_classification_levels,
                     default_backup_rpo_hours, default_patch_sla_critical_hours,
                     annex_a_applicability_overrides, topic_audience_overrides,
                     dpo_sections_auto_enabled, regulatory_references,
                     is_active, version)
                SELECT %s, %s, %s, %s, %s, %d, %d, %d, %d, %s, %s, %d, %s, 1, %d
                WHERE NOT EXISTS (
                    SELECT 1 FROM industry_preset_bundle WHERE bundle_key = %s
                )
                SQL,
            $key,
            $this->q($b['label']),
            $this->q($b['description']),
            $this->q($b['standard']),
            $this->jsonArray($b['preselected_standards']),
            $b['default_risk_appetite_tier'],
            $b['default_data_classification_levels'],
            $b['default_backup_rpo_hours'],
            $b['default_patch_sla_critical_hours'],
            $this->jsonObject($b['annex_a_applicability_overrides']),
            $this->jsonObject($b['topic_audience_overrides']),
            $b['dpo_sections_auto_enabled'] ? 1 : 0,
            $this->jsonArray($b['regulatory_references']),
            $b['version'],
            $key,
        );
    }

    /** Single-quote + escape a SQL string literal. */
    private function q(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Build a JSON_ARRAY(...) SQL expression (empty → JSON_ARRAY()).
     *
     * @param list<string> $items
     */
    private function jsonArray(array $items): string
    {
        if ($items === []) {
            return 'JSON_ARRAY()';
        }
        return 'JSON_ARRAY(' . implode(', ', array_map($this->q(...), $items)) . ')';
    }

    /**
     * Build a JSON_OBJECT(...) SQL expression (empty → JSON_OBJECT()).
     * Values that are arrays (topic-audience lists) become nested JSON_ARRAY.
     *
     * @param array<string, string|list<string>> $map
     */
    private function jsonObject(array $map): string
    {
        if ($map === []) {
            return 'JSON_OBJECT()';
        }
        $parts = [];
        foreach ($map as $k => $v) {
            $parts[] = $this->q((string) $k);
            $parts[] = is_array($v) ? $this->jsonArray($v) : $this->q($v);
        }
        return 'JSON_OBJECT(' . implode(', ', $parts) . ')';
    }

    /**
     * The 7 sector bundles — verbatim from
     * SeedIndustryPresetBundlesCommand::definitions() (minus custom_general,
     * which Version20260509020000 already seeds).
     *
     * @return list<array{
     *     key: string, label: string, description: string, standard: string,
     *     preselected_standards: list<string>, default_risk_appetite_tier: int,
     *     default_data_classification_levels: int, default_backup_rpo_hours: int,
     *     default_patch_sla_critical_hours: int,
     *     annex_a_applicability_overrides: array<string, string>,
     *     topic_audience_overrides: array<string, list<string>>,
     *     dpo_sections_auto_enabled: bool, regulatory_references: list<string>,
     *     version: int
     * }>
     */
    private function bundles(): array
    {
        return [
            [
                'key' => 'healthcare',
                'label' => 'Healthcare / Patient Records',
                'description' => 'Hospitals, MVZ, medical practices and digital-health providers handling '
                    . 'patient data. Defaults assume ISO 27001 + GDPR baseline with very conservative risk '
                    . 'appetite, 4-hour backup RPO and DPO sections auto-enabled.',
                'standard' => 'iso27001+gdpr',
                'preselected_standards' => ['iso27001', 'gdpr'],
                'default_risk_appetite_tier' => 1,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 4,
                'default_patch_sla_critical_hours' => 24,
                'annex_a_applicability_overrides' => [
                    'A.5.34' => 'applicable',
                    'A.5.18' => 'applicable',
                ],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => true,
                'regulatory_references' => ['§ 22 BDSG', '§ 203 StGB', 'Patient Records Act'],
                'version' => 1,
            ],
            [
                'key' => 'public_sector',
                'label' => 'Public Sector / KRITIS',
                'description' => 'Federal, state and municipal authorities plus KRITIS operators following '
                    . 'BSI IT-Grundschutz. Defaults assume ISO 27001 + BSI baseline with very conservative '
                    . 'risk appetite.',
                'standard' => 'iso27001+bsi',
                'preselected_standards' => ['iso27001', 'bsi'],
                'default_risk_appetite_tier' => 1,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 12,
                'default_patch_sla_critical_hours' => 48,
                'annex_a_applicability_overrides' => [],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => false,
                'regulatory_references' => ['BSIG § 8a', 'BSI 200-1/2/3', 'OZG'],
                'version' => 1,
            ],
            [
                'key' => 'b2c_saas',
                'label' => 'B2C-SaaS',
                'description' => 'Consumer-facing SaaS providers handling end-user accounts and personal '
                    . 'data. Defaults assume ISO 27001 + GDPR baseline with balanced risk appetite and '
                    . '24-hour backup RPO.',
                'standard' => 'iso27001+gdpr',
                'preselected_standards' => ['iso27001', 'gdpr'],
                'default_risk_appetite_tier' => 3,
                'default_data_classification_levels' => 3,
                'default_backup_rpo_hours' => 24,
                'default_patch_sla_critical_hours' => 72,
                'annex_a_applicability_overrides' => [],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => true,
                'regulatory_references' => ['GDPR', 'ePrivacy / TTDSG', 'CCPA'],
                'version' => 1,
            ],
            [
                'key' => 'ot_iec62443',
                'label' => 'OT / IEC 62443 (Industrial Control Systems)',
                'description' => 'Operational-technology environments — production lines, SCADA, PLCs and '
                    . 'industrial-IoT — aligned with IEC 62443 and NIS2. Defaults assume ISO 27001 baseline '
                    . 'with very conservative risk appetite and physical-heavy controls.',
                'standard' => 'iso27001+all',
                'preselected_standards' => ['iso27001', 'iec62443'],
                'default_risk_appetite_tier' => 1,
                'default_data_classification_levels' => 3,
                'default_backup_rpo_hours' => 8,
                'default_patch_sla_critical_hours' => 168,
                'annex_a_applicability_overrides' => [
                    'A.7.1' => 'applicable',
                    'A.7.2' => 'applicable',
                    'A.7.3' => 'applicable',
                    'A.7.4' => 'applicable',
                    'A.7.5' => 'applicable',
                    'A.7.6' => 'applicable',
                    'A.7.7' => 'applicable',
                    'A.7.8' => 'applicable',
                    'A.7.9' => 'applicable',
                    'A.7.10' => 'applicable',
                    'A.7.11' => 'applicable',
                    'A.7.12' => 'applicable',
                    'A.7.13' => 'applicable',
                    'A.7.14' => 'applicable',
                ],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => false,
                'regulatory_references' => ['IEC 62443', 'NIS2', 'BSI ICS'],
                'version' => 1,
            ],
            [
                'key' => 'de_mittelstand_nis2',
                'label' => 'DE-Mittelstand (NIS-2-pflichtig)',
                'description' => 'Mid-market industrial / B2B-service companies in Germany that fall '
                    . 'under the NIS2UmsuCG thresholds (essential / important entity per § 28 BSIG-neu). '
                    . 'Defaults assume ISO 27001 + NIS-2 + GDPR baseline with conservative risk appetite '
                    . 'and 12-hour backup RPO.',
                'standard' => 'iso27001+all',
                'preselected_standards' => ['iso27001', 'nis2', 'gdpr'],
                'default_risk_appetite_tier' => 2,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 12,
                'default_patch_sla_critical_hours' => 48,
                'annex_a_applicability_overrides' => [
                    'A.5.7' => 'applicable',
                    'A.5.24' => 'applicable',
                    'A.5.25' => 'applicable',
                    'A.5.26' => 'applicable',
                    'A.5.27' => 'applicable',
                ],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => true,
                'regulatory_references' => [
                    'EU 2022/2555 (NIS-2)',
                    'NIS2UmsuCG',
                    'BSIG § 28 ff. (neu)',
                    'GDPR / BDSG',
                ],
                'version' => 1,
            ],
            [
                'key' => 'bafin_dora_marisk_at',
                'label' => 'BaFin (DORA + MaRisk AT 11.2)',
                'description' => 'BaFin-supervised financial institutions — banks, insurers, payment / '
                    . 'e-money service providers, capital management companies. DORA (EU 2022/2554) is the '
                    . 'lex specialis since Jan 2025 and replaces VAIT / BAIT / KAIT / ZAIT; MaRisk AT 11.2 '
                    . 'continues to apply for outsourcing governance. Defaults assume ISO 27001 + DORA + '
                    . 'GDPR + BCM baseline, very conservative risk appetite, 4-hour backup RPO.',
                'standard' => 'iso27001+all',
                'preselected_standards' => ['iso27001', 'dora', 'gdpr', 'bcm'],
                'default_risk_appetite_tier' => 1,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 4,
                'default_patch_sla_critical_hours' => 24,
                'annex_a_applicability_overrides' => [
                    'A.5.19' => 'applicable',
                    'A.5.20' => 'applicable',
                    'A.5.21' => 'applicable',
                    'A.5.22' => 'applicable',
                    'A.5.23' => 'applicable',
                    'A.5.30' => 'applicable',
                ],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => true,
                'regulatory_references' => [
                    'EU 2022/2554 (DORA)',
                    'MaRisk AT 11.2',
                    'KWG § 25a/b',
                    'GDPR / BDSG',
                ],
                'version' => 1,
            ],
            [
                'key' => 'kritis_energie',
                'label' => 'KRITIS Energie (BSI-KritisV + EnWG § 11.1b)',
                'description' => 'KRITIS sector "Energie" operators — electricity, gas, district heating, '
                    . 'mineral oil supply — under BSI-KritisV thresholds. Combines BSI IT-Grundschutz, '
                    . 'NIS-2 (EU 2022/2555) and the EnWG § 11 Abs. 1b cybersecurity baseline (IT-Sicher'
                    . 'heitskatalog Strom/Gas). Defaults assume ISO 27001 + BSI + NIS-2 + BCM, very '
                    . 'conservative risk appetite, 4-hour backup RPO and physical-heavy controls.',
                'standard' => 'iso27001+all',
                'preselected_standards' => ['iso27001', 'bsi', 'kritis', 'nis2', 'bcm'],
                'default_risk_appetite_tier' => 1,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 4,
                'default_patch_sla_critical_hours' => 24,
                'annex_a_applicability_overrides' => [
                    'A.5.7' => 'applicable',
                    'A.5.30' => 'applicable',
                    'A.7.1' => 'applicable',
                    'A.7.2' => 'applicable',
                    'A.7.4' => 'applicable',
                    'A.8.14' => 'applicable',
                    'A.8.16' => 'applicable',
                ],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => false,
                'regulatory_references' => [
                    'BSI-KritisV',
                    'BSIG § 8a / § 8b',
                    'EnWG § 11 Abs. 1b',
                    'IT-Sicherheitskatalog Strom/Gas',
                    'EU 2022/2555 (NIS-2)',
                    'B3S Energie',
                ],
                'version' => 1,
            ],
        ];
    }
}
