<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\IndustryPresetBundle;
use App\Repository\IndustryPresetBundleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Policy-Wizard Sprint W4-B — seed the IndustryPresetBundles.
 *
 * Idempotent: existing bundles (matched by `key`) are updated in place;
 * new ones are inserted. Bundles seeded:
 *
 *   1. Healthcare           — ISO 27001 + GDPR, very conservative,
 *                             RPO 4h, DPO sections auto, A.5.34/A.5.18.
 *   2. Public-Sector/KRITIS — ISO 27001 + BSI IT-Grundschutz, very
 *                             conservative.
 *   3. B2C-SaaS             — ISO 27001 + GDPR, balanced, RPO 24h.
 *   4. OT / IEC 62443       — ISO 27001 + IEC 62443, very conservative,
 *                             physical-heavy A.7.1-A.7.14 applicable.
 *   5. Custom / Generic     — ISO 27001 only, balanced, no industry
 *                             assumptions (Junior-ISB fallback).
 *   6. DE Mittelstand NIS2  — ISO 27001 + NIS2 + GDPR, conservative.
 *                             Industrial / B2B mid-market firms now
 *                             in scope of the NIS2UmsuCG thresholds.
 *   7. BaFin DORA + MaRisk  — ISO 27001 + DORA + GDPR + BCM,
 *                             very conservative; replaces VAIT/BAIT.
 *   8. KRITIS Energie       — ISO 27001 + BSI IT-Grundschutz + NIS2
 *                             + BCM, very conservative; BSI-KritisV +
 *                             EnWG § 11 Abs. 1b cybersecurity baseline.
 *
 * Spec: docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md §3 W4.
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-bundles',
    description: 'Seed the IndustryPresetBundles (Healthcare, Public-Sector, B2C-SaaS, OT/IEC 62443, Custom/Generic, DE-Mittelstand NIS2, BaFin DORA+MaRisk-AT, KRITIS Energie).',
)]
final class SeedIndustryPresetBundlesCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IndustryPresetBundleRepository $repository,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $stats = $this->seed();
        $io->success(sprintf(
            'IndustryPresetBundles: %d created, %d updated.',
            $stats['created'],
            $stats['updated'],
        ));

        return Command::SUCCESS;
    }

    /**
     * Idempotent seeding logic — exposed for testability.
     *
     * @return array{created: int, updated: int}
     */
    public function seed(): array
    {
        $stats = ['created' => 0, 'updated' => 0];

        foreach ($this->definitions() as $def) {
            $existing = $this->repository->findByKey($def['key']);
            $bundle = $existing ?? new IndustryPresetBundle();
            $bundle
                ->setKey($def['key'])
                ->setLabel($def['label'])
                ->setDescription($def['description'])
                ->setStandard($def['standard'])
                ->setPreselectedStandards($def['preselected_standards'])
                ->setDefaultRiskAppetiteTier($def['default_risk_appetite_tier'])
                ->setDefaultDataClassificationLevels($def['default_data_classification_levels'])
                ->setDefaultBackupRpoHours($def['default_backup_rpo_hours'])
                ->setDefaultPatchSlaCriticalHours($def['default_patch_sla_critical_hours'])
                ->setAnnexAApplicabilityOverrides($def['annex_a_applicability_overrides'])
                ->setTopicAudienceOverrides($def['topic_audience_overrides'])
                ->setDpoSectionsAutoEnabled($def['dpo_sections_auto_enabled'])
                ->setRegulatoryReferences($def['regulatory_references'])
                ->setIsActive(true)
                ->setVersion($def['version']);

            if ($existing === null) {
                $this->entityManager->persist($bundle);
                $stats['created']++;
            } else {
                $stats['updated']++;
            }
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * The four v1 bundle blueprints. Kept here (not in fixtures) so
     * Phase-4-C can iterate without YAML round-trips.
     *
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     standard: string,
     *     preselected_standards: list<string>,
     *     default_risk_appetite_tier: int,
     *     default_data_classification_levels: int,
     *     default_backup_rpo_hours: int,
     *     default_patch_sla_critical_hours: int,
     *     annex_a_applicability_overrides: array<string, string>,
     *     topic_audience_overrides: array<string, list<string>>,
     *     dpo_sections_auto_enabled: bool,
     *     regulatory_references: list<string>,
     *     version: int,
     * }>
     */
    private function definitions(): array
    {
        return [
            [
                'key' => IndustryPresetBundle::KEY_HEALTHCARE,
                'label' => 'Healthcare / Patient Records',
                'description' => 'Hospitals, MVZ, medical practices and digital-health providers handling '
                    . 'patient data. Defaults assume ISO 27001 + GDPR baseline with very conservative risk '
                    . 'appetite, 4-hour backup RPO and DPO sections auto-enabled.',
                'standard' => IndustryPresetBundle::STANDARD_ISO_GDPR,
                'preselected_standards' => ['iso27001', 'gdpr'],
                'default_risk_appetite_tier' => 1,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 4,
                'default_patch_sla_critical_hours' => 24,
                'annex_a_applicability_overrides' => [
                    'A.5.34' => 'applicable', // Privacy & PII protection
                    'A.5.18' => 'applicable', // Access rights
                ],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => true,
                'regulatory_references' => [
                    '§ 22 BDSG',
                    '§ 203 StGB',
                    'Patient Records Act',
                ],
                'version' => 1,
            ],
            [
                'key' => IndustryPresetBundle::KEY_PUBLIC_SECTOR,
                'label' => 'Public Sector / KRITIS',
                'description' => 'Federal, state and municipal authorities plus KRITIS operators following '
                    . 'BSI IT-Grundschutz. Defaults assume ISO 27001 + BSI baseline with very conservative '
                    . 'risk appetite.',
                'standard' => IndustryPresetBundle::STANDARD_ISO_BSI,
                'preselected_standards' => ['iso27001', 'bsi'],
                'default_risk_appetite_tier' => 1,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 12,
                'default_patch_sla_critical_hours' => 48,
                'annex_a_applicability_overrides' => [],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => false,
                'regulatory_references' => [
                    'BSIG § 8a',
                    'BSI 200-1/2/3',
                    'OZG',
                ],
                'version' => 1,
            ],
            [
                'key' => IndustryPresetBundle::KEY_B2C_SAAS,
                'label' => 'B2C-SaaS',
                'description' => 'Consumer-facing SaaS providers handling end-user accounts and personal '
                    . 'data. Defaults assume ISO 27001 + GDPR baseline with balanced risk appetite and '
                    . '24-hour backup RPO.',
                'standard' => IndustryPresetBundle::STANDARD_ISO_GDPR,
                'preselected_standards' => ['iso27001', 'gdpr'],
                'default_risk_appetite_tier' => 3,
                'default_data_classification_levels' => 3,
                'default_backup_rpo_hours' => 24,
                'default_patch_sla_critical_hours' => 72,
                'annex_a_applicability_overrides' => [],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => true,
                'regulatory_references' => [
                    'GDPR',
                    'ePrivacy / TTDSG',
                    'CCPA',
                ],
                'version' => 1,
            ],
            [
                'key' => IndustryPresetBundle::KEY_OT_IEC62443,
                'label' => 'OT / IEC 62443 (Industrial Control Systems)',
                'description' => 'Operational-technology environments — production lines, SCADA, PLCs and '
                    . 'industrial-IoT — aligned with IEC 62443 and NIS2. Defaults assume ISO 27001 baseline '
                    . 'with very conservative risk appetite and physical-heavy controls.',
                'standard' => IndustryPresetBundle::STANDARD_ISO_ALL,
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
                'regulatory_references' => [
                    'IEC 62443',
                    'NIS2',
                    'BSI ICS',
                ],
                'version' => 1,
            ],
            [
                'key' => IndustryPresetBundle::KEY_CUSTOM_GENERAL,
                'label' => 'Allgemein / Custom',
                'description' => 'Generic preset without industry assumptions — you fill Steps 4 and 5 manually. '
                    . 'Pre-selects only the mandatory ISO 27001 baseline; risk appetite stays balanced (tier 3) '
                    . 'and no sector-specific Annex A overrides are applied.',
                'standard' => IndustryPresetBundle::STANDARD_ISO27001,
                'preselected_standards' => ['iso27001'],
                'default_risk_appetite_tier' => 3,
                'default_data_classification_levels' => 3,
                'default_backup_rpo_hours' => 24,
                'default_patch_sla_critical_hours' => 72,
                'annex_a_applicability_overrides' => [],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => false,
                'regulatory_references' => [],
                'version' => 1,
            ],
            // ── Compliance-Manager-Persona feedback (May 2026) ──────────
            // Three additional industry presets that pull the now-pickable
            // NIS-2 / DORA / BSI-C5 mapping catalogues into ready-to-go
            // sector bundles. Mappings already exist via
            // SeedNis2Iso27001MappingsCommand / SeedDoraIso27001MappingsCommand
            // / SeedC52026Iso27001MappingsCommand — these presets close the
            // sales-blocker by surfacing them in Step 1 of the Policy-Wizard.
            [
                'key' => IndustryPresetBundle::KEY_DE_MITTELSTAND_NIS2,
                'label' => 'DE-Mittelstand (NIS-2-pflichtig)',
                'description' => 'Mid-market industrial / B2B-service companies in Germany that fall '
                    . 'under the NIS2UmsuCG thresholds (essential / important entity per § 28 BSIG-neu). '
                    . 'Defaults assume ISO 27001 + NIS-2 + GDPR baseline with conservative risk appetite '
                    . 'and 12-hour backup RPO.',
                'standard' => IndustryPresetBundle::STANDARD_ISO_ALL,
                'preselected_standards' => ['iso27001', 'nis2', 'gdpr'],
                'default_risk_appetite_tier' => 2,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 12,
                'default_patch_sla_critical_hours' => 48,
                'annex_a_applicability_overrides' => [
                    'A.5.7' => 'applicable',  // Threat intelligence
                    'A.5.24' => 'applicable', // Incident management planning
                    'A.5.25' => 'applicable', // Assessment / decision on incidents
                    'A.5.26' => 'applicable', // Response to incidents
                    'A.5.27' => 'applicable', // Learning from incidents
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
                'key' => IndustryPresetBundle::KEY_BAFIN_DORA_MARISK_AT,
                'label' => 'BaFin (DORA + MaRisk AT 11.2)',
                'description' => 'BaFin-supervised financial institutions — banks, insurers, payment / '
                    . 'e-money service providers, capital management companies. DORA (EU 2022/2554) is the '
                    . 'lex specialis since Jan 2025 and replaces VAIT / BAIT / KAIT / ZAIT; MaRisk AT 11.2 '
                    . 'continues to apply for outsourcing governance. Defaults assume ISO 27001 + DORA + '
                    . 'GDPR + BCM baseline, very conservative risk appetite, 4-hour backup RPO.',
                'standard' => IndustryPresetBundle::STANDARD_ISO_ALL,
                'preselected_standards' => ['iso27001', 'dora', 'gdpr', 'bcm'],
                'default_risk_appetite_tier' => 1,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 4,
                'default_patch_sla_critical_hours' => 24,
                'annex_a_applicability_overrides' => [
                    'A.5.19' => 'applicable', // Information security in supplier relationships
                    'A.5.20' => 'applicable', // Addressing infosec within supplier agreements
                    'A.5.21' => 'applicable', // Managing infosec in the ICT supply chain
                    'A.5.22' => 'applicable', // Monitoring & review of supplier services
                    'A.5.23' => 'applicable', // Information security for use of cloud services
                    'A.5.30' => 'applicable', // ICT readiness for business continuity
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
                'key' => IndustryPresetBundle::KEY_KRITIS_ENERGIE,
                'label' => 'KRITIS Energie (BSI-KritisV + EnWG § 11.1b)',
                'description' => 'KRITIS sector "Energie" operators — electricity, gas, district heating, '
                    . 'mineral oil supply — under BSI-KritisV thresholds. Combines BSI IT-Grundschutz, '
                    . 'NIS-2 (EU 2022/2555) and the EnWG § 11 Abs. 1b cybersecurity baseline (IT-Sicher'
                    . 'heitskatalog Strom/Gas). Defaults assume ISO 27001 + BSI + NIS-2 + BCM, very '
                    . 'conservative risk appetite, 4-hour backup RPO and physical-heavy controls.',
                'standard' => IndustryPresetBundle::STANDARD_ISO_ALL,
                'preselected_standards' => ['iso27001', 'bsi', 'nis2', 'bcm'],
                'default_risk_appetite_tier' => 1,
                'default_data_classification_levels' => 4,
                'default_backup_rpo_hours' => 4,
                'default_patch_sla_critical_hours' => 24,
                'annex_a_applicability_overrides' => [
                    'A.5.7' => 'applicable',  // Threat intelligence
                    'A.5.30' => 'applicable', // ICT readiness for business continuity
                    'A.7.1' => 'applicable',  // Physical security perimeters
                    'A.7.2' => 'applicable',  // Physical entry
                    'A.7.4' => 'applicable',  // Physical security monitoring
                    'A.8.14' => 'applicable', // Redundancy of information processing facilities
                    'A.8.16' => 'applicable', // Monitoring activities
                ],
                'topic_audience_overrides' => [],
                'dpo_sections_auto_enabled' => false,
                'regulatory_references' => [
                    'BSI-KritisV',
                    'BSIG § 8a / § 8b',
                    'EnWG § 11 Abs. 1b',
                    'IT-Sicherheitskatalog Strom/Gas',
                    'EU 2022/2555 (NIS-2)',
                ],
                'version' => 1,
            ],
        ];
    }
}
