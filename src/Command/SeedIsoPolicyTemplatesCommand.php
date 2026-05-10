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
 * Policy-Wizard ISO 27001 seed — fills the ISO 27002:2022 24-topic
 * catalogue plus 1 cross-cutting top-level template (ISO 27001 Cl. 5.2)
 * = 25 PolicyTemplate rows for `standard='iso27001'`.
 *
 * Source of truth for the 24 topic-keys:
 *   {@see \App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardTopicCatalogue::ISO27001_TOPICS}
 *
 * Source of truth for Annex-A mapping:
 *   `docs/plans/policy-wizard/01-iso27001-input.md` §2 (per-topic
 *   "Linked Annex A" lists) + §3 (cross-mapping table).
 *
 * Translation keys point at the existing
 *   `translations/policy_iso27001.{de,en}.yaml` + `_batch{2,3,4}` files
 * (no new translation content authored — the seed wires existing
 * translations to PolicyTemplate rows). Where the catalogue topic-key
 * differs from the historical translation-key (e.g. `malware` vs
 * `malware_protection`, `supplier_relationships` vs `supplier_security`,
 * `privacy_pii` vs `privacy`, `mobile_device` vs `mobile_teleworking`)
 * the row uses the catalogue key for the {@see PolicyTemplate::$topic}
 * field (so wizard `targetedTopics` matching works) but the
 * `*_translation_key` columns reference the actual translation paths.
 *
 * Idempotent: running twice without `--force` is a no-op for rows that
 * already exist. With `--force` existing rows are updated in place.
 * `--dry-run` reports what would change without writing.
 *
 * Run after deploy:
 *   php bin/console app:policy-wizard:seed-iso27001
 *   php bin/console app:policy-wizard:seed-iso27001 --force
 *   php bin/console app:policy-wizard:seed-iso27001 --dry-run
 *
 * Mirrors {@see SeedBsiPolicyTemplatesCommand} / {@see SeedDoraPolicyTemplatesCommand}
 * in shape so downstream wizard tooling treats all standards uniformly.
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-iso27001',
    description: 'Seeds the ISO 27001 top-level policy + 24 ISO 27002:2022 topic policies (25 PolicyTemplate rows).',
)]
final class SeedIsoPolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'iso27001';

    /**
     * Catalogue topic-key → translation-file topic-key mapping. Required
     * because the historical translation files (`policy_iso27001*.yaml`)
     * use slightly different topic identifiers than
     * {@see PolicyWizardTopicCatalogue::ISO27001_TOPICS}. The
     * PolicyTemplate row itself stores the catalogue key; the translation
     * key path uses the mapping below.
     *
     * Identity entries (`malware → malware`) are omitted; lookup falls
     * back to the catalogue key when no override exists.
     *
     * @var array<string, string>
     */
    private const array TRANSLATION_KEY_OVERRIDES = [
        'malware' => 'malware_protection',
        'supplier_relationships' => 'supplier_security',
        'privacy_pii' => 'privacy',
        'mobile_device' => 'mobile_teleworking',
    ];

    /**
     * Canonical 25-row catalogue. Each row carries:
     *   • `key`: `iso27001.<catalogue_topic>`
     *   • `topic`: catalogue topic key (matches PolicyWizardTopicCatalogue)
     *   • `translation_topic`: translation-key topic (overrides applied)
     *   • `linked_annex_a_controls`: per `01-iso27001-input.md` §2
     *   • `affected_functions`: heuristic (IT_OPERATIONS/HR/CISO/...)
     *   • `dpo_section_required`: true for PII-touching templates
     *   • `requires_works_council_evidence`: true for workplace-monitoring
     *   • `target_audience`: 'all_staff' / 'it_operations' / 'specialists'
     *   • `climate_change_wording`: ISO 27001 Cl. 5.2 / Amd. 1:2024 driver
     *
     * @var list<array{
     *     key: string,
     *     topic: string,
     *     translation_topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     linked_annex_a_controls: list<string>,
     *     affected_functions: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: bool,
     *     requires_works_council_evidence: bool,
     *     target_audience: string,
     *     climate_change_wording: bool,
     * }>
     */
    public const array TEMPLATES = [
        // 0 — Cross-cutting Informationssicherheits-Leitlinie (Cl. 5.2).
        // The only top-level template; the other 24 are themenspezifisch.
        [
            'key' => 'iso27001.top_level',
            'topic' => 'top_level',
            'translation_topic' => 'top_level',
            'document_type' => 'policy',
            'norm_ref' => 'ISO/IEC 27001:2022 Cl. 5.2',
            'linked_annex_a_controls' => ['A.5.1'],
            'affected_functions' => ['TOP_MGMT', 'CISO'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => true,
        ],
        // 1 — Acceptable Use (A.5.10)
        [
            'key' => 'iso27001.acceptable_use',
            'topic' => 'acceptable_use',
            'translation_topic' => 'acceptable_use',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.10',
            'linked_annex_a_controls' => ['A.5.10', 'A.5.11', 'A.6.7', 'A.7.9', 'A.8.1'],
            'affected_functions' => ['CISO', 'HR', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => true,
            'requires_works_council_evidence' => true,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
        ],
        // 2 — Access Control (A.5.15)
        [
            'key' => 'iso27001.access_control',
            'topic' => 'access_control',
            'translation_topic' => 'access_control',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.15',
            'linked_annex_a_controls' => ['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2', 'A.8.3', 'A.8.4', 'A.8.5'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'it_operations',
            'climate_change_wording' => false,
        ],
        // 3 — Information Classification (A.5.12 + A.5.13)
        [
            'key' => 'iso27001.information_classification',
            'topic' => 'information_classification',
            'translation_topic' => 'information_classification',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.12',
            'linked_annex_a_controls' => ['A.5.12', 'A.5.13', 'A.5.14', 'A.5.33', 'A.7.10', 'A.8.10', 'A.8.12'],
            'affected_functions' => ['CISO', 'HR', 'IT_OPERATIONS'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => true,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
        ],
        // 4 — Information Transfer (A.5.14)
        [
            'key' => 'iso27001.information_transfer',
            'topic' => 'information_transfer',
            'translation_topic' => 'information_transfer',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.14',
            'linked_annex_a_controls' => ['A.5.14', 'A.6.6', 'A.8.20', 'A.8.21', 'A.8.22', 'A.8.23', 'A.8.24'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
        ],
        // 5 — Identity Management (A.5.16)
        [
            'key' => 'iso27001.identity_management',
            'topic' => 'identity_management',
            'translation_topic' => 'identity_management',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.16',
            'linked_annex_a_controls' => ['A.5.16', 'A.5.18', 'A.8.5'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS', 'HR'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'it_operations',
            'climate_change_wording' => false,
        ],
        // 6 — Authentication Information (A.5.17)
        [
            'key' => 'iso27001.authentication_information',
            'topic' => 'authentication_information',
            'translation_topic' => 'authentication_information',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.17',
            'linked_annex_a_controls' => ['A.5.17', 'A.8.5', 'A.5.16'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
        ],
        // 7 — Cryptography (A.8.24)
        [
            'key' => 'iso27001.cryptography',
            'topic' => 'cryptography',
            'translation_topic' => 'cryptography',
            'document_type' => 'policy',
            'norm_ref' => 'A.8.24',
            'linked_annex_a_controls' => ['A.8.24', 'A.5.14', 'A.8.25', 'A.8.26'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'it_operations',
            'climate_change_wording' => false,
        ],
        // 8 — Backup (A.8.13)
        [
            'key' => 'iso27001.backup',
            'topic' => 'backup',
            'translation_topic' => 'backup',
            'document_type' => 'policy',
            'norm_ref' => 'A.8.13',
            'linked_annex_a_controls' => ['A.8.13', 'A.5.30', 'A.8.14'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS', 'BCM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'it_operations',
            'climate_change_wording' => false,
        ],
        // 9 — Logging & Monitoring (A.8.15 / A.8.16 / A.8.17)
        [
            'key' => 'iso27001.logging',
            'topic' => 'logging',
            'translation_topic' => 'logging',
            'document_type' => 'policy',
            'norm_ref' => 'A.8.15',
            'linked_annex_a_controls' => ['A.8.15', 'A.8.16', 'A.8.17', 'A.5.28'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS', 'DPO'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => true,
            'target_audience' => 'it_operations',
            'climate_change_wording' => false,
        ],
        // 10 — Patch / Vulnerability Management (A.8.8)
        [
            'key' => 'iso27001.patch_management',
            'topic' => 'patch_management',
            'translation_topic' => 'patch_management',
            'document_type' => 'policy',
            'norm_ref' => 'A.8.8',
            'linked_annex_a_controls' => ['A.8.8', 'A.8.9', 'A.8.32', 'A.5.7'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'it_operations',
            'climate_change_wording' => false,
        ],
        // 11 — Malware Protection (A.8.7)
        [
            'key' => 'iso27001.malware',
            'topic' => 'malware',
            'translation_topic' => 'malware_protection',
            'document_type' => 'policy',
            'norm_ref' => 'A.8.7',
            'linked_annex_a_controls' => ['A.8.7', 'A.6.3', 'A.8.32'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
        ],
        // 12 — Secure Configuration (A.8.9)
        [
            'key' => 'iso27001.secure_configuration',
            'topic' => 'secure_configuration',
            'translation_topic' => 'secure_configuration',
            'document_type' => 'policy',
            'norm_ref' => 'A.8.9',
            'linked_annex_a_controls' => ['A.8.9', 'A.8.32', 'A.8.8'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'it_operations',
            'climate_change_wording' => false,
        ],
        // 13 — Network Security (A.8.20–A.8.23)
        [
            'key' => 'iso27001.network_security',
            'topic' => 'network_security',
            'translation_topic' => 'network_security',
            'document_type' => 'policy',
            'norm_ref' => 'A.8.20',
            'linked_annex_a_controls' => ['A.8.20', 'A.8.21', 'A.8.22', 'A.8.23', 'A.8.16'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'it_operations',
            'climate_change_wording' => false,
        ],
        // 14 — Secure Development (A.8.25–A.8.30)
        [
            'key' => 'iso27001.secure_development',
            'topic' => 'secure_development',
            'translation_topic' => 'secure_development',
            'document_type' => 'policy',
            'norm_ref' => 'A.8.25',
            'linked_annex_a_controls' => ['A.8.25', 'A.8.26', 'A.8.27', 'A.8.28', 'A.8.29', 'A.8.30', 'A.8.31', 'A.8.32', 'A.5.20'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'specialists',
            'climate_change_wording' => false,
        ],
        // 15 — Supplier Relationships (A.5.19–A.5.22) — translation key
        // = `supplier_security` (historical naming). climate_change=true
        // per architecture §6 Step 2 (supplier ESG/climate clauses).
        [
            'key' => 'iso27001.supplier_relationships',
            'topic' => 'supplier_relationships',
            'translation_topic' => 'supplier_security',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.19',
            'linked_annex_a_controls' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
            'affected_functions' => ['CISO', 'PROCUREMENT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'specialists',
            'climate_change_wording' => true,
        ],
        // 16 — Project Management Security (A.5.8)
        [
            'key' => 'iso27001.project_management',
            'topic' => 'project_management',
            'translation_topic' => 'project_management',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.8',
            'linked_annex_a_controls' => ['A.5.8', 'A.5.9', 'A.6.6'],
            'affected_functions' => ['CISO', 'PROJECT_MANAGEMENT'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'specialists',
            'climate_change_wording' => false,
        ],
        // 17 — Privacy & PII (A.5.34) — translation key = `privacy`,
        // catalogue topic = `privacy_pii`. climate_change=true per
        // architecture §6 Step 2 (PII / climate-disclosure overlap).
        [
            'key' => 'iso27001.privacy_pii',
            'topic' => 'privacy_pii',
            'translation_topic' => 'privacy',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.34',
            'linked_annex_a_controls' => ['A.5.34', 'A.5.31', 'A.5.32', 'A.8.11', 'A.8.12'],
            'affected_functions' => ['DPO', 'CISO', 'HR'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO'],
            'dpo_section_required' => true,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => true,
        ],
        // 18 — Incident Management (A.5.24–A.5.28)
        [
            'key' => 'iso27001.incident_management',
            'topic' => 'incident_management',
            'translation_topic' => 'incident_management',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.24',
            'linked_annex_a_controls' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.6.8', 'A.5.5', 'A.5.6'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS', 'CRISIS_TEAM', 'DPO'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
        ],
        // 19 — ICT Continuity (A.5.29 + A.5.30)
        [
            'key' => 'iso27001.continuity',
            'topic' => 'continuity',
            'translation_topic' => 'continuity',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.29',
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30', 'A.8.13', 'A.8.14'],
            'affected_functions' => ['CISO', 'BCM', 'IT_OPERATIONS', 'CRISIS_TEAM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'specialists',
            'climate_change_wording' => false,
        ],
        // 20 — Threat Intelligence (A.5.7)
        [
            'key' => 'iso27001.threat_intelligence',
            'topic' => 'threat_intelligence',
            'translation_topic' => 'threat_intelligence',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.7',
            'linked_annex_a_controls' => ['A.5.7', 'A.8.8', 'A.5.6', 'A.5.5'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'specialists',
            'climate_change_wording' => false,
        ],
        // 21 — Mobile & Remote Working (A.6.7) — translation key =
        // `mobile_teleworking`, catalogue topic = `mobile_device`.
        [
            'key' => 'iso27001.mobile_device',
            'topic' => 'mobile_device',
            'translation_topic' => 'mobile_teleworking',
            'document_type' => 'policy',
            'norm_ref' => 'A.6.7',
            'linked_annex_a_controls' => ['A.6.7', 'A.7.9', 'A.8.1', 'A.7.13', 'A.7.14'],
            'affected_functions' => ['CISO', 'HR', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_HR'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => true,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
        ],
        // 22 — Asset Management (A.5.9)
        [
            'key' => 'iso27001.asset_management',
            'topic' => 'asset_management',
            'translation_topic' => 'asset_management',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.9',
            'linked_annex_a_controls' => ['A.5.9', 'A.5.10', 'A.5.11', 'A.7.10', 'A.7.14'],
            'affected_functions' => ['CISO', 'IT_OPERATIONS'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
        ],
        // 23 — HR Security (A.6.1–A.6.6)
        [
            'key' => 'iso27001.hr_security',
            'topic' => 'hr_security',
            'translation_topic' => 'hr_security',
            'document_type' => 'policy',
            'norm_ref' => 'A.6.1',
            'linked_annex_a_controls' => ['A.6.1', 'A.6.2', 'A.6.3', 'A.6.4', 'A.6.5', 'A.6.6', 'A.5.11', 'A.5.18'],
            'affected_functions' => ['HR', 'CISO'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_HR', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
        ],
        // 24 — Physical Security (A.7.1–A.7.14)
        [
            'key' => 'iso27001.physical_security',
            'topic' => 'physical_security',
            'translation_topic' => 'physical_security',
            'document_type' => 'policy',
            'norm_ref' => 'A.7.1',
            'linked_annex_a_controls' => ['A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.5', 'A.7.6', 'A.7.7', 'A.7.8', 'A.7.9', 'A.5.10'],
            'affected_functions' => ['CISO', 'FACILITIES', 'IT_OPERATIONS'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
            'requires_works_council_evidence' => false,
            'target_audience' => 'all_staff',
            'climate_change_wording' => false,
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
            'ISO 27001 PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
     *     translation_topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     linked_annex_a_controls: list<string>,
     *     affected_functions: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: bool,
     *     requires_works_council_evidence: bool,
     *     target_audience: string,
     *     climate_change_wording: bool,
     * } $row
     */
    private function applyRowToTemplate(PolicyTemplate $template, array $row): void
    {
        $template->setTopic($row['topic']);
        $template->setDocumentType($row['document_type']);
        $template->setNormRef($row['norm_ref']);
        $template->setTitleTranslationKey(self::titleTranslationKey($row['translation_topic']));
        $template->setBodyTranslationKey(self::bodyTranslationKey($row['translation_topic']));
        $template->setLinkedAnnexAControls(
            $row['linked_annex_a_controls'] === [] ? null : $row['linked_annex_a_controls'],
        );
        $template->setLinkedBausteine(null);
        $template->setLinkedBsiBausteine(null);
        $template->setLinkedDoraArticles(null);
        $template->setBsiTier(null);
        $template->setAffectedFunctions(
            $row['affected_functions'] === [] ? null : $row['affected_functions'],
        );
        $template->setReviewIntervalMonths($row['review_interval_months']);
        $template->setApprovalChain($row['approval_chain']);
        $template->setDpoSectionRequired($row['dpo_section_required']);
        $template->setRequiresWorksCouncilEvidence($row['requires_works_council_evidence']);
        $template->setClimateChangeWording($row['climate_change_wording']);
        $template->setIsActive(true);
        $template->setVersion(1);
    }

    /**
     * Resolve the canonical title translation key for an ISO 27001 topic.
     * Public so the migration mirror + tests can re-use the same path
     * derivation without duplicating the string-build logic.
     */
    public static function titleTranslationKey(string $translationTopic): string
    {
        return sprintf('policy.iso27001.%s.v1.title', $translationTopic);
    }

    /**
     * Resolve the canonical body translation key for an ISO 27001 topic.
     */
    public static function bodyTranslationKey(string $translationTopic): string
    {
        return sprintf('policy.iso27001.%s.v1.body', $translationTopic);
    }

    /**
     * Catalogue topic-key → translation-file topic-key. Identity for any
     * key not in the override map. Exposed for tests and the migration
     * mirror so the same mapping never drifts between callers.
     */
    public static function translationTopicFor(string $catalogueTopic): string
    {
        return self::TRANSLATION_KEY_OVERRIDES[$catalogueTopic] ?? $catalogueTopic;
    }
}
