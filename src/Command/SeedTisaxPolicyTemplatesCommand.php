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
 * Policy-Wizard W7-A — seed the 10 TISAX (VDA ISA 5.x) Pflicht-
 * Richtlinien for the Automotive Supply Chain TISAX Pruefkatalog.
 *
 * Source of truth: VDA ISA Katalog 5.x (Pruefkatalog Information
 * Security) covering ISA 1-8 + Prototypenschutz (PSx) + Datenschutz
 * (DSx). The 10 templates seeded here:
 *
 *   1. tisax.information_security_policies        — ISA 1.x
 *   2. tisax.organisation_information_security    — ISA 2.x
 *   3. tisax.human_resources_security             — ISA 3.x
 *   4. tisax.physical_environmental_security      — ISA 4.x
 *   5. tisax.identity_access_management           — ISA 5.x
 *   6. tisax.it_security_operations               — ISA 6.x
 *   7. tisax.supplier_relationships_security      — ISA 7.x
 *   8. tisax.compliance_management                — ISA 8.x
 *   9. tisax.prototype_protection                 — PSx (Prototypen)
 *  10. tisax.data_protection_addendum             — DSx (PII)
 *
 * Every row carries:
 *   • `standard='tisax'`
 *   • `linked_annex_a_controls` — ISO 27001:2022 Annex A cross-mapping
 *     (TISAX Pruefkatalog ist im Kern ISO 27001-aligned)
 *   • `review_interval_months` — TISAX-typisches Pruefzyklus (12 Monate)
 *   • `affected_functions` — VDA-rollentypisch (CISO, HR, Procurement,
 *     Production, Engineering)
 *
 * Idempotent: running twice without `--force` is a no-op for rows that
 * already exist. With `--force` existing rows are updated in place.
 * `--dry-run` reports what would change without writing.
 *
 * Run after deploy:
 *   php bin/console app:policy-wizard:seed-tisax
 *   php bin/console app:policy-wizard:seed-tisax --force
 *   php bin/console app:policy-wizard:seed-tisax --dry-run
 *
 * Mirrors {@see SeedDoraPolicyTemplatesCommand} (W4-A) and
 * {@see SeedBsiPolicyTemplatesCommand} (W5-A) in shape so downstream
 * wizard tooling can treat all standards uniformly.
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-tisax',
    description: 'Seeds the 10 TISAX (VDA ISA + PSx + DSx) PolicyTemplate rows (W7-A).',
)]
final class SeedTisaxPolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'tisax';

    /**
     * Canonical 10-row catalogue. Body / title translation keys follow
     * the §8.7 versioning scheme (`policy.tisax.<topic>.v1.body`); real
     * translation content is authored in W7-E.
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
        // 1 — IS-Leitlinien (ISA 1.x)
        [
            'key' => 'tisax.information_security_policies',
            'topic' => 'information_security_policies',
            'document_type' => 'policy',
            'norm_ref' => 'VDA ISA 1.x',
            'title_translation_key' => 'policy.tisax.information_security_policies.v1.title',
            'body_translation_key' => 'policy.tisax.information_security_policies.v1.body',
            'linked_annex_a_controls' => ['A.5.1', 'A.5.2'],
            'affected_functions' => ['TOP_MGMT', 'CISO'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 2 — Organisation IS (ISA 2.x)
        [
            'key' => 'tisax.organisation_information_security',
            'topic' => 'organisation_information_security',
            'document_type' => 'policy',
            'norm_ref' => 'VDA ISA 2.x',
            'title_translation_key' => 'policy.tisax.organisation_information_security.v1.title',
            'body_translation_key' => 'policy.tisax.organisation_information_security.v1.body',
            'linked_annex_a_controls' => ['A.5.2', 'A.5.3', 'A.5.4', 'A.5.5'],
            'affected_functions' => ['CISO', 'TOP_MGMT', 'HR'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 3 — Personalsicherheit (ISA 3.x)
        [
            'key' => 'tisax.human_resources_security',
            'topic' => 'human_resources_security',
            'document_type' => 'policy',
            'norm_ref' => 'VDA ISA 3.x',
            'title_translation_key' => 'policy.tisax.human_resources_security.v1.title',
            'body_translation_key' => 'policy.tisax.human_resources_security.v1.body',
            'linked_annex_a_controls' => ['A.6.1', 'A.6.2', 'A.6.3', 'A.6.4', 'A.6.5', 'A.6.6'],
            'affected_functions' => ['HR', 'CISO'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_HR', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 4 — Physische + umgebungsbezogene Sicherheit (ISA 4.x)
        [
            'key' => 'tisax.physical_environmental_security',
            'topic' => 'physical_environmental_security',
            'document_type' => 'policy',
            'norm_ref' => 'VDA ISA 4.x',
            'title_translation_key' => 'policy.tisax.physical_environmental_security.v1.title',
            'body_translation_key' => 'policy.tisax.physical_environmental_security.v1.body',
            'linked_annex_a_controls' => [
                'A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.5', 'A.7.6',
                'A.7.7', 'A.7.8', 'A.7.9', 'A.7.10', 'A.7.11', 'A.7.12',
                'A.7.13', 'A.7.14',
            ],
            'affected_functions' => ['FACILITIES', 'CISO', 'PRODUCTION'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_FACILITIES'],
            'dpo_section_required' => false,
        ],
        // 5 — IAM (ISA 5.x)
        [
            'key' => 'tisax.identity_access_management',
            'topic' => 'identity_access_management',
            'document_type' => 'policy',
            'norm_ref' => 'VDA ISA 5.x',
            'title_translation_key' => 'policy.tisax.identity_access_management.v1.title',
            'body_translation_key' => 'policy.tisax.identity_access_management.v1.body',
            'linked_annex_a_controls' => [
                'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18',
                'A.8.2', 'A.8.3', 'A.8.4', 'A.8.5',
            ],
            'affected_functions' => ['IT_OPERATIONS', 'CISO', 'HR'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 6 — IT-Sicherheits-Betrieb (ISA 6.x)
        [
            'key' => 'tisax.it_security_operations',
            'topic' => 'it_security_operations',
            'document_type' => 'policy',
            'norm_ref' => 'VDA ISA 6.x',
            'title_translation_key' => 'policy.tisax.it_security_operations.v1.title',
            'body_translation_key' => 'policy.tisax.it_security_operations.v1.body',
            'linked_annex_a_controls' => [
                'A.8.6', 'A.8.7', 'A.8.8', 'A.8.9', 'A.8.13', 'A.8.15',
                'A.8.16', 'A.8.17', 'A.8.20', 'A.8.21', 'A.8.22', 'A.8.23',
                'A.8.24', 'A.8.32',
            ],
            'affected_functions' => ['IT_OPERATIONS', 'CISO', 'SOC'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 7 — Lieferanten-Sicherheit (ISA 7.x)
        [
            'key' => 'tisax.supplier_relationships_security',
            'topic' => 'supplier_relationships_security',
            'document_type' => 'policy',
            'norm_ref' => 'VDA ISA 7.x',
            'title_translation_key' => 'policy.tisax.supplier_relationships_security.v1.title',
            'body_translation_key' => 'policy.tisax.supplier_relationships_security.v1.body',
            'linked_annex_a_controls' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
            'affected_functions' => ['PROCUREMENT', 'CISO', 'LEGAL'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_PROCUREMENT'],
            'dpo_section_required' => false,
        ],
        // 8 — Compliance-Management (ISA 8.x)
        [
            'key' => 'tisax.compliance_management',
            'topic' => 'compliance_management',
            'document_type' => 'policy',
            'norm_ref' => 'VDA ISA 8.x',
            'title_translation_key' => 'policy.tisax.compliance_management.v1.title',
            'body_translation_key' => 'policy.tisax.compliance_management.v1.body',
            'linked_annex_a_controls' => ['A.5.31', 'A.5.32', 'A.5.33', 'A.5.34', 'A.5.35', 'A.5.36'],
            'affected_functions' => ['LEGAL', 'CISO', 'TOP_MGMT', 'COMPLIANCE'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_LEGAL', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 9 — Prototypenschutz (PSx — Pflicht bei Prototypen-Schutzbedarf hoch)
        [
            'key' => 'tisax.prototype_protection',
            'topic' => 'prototype_protection',
            'document_type' => 'programme',
            'norm_ref' => 'VDA ISA PSx',
            'title_translation_key' => 'policy.tisax.prototype_protection.v1.title',
            'body_translation_key' => 'policy.tisax.prototype_protection.v1.body',
            'linked_annex_a_controls' => [
                'A.5.10', 'A.5.13', 'A.5.14', 'A.6.6',
                'A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.6',
                'A.8.12',
            ],
            'affected_functions' => ['ENGINEERING', 'PRODUCTION', 'CISO', 'FACILITIES', 'PROCUREMENT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_ENGINEERING', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 10 — Datenschutz-Anhang TISAX (DSx — Pflicht bei pers.bez. Daten)
        [
            'key' => 'tisax.data_protection_addendum',
            'topic' => 'data_protection_addendum',
            'document_type' => 'policy',
            'norm_ref' => 'VDA ISA DSx',
            'title_translation_key' => 'policy.tisax.data_protection_addendum.v1.title',
            'body_translation_key' => 'policy.tisax.data_protection_addendum.v1.body',
            'linked_annex_a_controls' => ['A.5.34', 'A.8.10', 'A.8.11', 'A.8.12'],
            'affected_functions' => ['DPO', 'CISO', 'LEGAL', 'PROCUREMENT', 'HR'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO', 'ROLE_LEGAL'],
            'dpo_section_required' => true,
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
            'TISAX PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
        $template->setLinkedAnnexAControls(
            $row['linked_annex_a_controls'] === [] ? null : $row['linked_annex_a_controls'],
        );
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
