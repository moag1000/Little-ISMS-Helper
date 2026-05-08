<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\DocumentSection;

/**
 * Policy-Wizard W6-C — GDPR section catalogue.
 *
 * Per `docs/plans/policy-wizard/06-dpo-input.md` §0 Decision Matrix v2,
 * the DPO addon contributes 8 sections + 2 appendices that MERGE into
 * existing ISO 27001 host policies (rather than spawning standalone
 * privacy documents). The 5 genuinely standalone privacy artefacts
 * (DPO Charter, RoPA Methodology, DPIA Methodology, DSR Procedure,
 * Retention Schedule) are seeded by W6-B's
 * {@see \App\Command\SeedPrivacyPolicyTemplatesCommand} and are NOT
 * represented here.
 *
 * The catalogue is consulted by {@see DocumentGenerator} when the
 * tenant adopted both ISO 27001 AND GDPR scope: every ISO host policy
 * whose topic appears here grows one or more privacy `DocumentSection`
 * rows, each carrying its own `approval_role` per §0.A split-state
 * machine (CISO / DPO / JOINT). The DPO can then sign off / reject the
 * privacy section independently of the host CISO-owned content via
 * {@see PolicySectionApprovalService} (W6-A).
 *
 * Mechanically identical to {@see DoraExtensionCatalogue} (W4-A): a
 * static lookup driven by ISO topic key. The difference is that the
 * DORA addon appends prose `## DORA-Erweiterung` blocks to the rendered
 * body, whereas the GDPR addon emits per-section `DocumentSection`
 * rows that drive the per-section approval gate.
 *
 * Sections (10 total per §0 Decision Matrix v2):
 *
 *  | ISO Topic               | Section Key                       | GDPR Article            | Approval Role |
 *  |-------------------------|-----------------------------------|-------------------------|---------------|
 *  | acceptable_use          | gdpr_lawful_basis_workplace       | Art. 6, 9               | dpo           |
 *  | awareness_training      | gdpr_dpo_mandate                  | Art. 37-39              | dpo           |
 *  | information_classification | gdpr_special_categories       | Art. 9                  | joint         |
 *  | secure_development      | gdpr_privacy_by_design            | Art. 25                 | joint         |
 *  | secure_development      | gdpr_ai_systems                   | Art. 22 + EU AI Act     | dpo           |
 *  | supplier_security       | gdpr_joint_controllers            | Art. 26                 | dpo           |
 *  | information_transfer    | gdpr_international_transfers      | Art. 44-49 (Schrems II) | dpo           |
 *  | asset_management        | gdpr_retention_minimisation       | Art. 5(1)(c)+(e)        | joint         |
 *  | incident_management     | gdpr_breach_72h                   | Art. 33                 | dpo           |
 *  | physical_security       | gdpr_premises_processing          | Art. 32                 | ciso          |
 */
final class GdprSectionCatalogue
{
    /**
     * Catalogue rows. Each entry binds an ISO topic key to a unique
     * section key + GDPR articles + approval role per §0 v2.
     *
     * @var list<array{
     *   iso_topic: string,
     *   section_key: string,
     *   gdpr_articles: list<string>,
     *   approval_role: string,
     * }>
     */
    public const array SECTIONS = [
        [
            'iso_topic'     => 'acceptable_use',
            'section_key'   => 'gdpr_lawful_basis_workplace',
            'gdpr_articles' => ['Art. 6', 'Art. 9'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_DPO,
        ],
        [
            'iso_topic'     => 'awareness_training',
            'section_key'   => 'gdpr_dpo_mandate',
            'gdpr_articles' => ['Art. 37', 'Art. 38', 'Art. 39'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_DPO,
        ],
        [
            'iso_topic'     => 'information_classification',
            'section_key'   => 'gdpr_special_categories',
            'gdpr_articles' => ['Art. 9'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_JOINT,
        ],
        [
            'iso_topic'     => 'secure_development',
            'section_key'   => 'gdpr_privacy_by_design',
            'gdpr_articles' => ['Art. 25'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_JOINT,
        ],
        [
            'iso_topic'     => 'secure_development',
            'section_key'   => 'gdpr_ai_systems',
            'gdpr_articles' => ['Art. 22', 'EU AI Act'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_DPO,
        ],
        [
            'iso_topic'     => 'supplier_security',
            'section_key'   => 'gdpr_joint_controllers',
            'gdpr_articles' => ['Art. 26'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_DPO,
        ],
        [
            'iso_topic'     => 'information_transfer',
            'section_key'   => 'gdpr_international_transfers',
            'gdpr_articles' => ['Art. 44', 'Art. 45', 'Art. 46', 'Art. 47', 'Art. 48', 'Art. 49'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_DPO,
        ],
        [
            'iso_topic'     => 'asset_management',
            'section_key'   => 'gdpr_retention_minimisation',
            'gdpr_articles' => ['Art. 5(1)(c)', 'Art. 5(1)(e)'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_JOINT,
        ],
        [
            'iso_topic'     => 'incident_management',
            'section_key'   => 'gdpr_breach_72h',
            'gdpr_articles' => ['Art. 33'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_DPO,
        ],
        [
            'iso_topic'     => 'physical_security',
            'section_key'   => 'gdpr_premises_processing',
            'gdpr_articles' => ['Art. 32'],
            'approval_role' => DocumentSection::APPROVAL_ROLE_CISO,
        ],
    ];

    /**
     * Lookup helper. Returns every catalogue row whose `iso_topic`
     * matches. A topic may carry multiple sections (e.g. `secure_development`
     * gets both `gdpr_privacy_by_design` and `gdpr_ai_systems`).
     *
     * @return list<array{
     *   iso_topic: string,
     *   section_key: string,
     *   gdpr_articles: list<string>,
     *   approval_role: string,
     * }>
     */
    public function getSectionsFor(string $isoTopic): array
    {
        $hits = [];
        foreach (self::SECTIONS as $row) {
            if ($row['iso_topic'] === $isoTopic) {
                $hits[] = $row;
            }
        }
        return $hits;
    }

    /**
     * Total number of catalogue rows. Used by tests + sanity-check
     * assertions that the catalogue stays aligned with §0 Decision
     * Matrix v2 (10 entries).
     */
    public function count(): int
    {
        return count(self::SECTIONS);
    }

    /**
     * @return list<array{
     *   iso_topic: string,
     *   section_key: string,
     *   gdpr_articles: list<string>,
     *   approval_role: string,
     * }>
     */
    public function all(): array
    {
        return self::SECTIONS;
    }
}
