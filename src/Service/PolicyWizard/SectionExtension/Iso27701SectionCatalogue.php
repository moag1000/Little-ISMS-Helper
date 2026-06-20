<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Policy-Wizard — ISO/IEC 27701:2025 (PIMS) section-extension catalogue.
 *
 * ISO/IEC 27701 is a Privacy Information Management System (PIMS) extension
 * of ISO 27001/27002. It adds privacy/PII controls to the ISMS baseline —
 * every tenant adopting 27701 already has an ISO 27001 ISMS as prerequisite
 * (ISO 27701:2025 Cl. 4.4 explicitly references ISO 27001:2022 Cl. 4-10).
 *
 * This catalogue maps ISO 27001 topic-policy keys to 27701-clause-grounded
 * body-extension prose sections. Only topics where 27701 genuinely adds
 * privacy-specific obligations beyond the ISO 27001 baseline are included.
 * Topics without meaningful PIMS extension (e.g. `network_security`,
 * `malware`, `patch_management`) return [] from sectionsForTopic().
 *
 * Grounded from:
 *   `fixtures/library/mappings/iso27701-2025_to_gdpr_v1.0.yaml`
 *   (v2, published 2026-06-12, DB-canonical requirement IDs 27701-A.7.x.x /
 *   27701-B.8.x.x) — reverse mapping from GDPR articles to 27701 clauses.
 *   `src/Command/SeedIso27701PolicyTemplatesCommand.php` — topic + clause
 *   catalogue for the 10 standalone PIMS documents.
 *
 * controlRefs: DB-canonical 27701 clause IDs used in the mapping YAML
 *   (e.g. `27701-A.7.2.1`, `27701-A.7.3.1`, `27701-B.8.2.1`)
 *   or top-level clause forms (`5.2`, `6.1.1`, `7.2`, `8.2`).
 *
 * approvalRole: `dpo` — ISO 27701 is PIMS/privacy-owned, every extension
 *   section requires DPO sign-off (Art. 38 Abs. 3 DSGVO independence +
 *   ISO 27701:2025 Cl. 5.3 DPO mandate). Confirmed accepted value via
 *   {@see \App\Entity\DocumentSection::APPROVAL_ROLE_DPO} and
 *   {@see \App\Service\PolicyWizard\GdprSectionCatalogue} which also uses 'dpo'.
 *
 * renderMode: `body_extension` — appends a PIMS prose block to the ISO topic
 *   body, same as {@see Nis2SectionCatalogue} and {@see BsiSectionCatalogue}.
 *
 * Registered automatically via the `app.policy_section_catalogue` service tag
 * (_instanceof rule in config/services.yaml).
 *
 * Standard token: `iso27701` — matches CrossCoverageCalculator's
 *   SECTION_EXTENSION_FRAMEWORK_MAP `'iso27701' => 'ISO27701'` key (pre-seeded,
 *   DO NOT modify CrossCoverageCalculator.php).
 *
 * @see StandardSectionCatalogueInterface
 * @see Nis2SectionCatalogue  reference implementation (body_extension pattern)
 * @see \App\Command\SeedIso27701PolicyTemplatesCommand  @deprecated superseded by this catalogue
 */
