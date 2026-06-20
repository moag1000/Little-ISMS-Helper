<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Policy-Wizard — TISAX / VDA-ISA section-extension catalogue.
 *
 * Extends the ISO 27001 baseline topic policies with TISAX-specific prose
 * sections when a tenant has adopted both ISO 27001 and TISAX.
 *
 * Grounded from:
 *   `fixtures/library/mappings/tisax_to_iso27001-2022_v1.0.yaml`
 *   (extracted from ENX VDA-ISA 6 workbook reference column; control-IDs only,
 *   question-text NOT included — ENX licensed. Total: 80 TISAX controls.)
 *
 * controlRefs use TISAX control numbers in chapter.section.item notation
 * (e.g. '1.1.1', '4.1.2', '8.1.1'). Only the control NUMBERS are referenced;
 * no VDA-ISA requirement text is reproduced.
 *
 * LICENSE NOTE: Full VDA-ISA question text is ENX-licensed and NOT shippable.
 * Only control numbers are cited as identifiers. All prose in the extension
 * sections is original, independent authorship describing the obligation
 * in general terms aligned with the TISAX chapter structure.
 *
 * TISAX chapter structure:
 *   ch.1 Information Security Policies & Organisation
 *   ch.2 Human Resources Security
 *   ch.3 Physical & Environmental Security
 *   ch.4 Identity & Access Management
 *   ch.5 IT Security Operations
 *   ch.6 Supplier Relationships
 *   ch.7 Compliance
 *   ch.8 Prototype Protection (automotive-specific, no ISO anchors)
 *   ch.9 Data Protection (automotive PII scope, no ISO anchors)
 *
 * Topics with no meaningful TISAX addition beyond ISO 27001 baseline →
 * absent from SECTIONS (sectionsForTopic() returns [] for them).
 *
 * Prototype_protection (ch.8) and privacy_pii (ch.9) are surfaced
 * via the 'prototype_protection' and 'privacy_pii' ISO topic keys
 * with their respective TISAX ch.8/ch.9 entry-point refs, since those
 * chapters have no ISO 27001:2022 anchors in the authoritative mapping
 * but are real TISAX obligations for automotive tenants.
 *
 * renderMode: 'body_extension' — appends a prose block to the ISO topic body,
 * same as {@see BsiSectionCatalogue} and {@see Nis2SectionCatalogue}.
 *
 * Registered automatically via the `app.policy_section_catalogue` service tag
 * (_instanceof rule in config/services.yaml).
 *
 * @see StandardSectionCatalogueInterface
 * @see BsiSectionCatalogue  reference implementation
 * @see \App\Command\SeedTisaxPolicyTemplatesCommand  @deprecated superseded by this catalogue
 */
