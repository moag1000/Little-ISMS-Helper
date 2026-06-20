<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Policy-Wizard — ISO 22301:2019 BCMS section-extension catalogue.
 *
 * Extends the ISO 27001 baseline topic policies with ISO 22301-specific prose
 * sections when a tenant has adopted both ISO 27001 and BCM (token 'bcm').
 *
 * Grounded from ISO 22301:2019 (BCMS requirements) and ISO 22313:2020 (guidance),
 * mapped against the BCM entity model (`BusinessContinuityPlan`, `BCExercise`,
 * `CrisisTeam`, `BusinessProcess`) in `src/Entity/`.
 *
 * ISO 22301 extends the CONTINUITY-related ISO 27001 topics primarily:
 *  - `continuity`           → 8.2 (BIA), 8.3 (strategy), 8.4 (plans), 8.5 (exercising)
 *  - `backup`               → 8.4/8.3 (recovery / continuity backup strategy)
 *  - `incident_management`  → 8.4.2/8.4.3 (incident response structure / BC activation)
 *  - `top_level`            → 5.2 (BC policy), 5.1 (leadership commitment)
 *  - `supplier_relationships` → 8.3 (resource/supplier dependencies in BC strategy)
 *
 * Topics with no BCM addition (e.g. `cryptography`, `network_security`,
 * `information_classification`) return [] — no ISO 22301 clause is meaningfully
 * added beyond what ISO 27001 already requires.
 *
 * controlRefs use ISO 22301:2019 clause numbers (e.g. '8.2', '8.3', '8.4', '5.2')
 * matching the `ISO-22301` framework in CrossCoverageCalculator::FRAMEWORK_DEFAULTS
 * (total 30 heuristic requirements). The framework code is 'ISO-22301', mapped via
 * CrossCoverageCalculator::SECTION_EXTENSION_FRAMEWORK_MAP['bcm'].
 *
 * renderMode: 'body_extension' — appends a prose block to the ISO 27001 topic body,
 * same as {@see BsiSectionCatalogue} and {@see Nis2SectionCatalogue}.
 *
 * Registered automatically via the `app.policy_section_catalogue` service tag
 * (_instanceof rule in config/services.yaml).
 *
 * @see StandardSectionCatalogueInterface
 * @see BsiSectionCatalogue  reference implementation (same render mode)
 * @see \App\Command\SeedBcmPolicyTemplatesCommand  @deprecated superseded by this catalogue
 */
final class BcmSectionCatalogue implements StandardSectionCatalogueInterface
{
    /**
     * Static mapping iso_topic_key → section metadata.
     *
     * Covers the ISO 27001 topics where ISO 22301 genuinely adds
     * BCM-specific requirements beyond the ISO 27001 baseline.
     * Topics without meaningful BCM extension are absent — sectionsForTopic()
     * returns [] for them.
     *
     * controlRefs: ISO 22301:2019 clause numbers.
     * bodyTranslationKey: policy.iso27001.<topic>.v1.bcm_extension.body
     *   (translated in translations/policy_bcm.{de,en}.yaml)
     *
     * @var array<string, array{controlRefs: list<string>, bodyTranslationKey: string}>
     */
    private const array SECTIONS = [
        // Cl. 5.1 + 5.2 — Leadership commitment + BC policy → top-level policy
        'top_level' => [
            'controlRefs' => ['5.1', '5.2'],
            'bodyTranslationKey' => 'policy.iso27001.top_level.v1.bcm_extension.body',
        ],
        // Cl. 8.2 (BIA) + 8.3 (strategy) + 8.4 (plans/procedures) + 8.5 (exercising)
        // → the core BCMS operational clauses; continuity topic is the primary BCM topic
        'continuity' => [
            'controlRefs' => ['8.2', '8.3', '8.4', '8.5'],
            'bodyTranslationKey' => 'policy.iso27001.continuity.v1.bcm_extension.body',
        ],
        // Cl. 8.4 (BC plan procedures include backup/restore sequences)
        // + 8.3 (BC strategy includes recovery of data assets)
        'backup' => [
            'controlRefs' => ['8.3', '8.4'],
            'bodyTranslationKey' => 'policy.iso27001.backup.v1.bcm_extension.body',
        ],
        // Cl. 8.4.2 (incident response structure in BC plans)
        // + 8.4.3 (warning and communication procedures / continuity activation)
        'incident_management' => [
            'controlRefs' => ['8.4'],
            'bodyTranslationKey' => 'policy.iso27001.incident_management.v1.bcm_extension.body',
        ],
        // Cl. 8.3 — BC strategy includes supplier/resource dependencies
        // (critical supplier continuity is a mandatory BCM planning input)
        'supplier_relationships' => [
            'controlRefs' => ['8.3'],
            'bodyTranslationKey' => 'policy.iso27001.supplier_relationships.v1.bcm_extension.body',
        ],
    ];

    public function getStandard(): string
    {
        return 'bcm';
    }

    /** @return list<SectionExtension> */
    public function sectionsForTopic(string $isoTopic): array
    {
        $entry = self::SECTIONS[$isoTopic] ?? null;
        if ($entry === null) {
            return [];
        }

        return [
            new SectionExtension(
                sectionKey:         'bcm_extension',
                standard:           'bcm',
                controlRefs:        $entry['controlRefs'],
                approvalRole:       'ciso',
                bodyTranslationKey: $entry['bodyTranslationKey'],
                renderMode:         'body_extension',
            ),
        ];
    }
}
