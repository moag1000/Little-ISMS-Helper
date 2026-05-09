<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Policy-Wizard W5-A — seed the 28 BSI Pflicht-Richtlinien + 1
 * Schutzbedarfsfeststellungs-Methode-Dokument = 29 PolicyTemplate rows.
 *
 * Source of truth: `docs/plans/policy-wizard/02-bsi-input.md` Anhang A
 * (Pflicht-Richtlinien-Set tabularised) + §4.1 (Schutzbedarfsfest-
 * stellung als separates Methoden-Dokument).
 *
 * Every row carries:
 *   • `standard='bsi'`
 *   • `bsi_tier` — basis|standard|kern (Anhang A "Pflicht-Niveau")
 *   • `linked_bsi_bausteine` — Anhang A Baustein-Anforderung anchors
 *   • `linked_annex_a_controls` — ISO 27001 Annex A cross-mapping
 *   • `review_interval_months` — Anhang A "Review" column (1J=12 / 2J=24)
 *
 * Idempotent: running twice without `--force` is a no-op for rows that
 * already exist. With `--force` existing rows are updated in place.
 * `--dry-run` reports what would change without writing.
 *
 * Run after deploy:
 *   php bin/console app:policy-wizard:seed-bsi
 *   php bin/console app:policy-wizard:seed-bsi --force
 *   php bin/console app:policy-wizard:seed-bsi --dry-run
 *
 * Mirrors {@see SeedDoraPolicyTemplatesCommand} (W4-A) in shape so
 * downstream wizard tooling can treat both standards uniformly.
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-bsi',
    description: 'Seeds the 28 BSI Pflicht-Richtlinien + 1 Schutzbedarfs-Methode-Dokument (W5-A).',
)]
final class SeedBsiPolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'bsi';

    /**
     * Canonical 29-row catalogue. Body / title translation keys follow
     * the §8.7 versioning scheme (`policy.bsi.<topic>.v1.body`); real
     * translation content is authored in W5-E.
     *
     * "Pflicht-Niveau" mapping rule: Anhang A column verbatim →
     *   "Basis"     → 'basis'
     *   "Basis+Std" → 'standard'   (covers Basis AND Standard Anforderungen)
     *   "Kern"      → 'kern'
     *
     * @var list<array{
     *     key: string,
     *     topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     title_translation_key: string,
     *     body_translation_key: string,
     *     bsi_tier: string,
     *     linked_bsi_bausteine: list<string>,
     *     linked_annex_a_controls: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: bool,
     * }>
     */
    public const array TEMPLATES = [
        // 1 — IT-Sicherheitsleitlinie (top-level Leitlinie)
        [
            'key' => 'bsi.it_security_policy',
            'topic' => 'it_security_policy',
            'document_type' => 'policy',
            'norm_ref' => 'ISMS.1.A4',
            'title_translation_key' => 'policy.bsi.it_security_policy.v1.title',
            'body_translation_key' => 'policy.bsi.it_security_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['ISMS.1.A4', 'ISMS.1.A5', 'ISMS.1.A2'],
            'linked_annex_a_controls' => ['A.5.1'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 2 — ISMS-Konzept (Sicherheitskonzept)
        [
            'key' => 'bsi.isms_concept',
            'topic' => 'isms_concept',
            'document_type' => 'programme',
            'norm_ref' => 'ISMS.1.A6',
            'title_translation_key' => 'policy.bsi.isms_concept.v1.title',
            'body_translation_key' => 'policy.bsi.isms_concept.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['ISMS.1.A6'],
            'linked_annex_a_controls' => [],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 3 — Sicherheitsorganisation
        [
            'key' => 'bsi.security_organization',
            'topic' => 'security_organization',
            'document_type' => 'policy',
            'norm_ref' => 'ISMS.1.A1',
            'title_translation_key' => 'policy.bsi.security_organization.v1.title',
            'body_translation_key' => 'policy.bsi.security_organization.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['ISMS.1.A1', 'ISMS.1.A3', 'ISMS.1.A8'],
            'linked_annex_a_controls' => ['A.5.2', 'A.5.3'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 4 — Organisationsrichtlinie
        [
            'key' => 'bsi.organization_policy',
            'topic' => 'organization_policy',
            'document_type' => 'policy',
            'norm_ref' => 'ORP.1',
            'title_translation_key' => 'policy.bsi.organization_policy.v1.title',
            'body_translation_key' => 'policy.bsi.organization_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['ORP.1.A1', 'ORP.1.A2', 'ORP.1.A3'],
            'linked_annex_a_controls' => ['A.5.4'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 5 — Personalrichtlinie
        [
            'key' => 'bsi.personnel_policy',
            'topic' => 'personnel_policy',
            'document_type' => 'policy',
            'norm_ref' => 'ORP.2',
            'title_translation_key' => 'policy.bsi.personnel_policy.v1.title',
            'body_translation_key' => 'policy.bsi.personnel_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['ORP.2.A1', 'ORP.2.A2', 'ORP.2.A3', 'ORP.2.A4', 'ORP.2.A5'],
            'linked_annex_a_controls' => ['A.6.1', 'A.6.2', 'A.6.5'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_HR', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 6 — Awareness-Richtlinie
        [
            'key' => 'bsi.awareness_policy',
            'topic' => 'awareness_policy',
            'document_type' => 'policy',
            'norm_ref' => 'ORP.3',
            'title_translation_key' => 'policy.bsi.awareness_policy.v1.title',
            'body_translation_key' => 'policy.bsi.awareness_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['ORP.3.A1', 'ORP.3.A2', 'ORP.3.A3', 'ORP.3.A4'],
            'linked_annex_a_controls' => ['A.6.3'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 7 — IAM-Richtlinie (Basis+Std)
        [
            'key' => 'bsi.iam',
            'topic' => 'iam',
            'document_type' => 'policy',
            'norm_ref' => 'ORP.4',
            'title_translation_key' => 'policy.bsi.iam.v1.title',
            'body_translation_key' => 'policy.bsi.iam.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'ORP.4.A1', 'ORP.4.A2', 'ORP.4.A3', 'ORP.4.A4', 'ORP.4.A5',
                'ORP.4.A6', 'ORP.4.A7', 'ORP.4.A8', 'ORP.4.A9', 'ORP.4.A22',
            ],
            'linked_annex_a_controls' => ['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 8 — Kryptokonzept
        [
            'key' => 'bsi.crypto_concept',
            'topic' => 'crypto_concept',
            'document_type' => 'programme',
            'norm_ref' => 'CON.1',
            'title_translation_key' => 'policy.bsi.crypto_concept.v1.title',
            'body_translation_key' => 'policy.bsi.crypto_concept.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['CON.1.A1', 'CON.1.A2', 'CON.1.A3', 'CON.1.A4', 'CON.1.A6'],
            'linked_annex_a_controls' => ['A.8.24'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 9 — Datenschutz-Richtlinie
        [
            'key' => 'bsi.privacy_policy',
            'topic' => 'privacy_policy',
            'document_type' => 'policy',
            'norm_ref' => 'CON.2',
            'title_translation_key' => 'policy.bsi.privacy_policy.v1.title',
            'body_translation_key' => 'policy.bsi.privacy_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['CON.2.A1', 'CON.2.A2'],
            'linked_annex_a_controls' => ['A.5.34'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO'],
            'dpo_section_required' => true,
        ],
        // 10 — Datensicherungskonzept
        [
            'key' => 'bsi.backup_concept',
            'topic' => 'backup_concept',
            'document_type' => 'programme',
            'norm_ref' => 'CON.3',
            'title_translation_key' => 'policy.bsi.backup_concept.v1.title',
            'body_translation_key' => 'policy.bsi.backup_concept.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['CON.3.A1', 'CON.3.A2', 'CON.3.A3', 'CON.3.A4', 'CON.3.A5', 'CON.3.A6'],
            'linked_annex_a_controls' => ['A.8.13'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM'],
            'dpo_section_required' => false,
        ],
        // 11 — Lösch-/Vernichtungsrichtlinie
        [
            'key' => 'bsi.deletion_policy',
            'topic' => 'deletion_policy',
            'document_type' => 'policy',
            'norm_ref' => 'CON.6',
            'title_translation_key' => 'policy.bsi.deletion_policy.v1.title',
            'body_translation_key' => 'policy.bsi.deletion_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['CON.6.A1', 'CON.6.A2'],
            'linked_annex_a_controls' => ['A.7.10', 'A.7.14', 'A.8.10'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO'],
            'dpo_section_required' => true,
        ],
        // 12 — Auslandsreisen-Richtlinie
        [
            'key' => 'bsi.foreign_travel_policy',
            'topic' => 'foreign_travel_policy',
            'document_type' => 'policy',
            'norm_ref' => 'CON.7',
            'title_translation_key' => 'policy.bsi.foreign_travel_policy.v1.title',
            'body_translation_key' => 'policy.bsi.foreign_travel_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['CON.7.A1', 'CON.7.A2', 'CON.7.A3', 'CON.7.A4', 'CON.7.A5', 'CON.7.A6'],
            'linked_annex_a_controls' => ['A.6.7', 'A.7.9', 'A.8.1'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_HR'],
            'dpo_section_required' => false,
        ],
        // 13 — Software-Entwicklungsrichtlinie
        [
            'key' => 'bsi.software_development_policy',
            'topic' => 'software_development_policy',
            'document_type' => 'policy',
            'norm_ref' => 'CON.8',
            'title_translation_key' => 'policy.bsi.software_development_policy.v1.title',
            'body_translation_key' => 'policy.bsi.software_development_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['CON.8.A1', 'CON.8.A2', 'CON.8.A3', 'CON.8.A4', 'CON.8.A5', 'CON.8.A6'],
            'linked_annex_a_controls' => ['A.8.25', 'A.8.26', 'A.8.27', 'A.8.28', 'A.8.29', 'A.8.30', 'A.8.31'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 14 — Informationsaustausch-Richtlinie
        [
            'key' => 'bsi.information_exchange_policy',
            'topic' => 'information_exchange_policy',
            'document_type' => 'policy',
            'norm_ref' => 'CON.9',
            'title_translation_key' => 'policy.bsi.information_exchange_policy.v1.title',
            'body_translation_key' => 'policy.bsi.information_exchange_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['CON.9.A1', 'CON.9.A2', 'CON.9.A3'],
            'linked_annex_a_controls' => ['A.5.14'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 15 — Webanwendungs-Richtlinie
        [
            'key' => 'bsi.web_application_policy',
            'topic' => 'web_application_policy',
            'document_type' => 'policy',
            'norm_ref' => 'CON.10',
            'title_translation_key' => 'policy.bsi.web_application_policy.v1.title',
            'body_translation_key' => 'policy.bsi.web_application_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['CON.10.A1', 'CON.10.A2', 'CON.10.A3', 'CON.10.A4', 'CON.10.A5'],
            'linked_annex_a_controls' => ['A.8.26'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 16 — IT-Administrations-Richtlinie
        [
            'key' => 'bsi.it_administration_policy',
            'topic' => 'it_administration_policy',
            'document_type' => 'policy',
            'norm_ref' => 'OPS.1.1.2',
            'title_translation_key' => 'policy.bsi.it_administration_policy.v1.title',
            'body_translation_key' => 'policy.bsi.it_administration_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'OPS.1.1.2.A1', 'OPS.1.1.2.A2', 'OPS.1.1.2.A3',
                'OPS.1.1.2.A4', 'OPS.1.1.2.A5', 'OPS.1.1.2.A6', 'OPS.1.1.2.A21',
            ],
            'linked_annex_a_controls' => ['A.8.2', 'A.8.18'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 17 — Patch-/Change-Mgmt-Richtlinie
        [
            'key' => 'bsi.patch_change_management_policy',
            'topic' => 'patch_change_management_policy',
            'document_type' => 'policy',
            'norm_ref' => 'OPS.1.1.3',
            'title_translation_key' => 'policy.bsi.patch_change_management_policy.v1.title',
            'body_translation_key' => 'policy.bsi.patch_change_management_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['OPS.1.1.3.A1', 'OPS.1.1.3.A2', 'OPS.1.1.3.A3', 'OPS.1.1.3.A4', 'OPS.1.1.3.A5', 'OPS.1.1.3.A6'],
            'linked_annex_a_controls' => ['A.8.8', 'A.8.9', 'A.8.32'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 18 — Schadprogramm-Schutz-Richtlinie
        [
            'key' => 'bsi.malware_protection_policy',
            'topic' => 'malware_protection_policy',
            'document_type' => 'policy',
            'norm_ref' => 'OPS.1.1.4',
            'title_translation_key' => 'policy.bsi.malware_protection_policy.v1.title',
            'body_translation_key' => 'policy.bsi.malware_protection_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'OPS.1.1.4.A1', 'OPS.1.1.4.A2', 'OPS.1.1.4.A3',
                'OPS.1.1.4.A4', 'OPS.1.1.4.A5', 'OPS.1.1.4.A6', 'OPS.1.1.4.A7',
            ],
            'linked_annex_a_controls' => ['A.8.7'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 19 — Protokollierungsrichtlinie
        [
            'key' => 'bsi.logging_policy',
            'topic' => 'logging_policy',
            'document_type' => 'policy',
            'norm_ref' => 'OPS.1.1.5',
            'title_translation_key' => 'policy.bsi.logging_policy.v1.title',
            'body_translation_key' => 'policy.bsi.logging_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['OPS.1.1.5.A1', 'OPS.1.1.5.A2', 'OPS.1.1.5.A3', 'OPS.1.1.5.A4'],
            'linked_annex_a_controls' => ['A.8.15', 'A.8.16', 'A.8.17'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO'],
            'dpo_section_required' => true,
        ],
        // 20 — SW-Test-/Freigabe-Richtlinie
        [
            'key' => 'bsi.software_test_release_policy',
            'topic' => 'software_test_release_policy',
            'document_type' => 'policy',
            'norm_ref' => 'OPS.1.1.6',
            'title_translation_key' => 'policy.bsi.software_test_release_policy.v1.title',
            'body_translation_key' => 'policy.bsi.software_test_release_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['OPS.1.1.6.A1', 'OPS.1.1.6.A2', 'OPS.1.1.6.A3', 'OPS.1.1.6.A4'],
            'linked_annex_a_controls' => ['A.8.29', 'A.8.31'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 21 — Telearbeits-Richtlinie
        [
            'key' => 'bsi.teleworking_policy',
            'topic' => 'teleworking_policy',
            'document_type' => 'policy',
            'norm_ref' => 'OPS.1.2.4',
            'title_translation_key' => 'policy.bsi.teleworking_policy.v1.title',
            'body_translation_key' => 'policy.bsi.teleworking_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['OPS.1.2.4.A1', 'OPS.1.2.4.A2', 'OPS.1.2.4.A3'],
            'linked_annex_a_controls' => ['A.6.7'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_HR'],
            'dpo_section_required' => false,
        ],
        // 22 — Fernwartungs-Richtlinie
        [
            'key' => 'bsi.remote_maintenance_policy',
            'topic' => 'remote_maintenance_policy',
            'document_type' => 'policy',
            'norm_ref' => 'OPS.1.2.5',
            'title_translation_key' => 'policy.bsi.remote_maintenance_policy.v1.title',
            'body_translation_key' => 'policy.bsi.remote_maintenance_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['OPS.1.2.5.A1', 'OPS.1.2.5.A2', 'OPS.1.2.5.A3', 'OPS.1.2.5.A4'],
            'linked_annex_a_controls' => ['A.8.21'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 23 — Cloud-Nutzungsrichtlinie
        [
            'key' => 'bsi.cloud_usage_policy',
            'topic' => 'cloud_usage_policy',
            'document_type' => 'policy',
            'norm_ref' => 'OPS.2.2',
            'title_translation_key' => 'policy.bsi.cloud_usage_policy.v1.title',
            'body_translation_key' => 'policy.bsi.cloud_usage_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['OPS.2.2.A1', 'OPS.2.2.A2', 'OPS.2.2.A3', 'OPS.2.2.A4', 'OPS.2.2.A5'],
            'linked_annex_a_controls' => ['A.5.23'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 24 — Outsourcing-/Lieferanten-Richtlinie
        [
            'key' => 'bsi.outsourcing_supplier_policy',
            'topic' => 'outsourcing_supplier_policy',
            'document_type' => 'policy',
            'norm_ref' => 'OPS.2.3',
            'title_translation_key' => 'policy.bsi.outsourcing_supplier_policy.v1.title',
            'body_translation_key' => 'policy.bsi.outsourcing_supplier_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['OPS.2.3.A1', 'OPS.2.3.A2', 'OPS.2.3.A3', 'OPS.2.3.A4', 'OPS.2.3.A5'],
            'linked_annex_a_controls' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_PROCUREMENT'],
            'dpo_section_required' => false,
        ],
        // 25 — Detektionsrichtlinie
        [
            'key' => 'bsi.detection_policy',
            'topic' => 'detection_policy',
            'document_type' => 'policy',
            'norm_ref' => 'DER.1',
            'title_translation_key' => 'policy.bsi.detection_policy.v1.title',
            'body_translation_key' => 'policy.bsi.detection_policy.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['DER.1.A1', 'DER.1.A2', 'DER.1.A3', 'DER.1.A4'],
            'linked_annex_a_controls' => ['A.8.16'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 26 — Incident-Response-Richtlinie
        [
            'key' => 'bsi.incident_response',
            'topic' => 'incident_response',
            'document_type' => 'policy',
            'norm_ref' => 'DER.2.1',
            'title_translation_key' => 'policy.bsi.incident_response.v1.title',
            'body_translation_key' => 'policy.bsi.incident_response.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['DER.2.1.A1', 'DER.2.1.A2', 'DER.2.1.A3', 'DER.2.1.A4', 'DER.2.1.A5', 'DER.2.1.A6'],
            'linked_annex_a_controls' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 27 — IT-Forensik-Richtlinie
        [
            'key' => 'bsi.it_forensics',
            'topic' => 'it_forensics',
            'document_type' => 'policy',
            'norm_ref' => 'DER.2.2',
            'title_translation_key' => 'policy.bsi.it_forensics.v1.title',
            'body_translation_key' => 'policy.bsi.it_forensics.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['DER.2.2.A1', 'DER.2.2.A2', 'DER.2.2.A3'],
            'linked_annex_a_controls' => ['A.5.28'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO'],
            'dpo_section_required' => true,
        ],
        // 28 — Notfallmanagement-Richtlinie (BSI 200-4)
        [
            'key' => 'bsi.emergency_management',
            'topic' => 'emergency_management',
            'document_type' => 'policy',
            'norm_ref' => 'DER.4',
            'title_translation_key' => 'policy.bsi.emergency_management.v1.title',
            'body_translation_key' => 'policy.bsi.emergency_management.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['DER.4.A1', 'DER.4.A2', 'DER.4.A3', 'DER.4.A4'],
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 29 — Schutzbedarfsfeststellungs-Methodik (Methode-Doku, §4.1)
        [
            'key' => 'bsi.protection_needs_methodology',
            'topic' => 'protection_needs_methodology',
            'document_type' => 'methodology',
            'norm_ref' => 'BSI 200-2 Kap. 6',
            'title_translation_key' => 'policy.bsi.protection_needs_methodology.v1.title',
            'body_translation_key' => 'policy.bsi.protection_needs_methodology.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [],
            'linked_annex_a_controls' => [],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PolicyTemplateRepository $templateRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Update existing rows in place (ON DUPLICATE KEY UPDATE semantics).',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would change without writing to the database.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach (self::TEMPLATES as $row) {
            $existing = $this->templateRepository->findOneByKey($row['key']);

            if ($existing instanceof PolicyTemplate) {
                if (!$force) {
                    $skipped++;
                    continue;
                }
                $this->applyRowToTemplate($existing, $row);
                $existing->setUpdatedAt(new DateTimeImmutable());
                if (!$dryRun) {
                    $this->entityManager->persist($existing);
                }
                $updated++;
                continue;
            }

            $template = new PolicyTemplate();
            $template->setKey($row['key']);
            $template->setStandard(self::STANDARD);
            $this->applyRowToTemplate($template, $row);
            if (!$dryRun) {
                $this->entityManager->persist($template);
            }
            $created++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'BSI PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
            $created,
            $updated,
            $skipped,
            $dryRun ? 'yes' : 'no',
            $force ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array{
     *     key: string,
     *     topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     title_translation_key: string,
     *     body_translation_key: string,
     *     bsi_tier: string,
     *     linked_bsi_bausteine: list<string>,
     *     linked_annex_a_controls: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: bool,
     * } $row
     */
    private function applyRowToTemplate(PolicyTemplate $template, array $row): void
    {
        $template->setTopic($row['topic']);
        $template->setDocumentType($row['document_type']);
        $template->setNormRef($row['norm_ref']);
        $template->setTitleTranslationKey($row['title_translation_key']);
        $template->setBodyTranslationKey($row['body_translation_key']);
        $template->setBsiTier($row['bsi_tier']);
        $template->setLinkedBsiBausteine($row['linked_bsi_bausteine']);
        // Mirror anchors into the legacy linkedBausteine field too
        // (Baustein-level only, no Anforderung suffix) so existing
        // DocumentGenerator §8.1 control linkage still resolves.
        $template->setLinkedBausteine($this->bausteineRoots($row['linked_bsi_bausteine']));
        $template->setLinkedAnnexAControls(
            $row['linked_annex_a_controls'] === [] ? null : $row['linked_annex_a_controls'],
        );
        $template->setLinkedDoraArticles(null);
        $template->setAffectedFunctions(null);
        $template->setReviewIntervalMonths($row['review_interval_months']);
        $template->setApprovalChain($row['approval_chain']);
        $template->setDpoSectionRequired($row['dpo_section_required']);
        $template->setClimateChangeWording(false);
        $template->setIsActive(true);
        $template->setVersion(1);
    }

    /**
     * Reduce a list of Baustein-Anforderung anchors (`ORP.4.A1`, `ORP.4.A2`)
     * to the unique Baustein roots (`ORP.4`). Keeps insertion order and
     * returns null when the input is empty (column allows null).
     *
     * @param list<string> $anchors
     * @return list<string>|null
     */
    private function bausteineRoots(array $anchors): ?array
    {
        $seen = [];
        foreach ($anchors as $anchor) {
            $parts = explode('.', $anchor);
            $count = count($parts);
            if ($count < 2) {
                continue;
            }
            // Strip a trailing Anforderung token if present (always Aon
            // shape — letter A followed by digits). Otherwise keep as-is.
            if ($count >= 2 && preg_match('/^A\d+$/', $parts[$count - 1]) === 1) {
                array_pop($parts);
            }
            $root = implode('.', $parts);
            if ($root === '' || isset($seen[$root])) {
                continue;
            }
            $seen[$root] = true;
        }
        $result = array_keys($seen);
        return $result === [] ? null : $result;
    }
}
