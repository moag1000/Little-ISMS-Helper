<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Service\PolicyWizard\SectionExtension\SectionExtension;
use App\Service\PolicyWizard\SectionExtension\StandardSectionCatalogueInterface;

/**
 * Policy-Wizard — DORA extension catalogue.
 *
 * Per `docs/plans/policy-wizard/03-dora-input.md` §10 cross-mapping the
 * DORA addon classifies its 25 mandates against the ISO 27001 baseline
 * as:
 *
 *   - 6 NEW   — standalone DORA-only PolicyTemplate rows seeded by
 *               {@see \App\Command\SeedDoraPolicyTemplatesCommand}.
 *   - 19 EXT  — appended as a `## DORA-Erweiterung (Art. X)` section
 *               to the equivalent ISO topic policy (this catalogue).
 *   - 1 REP   — Network Security (Art. 9.4) — DORA replaces the ISO
 *               A.8.20-A.8.23 set wholesale; surfaced here as a single
 *               extension entry on the `network_security` ISO topic so
 *               the generated section flags the REPLACEMENT semantics.
 *
 * The catalogue is consulted by {@see DocumentGenerator} when the
 * tenant adopted both ISO 27001 and DORA: every ISO body whose topic
 * appears here grows a translated DORA-extension section appended at
 * render time. The translation key follows
 * `policy.iso27001.<topic>.v1.dora_extension.body` (authored in the policy translation files).
 */
final class DoraExtensionCatalogue implements StandardSectionCatalogueInterface
{
    /**
     * Static mapping `iso_topic_key` → `dora_article_refs`.
     *
     * 19 ISO topics: 18 EXTENDS rows plus `network_security` as the single
     * REPLACES entry. The catalogue
     * intentionally treats REPLACES the same way mechanically (append
     * a `## DORA-Erweiterung` section to the ISO body) because the
     * tenant's SoA still tracks the same Annex A control rows; the
     * stricter wording lives in the translated section body and the
     * old ISO content is archived by DocumentGenerator's supersedes
     * path when a new version is generated.
     *
     * The standalone Risk-Appetite-Statement (Art. 6.8) is NOT included
     * here — DORA §2.2 flags it as a tangential document with no Annex
     * A anchor. It is covered by the standalone
     * `dora.ict_risk_tolerance` template instead (see
     * {@see \App\Command\SeedDoraPolicyTemplatesCommand}).
     *
     * @var array<string, list<string>>
     */
    public const array EXTENSIONS = [
        // Keys are the ISO policy topics (PolicyTemplate::topic, same as the
        // GS++/BSI catalogues); each has its prose at
        // policy.iso27001.<topic>.v1.dora_extension.body in policy_dora.*.yaml —
        // SectionExtensionBodyTest fails when a topic has no text.

        // ICT risk-management framework (Art. 5-6) extends Cl. 4-6 + A.5.1.
        'top_level' => ['Art. 5', 'Art. 6'],

        // ICT asset management (Art. 8) extends A.5.9 / A.5.10.
        'asset_management' => ['Art. 8'],

        // Identification + classification (Art. 8.1, 8.4) extends A.5.12.
        'information_classification' => ['Art. 8.1', 'Art. 8.4'],

        // Protection + prevention (Art. 9.4) extends the access, identity,
        // acceptable-use, transfer and mobile-device policies.
        'access_control' => ['Art. 9.4'],
        'identity_management' => ['Art. 9.4'],
        'acceptable_use' => ['Art. 9.4'],
        'information_transfer' => ['Art. 9.4'],
        'mobile_device' => ['Art. 9.4'],

        // Network security REPLACES A.8.20-A.8.23 (stricter).
        'network_security' => ['Art. 9.4'],

        // Cryptography extends A.8.24 (PQC + crypto-agility).
        'cryptography' => ['Art. 9.4.b'],

        // Physical + environmental extends A.7.1-A.7.14.
        'physical_security' => ['Art. 9.4'],

        // ICT project management extends A.5.8.
        'project_management' => ['Art. 9.4.f'],

        // Acquisition / development extends A.8.25-A.8.31.
        'secure_development' => ['Art. 9.4.g'],

        // Detection of anomalous activities (Art. 10) extends A.8.15/A.8.16.
        'logging' => ['Art. 10'],

        // ICT response + recovery extends A.5.29, A.5.30.
        'continuity' => ['Art. 11'],

        // Backup extends A.8.13 (segregation + restoration evidence).
        'backup' => ['Art. 12'],

        // Incident management + reporting extends A.5.24-A.5.28.
        'incident_management' => ['Art. 17', 'Art. 18', 'Art. 19', 'Art. 20', 'Art. 21', 'Art. 22', 'Art. 23'],

        // Information sharing (Art. 45) extends A.5.7.
        'threat_intelligence' => ['Art. 45'],

        // ICT third-party risk incl. exit strategy extends A.5.19-A.5.23.
        'supplier_relationships' => ['Art. 28', 'Art. 29', 'Art. 30'],
    ];

    /**
     * Lookup helper. Returns the list of DORA article refs to render
     * onto the ISO topic body, or null when no DORA extension applies
     * (e.g. ISO topic policies that have no DORA equivalent — like
     * `compliance_review` or `internal_audit_programme`).
     *
     * @return list<string>|null
     */
    public function getExtensionFor(string $isoTopic): ?array
    {
        return self::EXTENSIONS[$isoTopic] ?? null;
    }

    /**
     * Number of EXTENDS entries — used by tests + a sanity-check
     * assertion that the catalogue stays aligned with DORA §10.
     */
    public function count(): int
    {
        return count(self::EXTENSIONS);
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return self::EXTENSIONS;
    }

    // -------------------------------------------------------------------------
    // StandardSectionCatalogueInterface
    // -------------------------------------------------------------------------

    public function getStandard(): string
    {
        return 'dora';
    }

    /**
     * Wraps the DORA extension entry for the given ISO topic in a single
     * {@see SectionExtension} DTO, or returns an empty list when no extension
     * applies (mirrors the null-vs-empty distinction in {@see getExtensionFor()}).
     *
     * The `sectionKey` is fixed to `'dora_extension'` because DORA appends a
     * single prose block (`## DORA-Erweiterung`) rather than N discrete
     * {@see \App\Entity\DocumentSection} rows.
     *
     * The `bodyTranslationKey` follows this convention:
     * `policy.iso27001.<topic>.v1.dora_extension.body`
     * (v1 placeholder — consumers that need a version-aware key should call
     * {@see getExtensionFor()} directly and build the key from the template
     * version, as {@see \App\Service\PolicyWizard\DocumentGenerator} does).
     *
     * @return list<SectionExtension>
     */
    public function sectionsForTopic(string $isoTopic): array
    {
        $articles = $this->getExtensionFor($isoTopic);
        if ($articles === null) {
            return [];
        }

        return [
            new SectionExtension(
                sectionKey:         'dora_extension',
                standard:           'dora',
                controlRefs:        $articles,
                approvalRole:       'ciso',
                bodyTranslationKey: 'policy.iso27001.' . $isoTopic . '.v1.dora_extension.body',
                renderMode:         'body_extension',
            ),
        ];
    }
}