final class Iso27701SectionCatalogue implements StandardSectionCatalogueInterface
{
    /**
     * Static mapping iso_topic_key → section metadata.
     *
     * Covers the ISO 27001 topics where ISO 27701 PIMS genuinely adds
     * privacy-specific obligations. Topics without PIMS extension are absent —
     * sectionsForTopic() returns [] for them.
     *
     * controlRefs: DB-canonical ISO 27701 clause IDs.
     * bodyTranslationKey: policy.iso27001.<topic>.v1.iso27701_extension.body
     *   (translated in translations/policy_iso27701.{de,en}.yaml — appended
     *   to the existing silo-seeder content without touching existing keys).
     *
     * Clause-to-topic rationale (grounded from 27701-to-GDPR mapping + seeder):
     *
     *  privacy_pii       → A.7.2.1 (PII purpose), A.7.3.1 (limit collection),
     *                       A.7.4.1 (erasure policy) — core PII lifecycle
     *  information_class → A.7.2.1 (purpose doc), A.7.3.4 (PII records) —
     *                       special categories need privacy-classification overlay
     *  supplier_rels     → B.8.2.1 (processor agreement), B.8.5.3 (sub-processor
     *                       controls) — GDPR Art. 28 AVV / DPA chain
     *  access_control    → A.7.2.2 (lawful basis register), A.7.2.5 (consent +
     *                       DSR mechanism) — access must respect lawful basis
     *  incident_mgmt     → A.7.5.1 (breach notification), B.8.4.2 (notify customer) —
     *                       GDPR Art. 33/34 72h reporting
     *  hr_security       → A.7.2.4 (privacy notices to staff), A.7.2.3 (consent
     *                       in employment context), 5.3 (DPO designation)
     *  acceptable_use    → A.7.2.2 (lawful basis workplace), A.7.3.6 (erasure/correction)
     *  top_level         → 5.2 (PIMS commitment), 6.1.1 (PIMS risk assessment),
     *                       27701-6.1.2 (PIA/DPIA requirement)
     *
     * @var array<string, array{controlRefs: list<string>, bodyTranslationKey: string}>
     */
    private const array SECTIONS = [
        // Core PII lifecycle — A.7.2.1 purpose / A.7.3.1 data minimisation /
        // A.7.4.1 erasure → the most PIMS-specific topic in any ISMS
        'privacy_pii' => [
            'controlRefs' => ['27701-A.7.2.1', '27701-A.7.3.1', '27701-A.7.4.1'],
            'bodyTranslationKey' => 'policy.iso27001.privacy_pii.v1.iso27701_extension.body',
        ],
        // Information classification must overlay privacy sensitivity categories
        // (A.7.2.1 purpose docs + A.7.3.4 PII records → special categories Art. 9)
        'information_classification' => [
            'controlRefs' => ['27701-A.7.2.1', '27701-A.7.3.4'],
            'bodyTranslationKey' => 'policy.iso27001.information_classification.v1.iso27701_extension.body',
        ],
        // Supplier relationships → processor chain: AVV (B.8.2.1) + sub-processors (B.8.5.3)
        // GDPR Art. 28 — mapping pair: 27701-B.8.2.1 → GDPR-28 (equivalent)
        'supplier_relationships' => [
            'controlRefs' => ['27701-B.8.2.1', '27701-B.8.5.3'],
            'bodyTranslationKey' => 'policy.iso27001.supplier_relationships.v1.iso27701_extension.body',
        ],
        // Access control must integrate lawful basis (A.7.2.2) and DSR mechanism
        // (A.7.2.5 → GDPR Art. 15 access right), plus rectification/erasure gate
        'access_control' => [
            'controlRefs' => ['27701-A.7.2.2', '27701-A.7.2.5'],
            'bodyTranslationKey' => 'policy.iso27001.access_control.v1.iso27701_extension.body',
        ],
        // Incident management → PII breach notification chain:
        // A.7.5.1 (notify authority, 72h SLA) + B.8.4.2 (notify data subjects)
        // mapping pairs: 27701-A.7.5.1 → GDPR-33 (equivalent), 27701-B.8.4.2 → GDPR-34
        'incident_management' => [
            'controlRefs' => ['27701-A.7.5.1', '27701-B.8.4.2'],
            'bodyTranslationKey' => 'policy.iso27001.incident_management.v1.iso27701_extension.body',
        ],
        // HR security → privacy notices to employees (A.7.2.4 → GDPR Art. 13/14),
        // consent in employment context (A.7.2.3), DPO designation clause (5.3)
        'hr_security' => [
            'controlRefs' => ['27701-A.7.2.4', '27701-A.7.2.3', '27701-5.3'],
            'bodyTranslationKey' => 'policy.iso27001.hr_security.v1.iso27701_extension.body',
        ],
        // Acceptable use → lawful basis for workplace processing (A.7.2.2 → GDPR Art. 6)
        // + correction/erasure rights for employee data (A.7.3.6 → GDPR Art. 17)
        'acceptable_use' => [
            'controlRefs' => ['27701-A.7.2.2', '27701-A.7.3.6'],
            'bodyTranslationKey' => 'policy.iso27001.acceptable_use.v1.iso27701_extension.body',
        ],
        // Top-level → PIMS commitment (5.2), PIMS risk assessment (6.1.1),
        // PIA/DPIA obligation (6.1.2 → GDPR Art. 35)
        'top_level' => [
            'controlRefs' => ['27701-5.2', '27701-6.1.1', '27701-6.1.2'],
            'bodyTranslationKey' => 'policy.iso27001.top_level.v1.iso27701_extension.body',
        ],
    ];

    public function getStandard(): string
    {
        return 'iso27701';
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
                sectionKey:         'iso27701_extension',
                standard:           'iso27701',
                controlRefs:        $entry['controlRefs'],
                approvalRole:       'dpo',
                bodyTranslationKey: $entry['bodyTranslationKey'],
                renderMode:         'body_extension',
            ),
        ];
    }
}
