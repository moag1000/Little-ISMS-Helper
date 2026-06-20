<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Policy-Wizard — NIS2 Art. 21(2) section-extension catalogue.
 *
 * Extends the ISO 27001 baseline topic policies with NIS2-specific prose
 * sections when a tenant has adopted both ISO 27001 and NIS2.
 *
 * Grounded from: `fixtures/library/mappings/nis2-art21_to_iso27001-2022_v1.0.yaml`
 * (v4, published 2026-06-13, panel-validated 4-expert consensus).
 *
 * controlRefs use the DB-canonical NIS2-ART21-X format (uppercase), matching
 * LoadNis2Art21RequirementsCommand's requirementId scheme.
 *
 * renderMode: 'body_extension' — appends a prose block to the ISO topic body,
 * same as {@see \App\Service\PolicyWizard\DoraExtensionCatalogue}.
 *
 * Registered automatically via the `app.policy_section_catalogue` service tag
 * (_instanceof rule in config/services.yaml).
 *
 * @see StandardSectionCatalogueInterface
 * @see \App\Service\PolicyWizard\DoraExtensionCatalogue  reference implementation
 */
final class Nis2SectionCatalogue implements StandardSectionCatalogueInterface
{
    /**
     * Static mapping iso_topic_key → section metadata.
     *
     * Covers the ISO topics where NIS2 Art. 21(2) genuinely adds requirements
     * beyond ISO 27001 baseline. Topics without NIS2 coverage are absent
     * (sectionsForTopic returns [] for them).
     *
     * controlRefs: NIS2-ART21-X DB-canonical form.
     * bodyTranslationKey: policy.iso27001.<topic>.v1.nis2_extension.body
     *   (translated in translations/policy_nis2.{de,en}.yaml)
     *
     * @var array<string, array{controlRefs: list<string>, bodyTranslationKey: string}>
     */
    private const array SECTIONS = [
        // Art. 21(2)(a) — risk analysis + IS-Policy → top-level policy
        'top_level' => [
            'controlRefs' => ['NIS2-ART21-A'],
            'bodyTranslationKey' => 'policy.iso27001.top_level.v1.nis2_extension.body',
        ],
        // Art. 21(2)(b) — incident handling
        'incident_management' => [
            'controlRefs' => ['NIS2-ART21-B'],
            'bodyTranslationKey' => 'policy.iso27001.incident_management.v1.nis2_extension.body',
        ],
        // Art. 21(2)(c) — business continuity, backup, crisis mgmt
        'continuity' => [
            'controlRefs' => ['NIS2-ART21-C'],
            'bodyTranslationKey' => 'policy.iso27001.continuity.v1.nis2_extension.body',
        ],
        // Art. 21(2)(c) — backup specifically (A.8.13, A.8.14)
        'backup' => [
            'controlRefs' => ['NIS2-ART21-C'],
            'bodyTranslationKey' => 'policy.iso27001.backup.v1.nis2_extension.body',
        ],
        // Art. 21(2)(d) — supply chain security
        'supplier_relationships' => [
            'controlRefs' => ['NIS2-ART21-D'],
            'bodyTranslationKey' => 'policy.iso27001.supplier_relationships.v1.nis2_extension.body',
        ],
        // Art. 21(2)(e) — security in acquisition / development / maintenance
        'secure_development' => [
            'controlRefs' => ['NIS2-ART21-E'],
            'bodyTranslationKey' => 'policy.iso27001.secure_development.v1.nis2_extension.body',
        ],
        // Art. 21(2)(f) — effectiveness assessment of cybersecurity measures
        'threat_intelligence' => [
            'controlRefs' => ['NIS2-ART21-F'],
            'bodyTranslationKey' => 'policy.iso27001.threat_intelligence.v1.nis2_extension.body',
        ],
        // Art. 21(2)(g) — cyber hygiene + training (malware + patch management)
        'malware' => [
            'controlRefs' => ['NIS2-ART21-G'],
            'bodyTranslationKey' => 'policy.iso27001.malware.v1.nis2_extension.body',
        ],
        'patch_management' => [
            'controlRefs' => ['NIS2-ART21-G'],
            'bodyTranslationKey' => 'policy.iso27001.patch_management.v1.nis2_extension.body',
        ],
        // Art. 21(2)(h) — cryptography and encryption
        'cryptography' => [
            'controlRefs' => ['NIS2-ART21-H'],
            'bodyTranslationKey' => 'policy.iso27001.cryptography.v1.nis2_extension.body',
        ],
        // Art. 21(2)(i) — HR security + access control + asset management
        'access_control' => [
            'controlRefs' => ['NIS2-ART21-I'],
            'bodyTranslationKey' => 'policy.iso27001.access_control.v1.nis2_extension.body',
        ],
        'asset_management' => [
            'controlRefs' => ['NIS2-ART21-I'],
            'bodyTranslationKey' => 'policy.iso27001.asset_management.v1.nis2_extension.body',
        ],
        'hr_security' => [
            'controlRefs' => ['NIS2-ART21-I'],
            'bodyTranslationKey' => 'policy.iso27001.hr_security.v1.nis2_extension.body',
        ],
        // Art. 21(2)(j) — MFA + secured communications
        'authentication_information' => [
            'controlRefs' => ['NIS2-ART21-J'],
            'bodyTranslationKey' => 'policy.iso27001.authentication_information.v1.nis2_extension.body',
        ],
        'identity_management' => [
            'controlRefs' => ['NIS2-ART21-J'],
            'bodyTranslationKey' => 'policy.iso27001.identity_management.v1.nis2_extension.body',
        ],
    ];

    public function getStandard(): string
    {
        return 'nis2';
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
                sectionKey:         'nis2_extension',
                standard:           'nis2',
                controlRefs:        $entry['controlRefs'],
                approvalRole:       'ciso',
                bodyTranslationKey: $entry['bodyTranslationKey'],
                renderMode:         'body_extension',
            ),
        ];
    }
}
