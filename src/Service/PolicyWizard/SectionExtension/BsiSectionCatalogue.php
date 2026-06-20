<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Policy-Wizard — BSI IT-Grundschutz section-extension catalogue.
 *
 * Extends the ISO 27001 baseline topic policies with BSI IT-Grundschutz
 * Baustein-specific prose sections when a tenant has adopted both ISO 27001
 * and BSI IT-Grundschutz.
 *
 * Grounded from:
 *   `fixtures/library/mappings/bsi-it-grundschutz_to_iso27001-2022_v1.0.yaml`
 *   (v2, 2024-12-01, 4-expert consensus, 106 Bausteine).
 *
 * controlRefs use BSI Baustein root IDs (e.g. `ORP.4`, `CON.1`, `DER.2.1`)
 * matching the linkedBsiBausteine scheme in SeedBsiPolicyTemplatesCommand.
 *
 * renderMode: 'body_extension' — appends a prose block to the ISO topic body,
 * same as {@see Nis2SectionCatalogue}.
 *
 * Registered automatically via the `app.policy_section_catalogue` service tag
 * (_instanceof rule in config/services.yaml).
 *
 * @see StandardSectionCatalogueInterface
 * @see Nis2SectionCatalogue  reference implementation
 * @see \App\Command\SeedBsiPolicyTemplatesCommand  @deprecated superseded by this catalogue
 */
final class BsiSectionCatalogue implements StandardSectionCatalogueInterface
{
    /**
     * Static mapping iso_topic_key → section metadata.
     *
     * Covers the ISO topics where BSI IT-Grundschutz genuinely adds
     * Baustein-specific requirements beyond the ISO 27001 baseline.
     * Topics without meaningful BSI extension (e.g. information_classification,
     * project_management, mobile_device) are absent — sectionsForTopic()
     * returns [] for them.
     *
     * controlRefs: BSI Baustein root IDs.
     * bodyTranslationKey: policy.iso27001.<topic>.v1.bsi_extension.body
     *   (translated in translations/policy_bsi.{de,en}.yaml)
     *
     * @var array<string, array{controlRefs: list<string>, bodyTranslationKey: string}>
     */
    private const array SECTIONS = [
        // ISMS.1 — Sicherheitsmanagement → top-level policy + asset management
        'top_level' => [
            'controlRefs' => ['ISMS.1'],
            'bodyTranslationKey' => 'policy.iso27001.top_level.v1.bsi_extension.body',
        ],
        'asset_management' => [
            'controlRefs' => ['ISMS.1'],
            'bodyTranslationKey' => 'policy.iso27001.asset_management.v1.bsi_extension.body',
        ],
        // ORP.4 — Identitäts- und Berechtigungsmanagement → access control + identity + authentication
        'access_control' => [
            'controlRefs' => ['ORP.4'],
            'bodyTranslationKey' => 'policy.iso27001.access_control.v1.bsi_extension.body',
        ],
        'identity_management' => [
            'controlRefs' => ['ORP.4'],
            'bodyTranslationKey' => 'policy.iso27001.identity_management.v1.bsi_extension.body',
        ],
        'authentication_information' => [
            'controlRefs' => ['ORP.4'],
            'bodyTranslationKey' => 'policy.iso27001.authentication_information.v1.bsi_extension.body',
        ],
        // ORP.2 — Personal → HR security
        'hr_security' => [
            'controlRefs' => ['ORP.2'],
            'bodyTranslationKey' => 'policy.iso27001.hr_security.v1.bsi_extension.body',
        ],
        // CON.1 — Kryptokonzept → cryptography
        'cryptography' => [
            'controlRefs' => ['CON.1'],
            'bodyTranslationKey' => 'policy.iso27001.cryptography.v1.bsi_extension.body',
        ],
        // CON.2 — Datenschutz → privacy/PII
        'privacy_pii' => [
            'controlRefs' => ['CON.2'],
            'bodyTranslationKey' => 'policy.iso27001.privacy_pii.v1.bsi_extension.body',
        ],
        // CON.3 — Datensicherungskonzept → backup
        'backup' => [
            'controlRefs' => ['CON.3'],
            'bodyTranslationKey' => 'policy.iso27001.backup.v1.bsi_extension.body',
        ],
        // CON.8 — Software-Entwicklung → secure_development
        'secure_development' => [
            'controlRefs' => ['CON.8'],
            'bodyTranslationKey' => 'policy.iso27001.secure_development.v1.bsi_extension.body',
        ],
        // CON.9 — Informationsaustausch → information_transfer
        'information_transfer' => [
            'controlRefs' => ['CON.9'],
            'bodyTranslationKey' => 'policy.iso27001.information_transfer.v1.bsi_extension.body',
        ],
        // OPS.1.1.3 — Patch- und Änderungsmanagement → patch_management
        'patch_management' => [
            'controlRefs' => ['OPS.1.1.3'],
            'bodyTranslationKey' => 'policy.iso27001.patch_management.v1.bsi_extension.body',
        ],
        // OPS.1.1.4 — Schutz vor Schadprogrammen → malware
        'malware' => [
            'controlRefs' => ['OPS.1.1.4'],
            'bodyTranslationKey' => 'policy.iso27001.malware.v1.bsi_extension.body',
        ],
        // OPS.1.1.5 — Protokollierung → logging
        'logging' => [
            'controlRefs' => ['OPS.1.1.5'],
            'bodyTranslationKey' => 'policy.iso27001.logging.v1.bsi_extension.body',
        ],
        // OPS.2.3 — Nutzung von Outsourcing → supplier_relationships
        'supplier_relationships' => [
            'controlRefs' => ['OPS.2.3'],
            'bodyTranslationKey' => 'policy.iso27001.supplier_relationships.v1.bsi_extension.body',
        ],
        // DER.1 — Detektion → threat_intelligence
        'threat_intelligence' => [
            'controlRefs' => ['DER.1'],
            'bodyTranslationKey' => 'policy.iso27001.threat_intelligence.v1.bsi_extension.body',
        ],
        // DER.2.1 — Behandlung von Sicherheitsvorfällen → incident_management
        'incident_management' => [
            'controlRefs' => ['DER.2.1'],
            'bodyTranslationKey' => 'policy.iso27001.incident_management.v1.bsi_extension.body',
        ],
        // DER.4 — Notfallmanagement → continuity
        'continuity' => [
            'controlRefs' => ['DER.4'],
            'bodyTranslationKey' => 'policy.iso27001.continuity.v1.bsi_extension.body',
        ],
        // NET.1.1 — Netzarchitektur → network_security
        'network_security' => [
            'controlRefs' => ['NET.1.1'],
            'bodyTranslationKey' => 'policy.iso27001.network_security.v1.bsi_extension.body',
        ],
        // INF.1 — Allgemeines Gebäude → physical_security
        'physical_security' => [
            'controlRefs' => ['INF.1'],
            'bodyTranslationKey' => 'policy.iso27001.physical_security.v1.bsi_extension.body',
        ],
    ];

    public function getStandard(): string
    {
        return 'bsi';
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
                sectionKey:         'bsi_extension',
                standard:           'bsi',
                controlRefs:        $entry['controlRefs'],
                approvalRole:       'ciso',
                bodyTranslationKey: $entry['bodyTranslationKey'],
                renderMode:         'body_extension',
            ),
        ];
    }
}
