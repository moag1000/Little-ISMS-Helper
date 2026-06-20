<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Policy-Wizard — BSI C5:2020 section-extension catalogue.
 *
 * Extends the ISO 27001 baseline topic policies with BSI C5:2020
 * cloud-security criteria-specific prose sections when a tenant has
 * adopted both ISO 27001 and BSI C5:2020.
 *
 * Grounded from:
 *   `fixtures/library/mappings/bsi-c5-2020_to_iso27001-2022_v1.0.yaml`
 *   (v1.0, consensus mapping, 114 C5 criteria).
 *
 * controlRefs use BSI C5 criteria IDs (e.g. `OPS-01`, `SIM-01`, `CRY-01`)
 * matching the C5 criteria namespace used by CrossCoverageCalculator
 * (SECTION_EXTENSION_FRAMEWORK_MAP key: 'BSI-C5').
 *
 * renderMode: 'body_extension' — appends a prose block to the ISO topic body,
 * same as {@see BsiSectionCatalogue} and {@see Nis2SectionCatalogue}.
 *
 * Registered automatically via the `app.policy_section_catalogue` service tag
 * (_instanceof rule in config/services.yaml).
 *
 * @see StandardSectionCatalogueInterface
 * @see BsiSectionCatalogue  reference implementation (IT-Grundschutz Bausteine)
 * @see \App\Command\SeedC5PolicyTemplatesCommand  @deprecated superseded by this catalogue
 */
final class C5SectionCatalogue implements StandardSectionCatalogueInterface
{
    /**
     * Static mapping iso_topic_key → section metadata.
     *
     * Covers the ISO topics where BSI C5:2020 genuinely adds cloud-specific
     * criteria beyond the ISO 27001 baseline.
     * Topics without meaningful C5 extension (e.g. information_classification,
     * project_management, mobile_device, network_security, physical_security,
     * secure_development) are absent — sectionsForTopic() returns [] for them.
     *
     * controlRefs: BSI C5 criteria IDs.
     * bodyTranslationKey: policy.iso27001.<topic>.v1.c5_extension.body
     *   (translated in translations/policy_c5.{de,en}.yaml)
     *
     * @var array<string, array{controlRefs: list<string>, bodyTranslationKey: string}>
     */
    private const array SECTIONS = [
        // OPS-01 — Leitlinie zur Informationssicherheit → top-level policy
        'top_level' => [
            'controlRefs' => ['OPS-01'],
            'bodyTranslationKey' => 'policy.iso27001.top_level.v1.c5_extension.body',
        ],
        // AM-01 — Asset-Inventar → asset management
        'asset_management' => [
            'controlRefs' => ['AM-01'],
            'bodyTranslationKey' => 'policy.iso27001.asset_management.v1.c5_extension.body',
        ],
        // SSO-01 / SSO-04 — Subdienstleister-Steuerung / Sub-Provider-Audit → supplier relationships
        'supplier_relationships' => [
            'controlRefs' => ['SSO-01', 'SSO-04'],
            'bodyTranslationKey' => 'policy.iso27001.supplier_relationships.v1.c5_extension.body',
        ],
        // OIS-05 — Identifikation von Schwachstellen und Bedrohungen → threat intelligence
        'threat_intelligence' => [
            'controlRefs' => ['OIS-05'],
            'bodyTranslationKey' => 'policy.iso27001.threat_intelligence.v1.c5_extension.body',
        ],
        // SIM-01 — Richtlinien zur Behandlung von Sicherheitsvorfällen → incident management
        'incident_management' => [
            'controlRefs' => ['SIM-01'],
            'bodyTranslationKey' => 'policy.iso27001.incident_management.v1.c5_extension.body',
        ],
        // BCM-01 — Business-Continuity-Strategie → continuity
        'continuity' => [
            'controlRefs' => ['BCM-01'],
            'bodyTranslationKey' => 'policy.iso27001.continuity.v1.c5_extension.body',
        ],
        // HR-03 — Schulung und Sensibilisierung → HR security
        'hr_security' => [
            'controlRefs' => ['HR-03'],
            'bodyTranslationKey' => 'policy.iso27001.hr_security.v1.c5_extension.body',
        ],
        // IDM-02 / IDM-09 — Benutzerregistrierung + Authentifizierungsmechanismen → access control
        'access_control' => [
            'controlRefs' => ['IDM-02', 'IDM-09'],
            'bodyTranslationKey' => 'policy.iso27001.access_control.v1.c5_extension.body',
        ],
        // IDM-02 — Benutzerregistrierung / Identity Lifecycle → identity management
        'identity_management' => [
            'controlRefs' => ['IDM-02'],
            'bodyTranslationKey' => 'policy.iso27001.identity_management.v1.c5_extension.body',
        ],
        // IDM-09 — Authentifizierungsmechanismen (MFA, phishing-resistant) → authentication information
        'authentication_information' => [
            'controlRefs' => ['IDM-09'],
            'bodyTranslationKey' => 'policy.iso27001.authentication_information.v1.c5_extension.body',
        ],
        // OPS-15 — Schutz vor Schadprogrammen → malware
        'malware' => [
            'controlRefs' => ['OPS-15'],
            'bodyTranslationKey' => 'policy.iso27001.malware.v1.c5_extension.body',
        ],
        // OPS-18 — Umgang mit Schwachstellen → patch management
        'patch_management' => [
            'controlRefs' => ['OPS-18'],
            'bodyTranslationKey' => 'policy.iso27001.patch_management.v1.c5_extension.body',
        ],
        // OPS-10 — Protokollierung und Monitoring → logging
        'logging' => [
            'controlRefs' => ['OPS-10'],
            'bodyTranslationKey' => 'policy.iso27001.logging.v1.c5_extension.body',
        ],
        // CRY-01 — Verschlüsselungsrichtlinie und Schlüsselverwaltung → cryptography
        'cryptography' => [
            'controlRefs' => ['CRY-01'],
            'bodyTranslationKey' => 'policy.iso27001.cryptography.v1.c5_extension.body',
        ],
    ];

    public function getStandard(): string
    {
        return 'c5';
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
                sectionKey:         'c5_extension',
                standard:           'c5',
                controlRefs:        $entry['controlRefs'],
                approvalRole:       'ciso',
                bodyTranslationKey: $entry['bodyTranslationKey'],
                renderMode:         'body_extension',
            ),
        ];
    }
}
