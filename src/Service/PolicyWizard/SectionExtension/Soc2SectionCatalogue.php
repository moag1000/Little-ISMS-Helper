<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Policy-Wizard — SOC 2 Trust Services Criteria (TSC) section-extension catalogue.
 *
 * Extends the ISO 27001 baseline topic policies with AICPA Trust Services
 * Criteria (TSC 2017, revised 2022) specific prose sections when a tenant
 * has adopted both ISO 27001 and SOC 2.
 *
 * Grounded from:
 *   - AICPA Trust Services Criteria 2017 (revised 2022)
 *   - ISO/IEC 27001:2022 Annex A cross-references
 *   - {@see \App\Command\SeedSoc2PolicyTemplatesCommand} (SOC 2 template topics + norm_refs)
 *
 * controlRefs use the canonical AICPA TSC criterion ids:
 *   Common Criteria: CC1.x – CC9.x
 *   Availability:    A1.x
 *   Confidentiality: C1.x
 *   Processing Integrity: PI1.x
 *   Privacy:         P1 – P8
 *
 * Topic → TSC mapping rationale (sourced from AICPA TSC 2017 rev.2022):
 *   access_control/identity_management/authentication_information → CC6.1–CC6.3, CC6.6
 *   physical_security  → CC6.4–CC6.5
 *   logging            → CC7.2
 *   malware            → CC7.1
 *   patch_management   → CC7.1 (configuration detection/vulnerability mgmt)
 *   incident_management → CC7.3–CC7.4
 *   secure_development / network_security → CC8.1, CC6.6–CC6.7
 *   supplier_relationships → CC9.2
 *   top_level / asset_management / threat_intelligence → CC3.1–CC3.2, CC1.3
 *   continuity / backup → A1.2, A1.3
 *   cryptography       → C1.1, CC6.7
 *   privacy_pii        → P1, P4
 *   hr_security        → CC1.4
 *   information_transfer → CC6.6
 *
 * renderMode: 'body_extension' — appends a prose block to the ISO topic body,
 * same as {@see BsiSectionCatalogue} and {@see Nis2SectionCatalogue}.
 *
 * Registered automatically via the `app.policy_section_catalogue` service tag
 * (_instanceof rule in config/services.yaml).
 *
 * @see StandardSectionCatalogueInterface
 * @see Nis2SectionCatalogue  reference implementation
 * @see \App\Command\SeedSoc2PolicyTemplatesCommand  @deprecated superseded by this catalogue
 */