final class TisaxSectionCatalogue implements StandardSectionCatalogueInterface
{
    /**
     * Static mapping iso_topic_key → section metadata.
     *
     * controlRefs: TISAX control numbers (chapter.section.item).
     * bodyTranslationKey: policy.iso27001.<topic>.v1.tisax_extension.body
     *   (translated in translations/policy_tisax.{de,en}.yaml)
     *
     * Derivation rationale per topic (from tisax_to_iso27001-2022_v1.0.yaml):
     *   top_level           ← 1.1.1→A.5.1, 1.2.2→A.5.2/A.5.3
     *   asset_management    ← 1.3.1→A.5.9, 1.3.2→A.5.10/A.5.12/A.5.13
     *   access_control      ← 4.1.1→A.5.18, 4.1.2→A.5.15/A.8.5
     *   identity_management ← 4.1.3→A.5.16/A.5.17
     *   authentication_info ← 4.1.3→A.5.17/A.8.9, 5.2.4→A.5.17/A.8.15
     *   hr_security         ← 2.1.1→A.6.1, 2.1.2→A.6.2/A.6.5
     *   physical_security   ← 3.1.1→A.7.1/A.7.6, 3.1.4→A.6.7/A.7.10/A.8.1
     *   incident_management ← 1.6.1→A.5.24/A.6.8, 1.6.2→A.5.24-A.5.27
     *   continuity          ← 1.6.3→A.5.29, 5.2.8→A.5.30
     *   backup              ← 5.2.9→A.8.13
     *   supplier_relations  ← 1.3.4→A.5.19, 6.1.1→A.5.19/A.5.22, 5.3.3→A.5.23
     *   information_transfer← 5.1.2→A.5.14, 6.1.2→A.5.14/A.6.6
     *   secure_development  ← 5.3.1→A.5.8/A.8.25, 5.2.1→A.8.32, 5.2.2→A.8.31
     *   logging             ← 5.2.4→A.8.15 (security event logging)
     *   network_security    ← 5.2.7→A.8.20/A.8.22, 5.3.2→A.8.21
     *   cryptography        ← 5.1.1→A.8.24
     *   patch_management    ← 5.2.5→A.8.8/A.8.19
     *   malware             ← 5.2.5→A.8.8/A.8.19 (shared ch.5 ops topic)
     *   threat_intelligence ← 5.2.6→A.5.36/A.8.34 (compliance monitoring)
     *   prototype_protection← ch.8 (8.1.x-8.5.x, automotive PSx — no ISO anchors)
     *   privacy_pii         ← ch.9 (9.1.1-9.8.1, automotive DSx — no ISO anchors)
     *
     * @var array<string, array{controlRefs: list<string>, bodyTranslationKey: string}>
     */
    private const array SECTIONS = [
        // ch.1 — IS-Leitlinie / Organisation → top-level policy
        'top_level' => [
            'controlRefs' => ['1.1.1', '1.2.2'],
            'bodyTranslationKey' => 'policy.iso27001.top_level.v1.tisax_extension.body',
        ],
        // ch.1 — Asset-Inventar + Klassifizierung → asset_management
        'asset_management' => [
            'controlRefs' => ['1.3.1', '1.3.2'],
            'bodyTranslationKey' => 'policy.iso27001.asset_management.v1.tisax_extension.body',
        ],
        // ch.4 — Berechtigungsvergabe + Zugriffskontrolle → access_control
        'access_control' => [
            'controlRefs' => ['4.1.1', '4.1.2'],
            'bodyTranslationKey' => 'policy.iso27001.access_control.v1.tisax_extension.body',
        ],
        // ch.4 — Identitaetsmanagement (User Lifecycle) → identity_management
        'identity_management' => [
            'controlRefs' => ['4.1.3'],
            'bodyTranslationKey' => 'policy.iso27001.identity_management.v1.tisax_extension.body',
        ],
        // ch.4 + ch.5 — Authentisierung, MFA, Passwortschutz → authentication_information
        'authentication_information' => [
            'controlRefs' => ['4.1.3', '5.2.4'],
            'bodyTranslationKey' => 'policy.iso27001.authentication_information.v1.tisax_extension.body',
        ],
        // ch.2 — Personalsicherheit → hr_security
        'hr_security' => [
            'controlRefs' => ['2.1.1', '2.1.2'],
            'bodyTranslationKey' => 'policy.iso27001.hr_security.v1.tisax_extension.body',
        ],
        // ch.3 — Physische Sicherheit + Zonenkonzept → physical_security
        'physical_security' => [
            'controlRefs' => ['3.1.1', '3.1.4'],
            'bodyTranslationKey' => 'policy.iso27001.physical_security.v1.tisax_extension.body',
        ],
        // ch.1 — Incident Response → incident_management
        'incident_management' => [
            'controlRefs' => ['1.6.1', '1.6.2'],
            'bodyTranslationKey' => 'policy.iso27001.incident_management.v1.tisax_extension.body',
        ],
        // ch.1 + ch.5 — Notfallmanagement / BCM → continuity
        'continuity' => [
            'controlRefs' => ['1.6.3', '5.2.8'],
            'bodyTranslationKey' => 'policy.iso27001.continuity.v1.tisax_extension.body',
        ],
        // ch.5 — Datensicherung → backup
        'backup' => [
            'controlRefs' => ['5.2.9'],
            'bodyTranslationKey' => 'policy.iso27001.backup.v1.tisax_extension.body',
        ],
        // ch.1 + ch.6 + ch.5 — Lieferantensicherheit → supplier_relationships
        'supplier_relationships' => [
            'controlRefs' => ['1.3.4', '6.1.1', '5.3.3'],
            'bodyTranslationKey' => 'policy.iso27001.supplier_relationships.v1.tisax_extension.body',
        ],
        // ch.5 + ch.6 — Informationstransfer / Datenweitergabe → information_transfer
        'information_transfer' => [
            'controlRefs' => ['5.1.2', '6.1.2'],
            'bodyTranslationKey' => 'policy.iso27001.information_transfer.v1.tisax_extension.body',
        ],
        // ch.5 — Sichere Entwicklung + Secure Coding → secure_development
        'secure_development' => [
            'controlRefs' => ['5.3.1', '5.2.1', '5.2.2'],
            'bodyTranslationKey' => 'policy.iso27001.secure_development.v1.tisax_extension.body',
        ],
        // ch.5 — Protokollierung + Monitoring → logging
        'logging' => [
            'controlRefs' => ['5.2.4'],
            'bodyTranslationKey' => 'policy.iso27001.logging.v1.tisax_extension.body',
        ],
        // ch.5 — Netzwerksicherheit → network_security
        'network_security' => [
            'controlRefs' => ['5.2.7', '5.3.2'],
            'bodyTranslationKey' => 'policy.iso27001.network_security.v1.tisax_extension.body',
        ],
        // ch.5 — Kryptographie → cryptography
        'cryptography' => [
            'controlRefs' => ['5.1.1'],
            'bodyTranslationKey' => 'policy.iso27001.cryptography.v1.tisax_extension.body',
        ],
        // ch.5 — Schwachstellen- und Patch-Management → patch_management
        'patch_management' => [
            'controlRefs' => ['5.2.5'],
            'bodyTranslationKey' => 'policy.iso27001.patch_management.v1.tisax_extension.body',
        ],
        // ch.5 — Schadcode-Schutz → malware
        'malware' => [
            'controlRefs' => ['5.2.5'],
            'bodyTranslationKey' => 'policy.iso27001.malware.v1.tisax_extension.body',
        ],
        // ch.1 + ch.5 — Compliance-Monitoring, Schwachstellen-Tracking → threat_intelligence
        'threat_intelligence' => [
            'controlRefs' => ['1.5.1', '5.2.6'],
            'bodyTranslationKey' => 'policy.iso27001.threat_intelligence.v1.tisax_extension.body',
        ],
        // ch.8 — Prototypenschutz (PSx) — automotive-specific, no ISO anchors
        // Surfaced on the 'prototype_protection' topic (seeded by SeedTisaxPolicyTemplatesCommand)
        'prototype_protection' => [
            'controlRefs' => ['8.1.1', '8.1.2', '8.2.1', '8.3.1', '8.4.1', '8.5.1'],
            'bodyTranslationKey' => 'policy.iso27001.prototype_protection.v1.tisax_extension.body',
        ],
        // ch.9 — Datenschutz-Anhang (DSx) — automotive PII scope, no ISO anchors
        'privacy_pii' => [
            'controlRefs' => ['9.1.1', '9.2.1', '9.5.1', '9.7.1'],
            'bodyTranslationKey' => 'policy.iso27001.privacy_pii.v1.tisax_extension.body',
        ],
    ];

    public function getStandard(): string
    {
        return 'tisax';
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
                sectionKey:         'tisax_extension',
                standard:           'tisax',
                controlRefs:        $entry['controlRefs'],
                approvalRole:       'ciso',
                bodyTranslationKey: $entry['bodyTranslationKey'],
                renderMode:         'body_extension',
            ),
        ];
    }
}
