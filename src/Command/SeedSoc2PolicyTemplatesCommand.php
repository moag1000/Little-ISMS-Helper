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
 * Policy-Wizard SOC 2 — seed the 10 SOC 2 Trust Services Criteria
 * (TSC 2017, revised 2022) Pflicht-Templates as PolicyTemplate rows.
 *
 * SOC 2 was added to the Compliance-Wizard standard picker in commit
 * 8e286cbc but had zero policy templates seeded. This command closes
 * that gap and mirrors {@see SeedBsiPolicyTemplatesCommand} (W5-A) and
 * {@see SeedDoraPolicyTemplatesCommand} (W4-A) in shape so downstream
 * wizard tooling treats SOC 2 uniformly with the other standards.
 *
 * The 10 templates cover the AICPA Trust Services Criteria 2017 + 2022
 * revision: 5 Common Criteria buckets (CC1+CC2+CC3 governance,
 * CC6 access, CC7 operations, CC8 change-management, CC9 risk
 * mitigation / vendor) plus the 4 additional principles (Availability
 * A1, Confidentiality C1, Processing Integrity PI1, Privacy P1-P8) and
 * a dedicated incident-response / stakeholder-communication policy
 * (CC2.3 + CC7.4) which auditors expect as a separate document.
 *
 * Norm anchors:
 *   - AICPA Trust Services Criteria 2017 (revised 2022)
 *   - ISO/IEC 27001:2022 Annex A cross-references (full TSC ↔ Annex A
 *     mapping seeded by {@see SeedSoc2Iso27001MappingsCommand})
 *   - AICPA SSAE 18 (Service Auditor reporting standard) — bodies
 *     explain the Type-I vs Type-II distinction and subservice-org
 *     carve-out vs inclusive treatment that auditors look for.
 *
 * Bodies are authored EN-primary (US auditor audience) and DE-secondary
 * (Sie-Form, formal) so German-speaking tenants still get usable text.
 *
 * Idempotent: running twice without `--force` is a no-op for rows that
 * already exist. With `--force` existing rows are updated in place.
 * `--dry-run` reports what would change without writing.
 *
 * Run after deploy:
 *   php bin/console app:policy-wizard:seed-soc2
 *   php bin/console app:policy-wizard:seed-soc2 --force
 *   php bin/console app:policy-wizard:seed-soc2 --dry-run
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-soc2',
    description: 'Seeds the 10 SOC 2 Trust Services Criteria policy templates.',
)]
final class SeedSoc2PolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'soc2';

    /**
     * Canonical 10-row catalogue. Body / title translation keys follow
     * the §8.7 versioning scheme (`policy.soc2.<topic>.v1.body`).
     *
     * @var list<array{
     *     key: string,
     *     topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     title_translation_key: string,
     *     body_translation_key: string,
     *     linked_annex_a_controls: list<string>,
     *     affected_functions: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: bool,
     * }>
     */
    public const array TEMPLATES = [
        // 1 — Security Governance + Risk-Mgmt (CC1+CC2+CC3)
        [
            'key' => 'soc2.security_governance',
            'topic' => 'security_governance',
            'document_type' => 'policy',
            'norm_ref' => 'TSC CC1-CC3',
            'title_translation_key' => 'policy.soc2.security_governance.v1.title',
            'body_translation_key' => 'policy.soc2.security_governance.v1.body',
            'linked_annex_a_controls' => ['A.5.1', 'A.5.2', 'A.5.4', 'A.5.7', 'A.5.8', 'A.6.3'],
            'affected_functions' => ['TOP_MGMT', 'CISO', 'INTERNAL_AUDIT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 2 — Logical + Physical Access Controls (CC6)
        [
            'key' => 'soc2.logical_physical_access_controls',
            'topic' => 'logical_physical_access_controls',
            'document_type' => 'policy',
            'norm_ref' => 'TSC CC6',
            'title_translation_key' => 'policy.soc2.logical_physical_access_controls.v1.title',
            'body_translation_key' => 'policy.soc2.logical_physical_access_controls.v1.body',
            'linked_annex_a_controls' => ['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.7.1', 'A.7.2', 'A.8.2', 'A.8.3', 'A.8.5'],
            'affected_functions' => ['IT_OPERATIONS', 'HR', 'FACILITIES'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 3 — System Operations + Monitoring (CC7)
        [
            'key' => 'soc2.system_operations',
            'topic' => 'system_operations',
            'document_type' => 'policy',
            'norm_ref' => 'TSC CC7',
            'title_translation_key' => 'policy.soc2.system_operations.v1.title',
            'body_translation_key' => 'policy.soc2.system_operations.v1.body',
            'linked_annex_a_controls' => ['A.5.25', 'A.5.26', 'A.8.8', 'A.8.15', 'A.8.16', 'A.8.17'],
            'affected_functions' => ['IT_OPERATIONS', 'SOC'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 4 — Change-Management (CC8)
        [
            'key' => 'soc2.change_management',
            'topic' => 'change_management',
            'document_type' => 'policy',
            'norm_ref' => 'TSC CC8.1',
            'title_translation_key' => 'policy.soc2.change_management.v1.title',
            'body_translation_key' => 'policy.soc2.change_management.v1.body',
            'linked_annex_a_controls' => ['A.8.9', 'A.8.29', 'A.8.31', 'A.8.32'],
            'affected_functions' => ['IT_OPERATIONS', 'DEVELOPMENT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 5 — Risk Mitigation + Vendor-Mgmt (CC9)
        [
            'key' => 'soc2.risk_mitigation',
            'topic' => 'risk_mitigation',
            'document_type' => 'policy',
            'norm_ref' => 'TSC CC9',
            'title_translation_key' => 'policy.soc2.risk_mitigation.v1.title',
            'body_translation_key' => 'policy.soc2.risk_mitigation.v1.body',
            'linked_annex_a_controls' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
            'affected_functions' => ['RISK_MANAGEMENT', 'PROCUREMENT', 'LEGAL'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_PROCUREMENT'],
            'dpo_section_required' => false,
        ],
        // 6 — Availability (A1.x — RTO/RPO/Capacity)
        [
            'key' => 'soc2.availability_principle',
            'topic' => 'availability_principle',
            'document_type' => 'policy',
            'norm_ref' => 'TSC A1.1-A1.3',
            'title_translation_key' => 'policy.soc2.availability_principle.v1.title',
            'body_translation_key' => 'policy.soc2.availability_principle.v1.body',
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30', 'A.8.6', 'A.8.13', 'A.8.14'],
            'affected_functions' => ['IT_OPERATIONS', 'BCM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM'],
            'dpo_section_required' => false,
        ],
        // 7 — Confidentiality (C1.x — Classification + Crypto + Disposal)
        [
            'key' => 'soc2.confidentiality_principle',
            'topic' => 'confidentiality_principle',
            'document_type' => 'policy',
            'norm_ref' => 'TSC C1.1-C1.2',
            'title_translation_key' => 'policy.soc2.confidentiality_principle.v1.title',
            'body_translation_key' => 'policy.soc2.confidentiality_principle.v1.body',
            'linked_annex_a_controls' => ['A.5.12', 'A.5.13', 'A.5.14', 'A.7.10', 'A.7.14', 'A.8.10', 'A.8.24'],
            'affected_functions' => ['IT_OPERATIONS', 'LEGAL', 'CISO'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 8 — Processing Integrity (PI1.x — Input/Process/Output validation)
        [
            'key' => 'soc2.processing_integrity_principle',
            'topic' => 'processing_integrity_principle',
            'document_type' => 'policy',
            'norm_ref' => 'TSC PI1.1-PI1.5',
            'title_translation_key' => 'policy.soc2.processing_integrity_principle.v1.title',
            'body_translation_key' => 'policy.soc2.processing_integrity_principle.v1.body',
            'linked_annex_a_controls' => ['A.8.25', 'A.8.26', 'A.8.27', 'A.8.28', 'A.8.29'],
            'affected_functions' => ['DEVELOPMENT', 'IT_OPERATIONS', 'BUSINESS_OWNERS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 9 — Privacy (P1-P8 — Notice, Choice, Collection, Use, Access,
        // Disclosure, Quality, Monitoring). DPO sign-off required.
        [
            'key' => 'soc2.privacy_principle',
            'topic' => 'privacy_principle',
            'document_type' => 'policy',
            'norm_ref' => 'TSC P1-P8',
            'title_translation_key' => 'policy.soc2.privacy_principle.v1.title',
            'body_translation_key' => 'policy.soc2.privacy_principle.v1.body',
            'linked_annex_a_controls' => ['A.5.34', 'A.8.10', 'A.8.11', 'A.8.12'],
            'affected_functions' => ['DPO', 'LEGAL', 'CUSTOMER_SUCCESS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 10 — Incident Response + Stakeholder Communication (CC2.3 + CC7.4)
        [
            'key' => 'soc2.incident_response_communication',
            'topic' => 'incident_response_communication',
            'document_type' => 'policy',
            'norm_ref' => 'TSC CC2.3 + CC7.4',
            'title_translation_key' => 'policy.soc2.incident_response_communication.v1.title',
            'body_translation_key' => 'policy.soc2.incident_response_communication.v1.body',
            'linked_annex_a_controls' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.6.8'],
            'affected_functions' => ['CISO', 'COMMUNICATIONS', 'CRISIS_TEAM', 'TOP_MGMT'],
            'review_interval_months' => 12,
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
            'SOC 2 PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
     *     linked_annex_a_controls: list<string>,
     *     affected_functions: list<string>,
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
        $template->setLinkedAnnexAControls($row['linked_annex_a_controls']);
        $template->setLinkedBausteine(null);
        $template->setLinkedBsiBausteine(null);
        $template->setLinkedDoraArticles(null);
        $template->setBsiTier(null);
        $template->setAffectedFunctions($row['affected_functions']);
        $template->setReviewIntervalMonths($row['review_interval_months']);
        $template->setApprovalChain($row['approval_chain']);
        $template->setDpoSectionRequired($row['dpo_section_required']);
        $template->setClimateChangeWording(false);
        $template->setIsActive(true);
        $template->setVersion(1);
    }
}