final class Soc2SectionCatalogue implements StandardSectionCatalogueInterface
{
    /**
     * Static mapping iso_topic_key → section metadata.
     *
     * Covers the ISO 27001 topics where SOC 2 TSC genuinely adds
     * requirements beyond the ISO 27001 baseline. Topics without meaningful
     * SOC 2 TSC extension (e.g. information_classification, project_management,
     * mobile_device) are absent — sectionsForTopic() returns [] for them.
     *
     * controlRefs: AICPA TSC criterion ids (CC#.#, A#.#, C#.#, PI#.#, P#).
     * bodyTranslationKey: policy.iso27001.<topic>.v1.soc2_extension.body
     *   (translated in translations/policy_soc2.{de,en}.yaml)
     *
     * @var array<string, array{controlRefs: list<string>, bodyTranslationKey: string}>
     */
    private const array SECTIONS = [
        // CC1.3, CC1.4, CC3.1-CC3.2 — Governance + Risk-Assessment → top-level
        'top_level' => [
            'controlRefs' => ['CC1.3', 'CC3.1', 'CC3.2'],
            'bodyTranslationKey' => 'policy.iso27001.top_level.v1.soc2_extension.body',
        ],
        // CC3.1, CC3.2 — Risk Assessment → asset_management
        'asset_management' => [
            'controlRefs' => ['CC3.2'],
            'bodyTranslationKey' => 'policy.iso27001.asset_management.v1.soc2_extension.body',
        ],
        // CC1.4 — Competence → hr_security
        'hr_security' => [
            'controlRefs' => ['CC1.4'],
            'bodyTranslationKey' => 'policy.iso27001.hr_security.v1.soc2_extension.body',
        ],
        // CC6.1-CC6.3 — Logical Access Controls → access_control
        'access_control' => [
            'controlRefs' => ['CC6.1', 'CC6.2', 'CC6.3'],
            'bodyTranslationKey' => 'policy.iso27001.access_control.v1.soc2_extension.body',
        ],
        // CC6.1-CC6.3 — Identity lifecycle → identity_management
        'identity_management' => [
            'controlRefs' => ['CC6.1', 'CC6.2', 'CC6.3'],
            'bodyTranslationKey' => 'policy.iso27001.identity_management.v1.soc2_extension.body',
        ],
        // CC6.1, CC6.6 — Authentication → authentication_information
        'authentication_information' => [
            'controlRefs' => ['CC6.1', 'CC6.6'],
            'bodyTranslationKey' => 'policy.iso27001.authentication_information.v1.soc2_extension.body',
        ],
        // CC6.4, CC6.5 — Physical Access Controls → physical_security
        'physical_security' => [
            'controlRefs' => ['CC6.4', 'CC6.5'],
            'bodyTranslationKey' => 'policy.iso27001.physical_security.v1.soc2_extension.body',
        ],
        // CC7.2 — Monitoring Activities → logging
        'logging' => [
            'controlRefs' => ['CC7.2'],
            'bodyTranslationKey' => 'policy.iso27001.logging.v1.soc2_extension.body',
        ],
        // CC7.1 — Vulnerability + Config Detection → malware
        'malware' => [
            'controlRefs' => ['CC7.1'],
            'bodyTranslationKey' => 'policy.iso27001.malware.v1.soc2_extension.body',
        ],
        // CC7.1 — Vulnerability + Config Detection → patch_management
        'patch_management' => [
            'controlRefs' => ['CC7.1'],
            'bodyTranslationKey' => 'policy.iso27001.patch_management.v1.soc2_extension.body',
        ],
        // CC7.3, CC7.4 — Event Evaluation + Incident Response → incident_management
        'incident_management' => [
            'controlRefs' => ['CC7.3', 'CC7.4'],
            'bodyTranslationKey' => 'policy.iso27001.incident_management.v1.soc2_extension.body',
        ],
        // CC3.1, CC3.2 — Risk Assessment → threat_intelligence
        'threat_intelligence' => [
            'controlRefs' => ['CC3.1', 'CC3.2'],
            'bodyTranslationKey' => 'policy.iso27001.threat_intelligence.v1.soc2_extension.body',
        ],
        // CC8.1 — Change Management → secure_development
        'secure_development' => [
            'controlRefs' => ['CC8.1'],
            'bodyTranslationKey' => 'policy.iso27001.secure_development.v1.soc2_extension.body',
        ],
        // CC6.6, CC6.7 — Restricted Logical Access → network_security
        'network_security' => [
            'controlRefs' => ['CC6.6', 'CC6.7'],
            'bodyTranslationKey' => 'policy.iso27001.network_security.v1.soc2_extension.body',
        ],
        // CC9.2 — Vendor Risk → supplier_relationships
        'supplier_relationships' => [
            'controlRefs' => ['CC9.2'],
            'bodyTranslationKey' => 'policy.iso27001.supplier_relationships.v1.soc2_extension.body',
        ],
        // A1.3 — Business Continuity → continuity
        'continuity' => [
            'controlRefs' => ['A1.3'],
            'bodyTranslationKey' => 'policy.iso27001.continuity.v1.soc2_extension.body',
        ],
        // A1.2 — Backup and Recovery → backup
        'backup' => [
            'controlRefs' => ['A1.2'],
            'bodyTranslationKey' => 'policy.iso27001.backup.v1.soc2_extension.body',
        ],
        // C1.1, CC6.7 — Confidentiality + Crypto → cryptography
        'cryptography' => [
            'controlRefs' => ['C1.1', 'CC6.7'],
            'bodyTranslationKey' => 'policy.iso27001.cryptography.v1.soc2_extension.body',
        ],
        // P1, P4 — Privacy Notice + Use/Retention → privacy_pii
        'privacy_pii' => [
            'controlRefs' => ['P1', 'P4'],
            'bodyTranslationKey' => 'policy.iso27001.privacy_pii.v1.soc2_extension.body',
        ],
        // CC6.6 — Encrypted communications / information transfer
        'information_transfer' => [
            'controlRefs' => ['CC6.6'],
            'bodyTranslationKey' => 'policy.iso27001.information_transfer.v1.soc2_extension.body',
        ],
    ];

    public function getStandard(): string
    {
        return 'soc2';
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
                sectionKey:         'soc2_extension',
                standard:           'soc2',
                controlRefs:        $entry['controlRefs'],
                approvalRole:       'ciso',
                bodyTranslationKey: $entry['bodyTranslationKey'],
                renderMode:         'body_extension',
            ),
        ];
    }
}
