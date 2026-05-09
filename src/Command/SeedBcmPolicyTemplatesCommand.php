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
 * Policy-Wizard W5-B — seed the 14 BCM PolicyTemplate rows.
 *
 * Per `docs/plans/policy-wizard/04-bcm-input.md` §1 + §2.1-§2.13 the
 * BCM addon contributes 14 governance-level documents:
 *
 *   1. bcms_top_level                        — ISO 22301 Cl. 5.2 Notfallleitlinie
 *   2. bcms_scope_statement                  — ISO 22301 Cl. 4.3 Geltungsbereich
 *   3. bia_methodology                       — ISO 22301 Cl. 8.2.2 BIA Methodik
 *   4. risk_assessment_methodology_bcm       — ISO 22301 Cl. 8.2.3 BCM-RA
 *   5. bc_strategy                           — ISO 22301 Cl. 8.3 BC-Strategie
 *   6. bc_plans                              — ISO 22301 Cl. 8.4.4 BC Plan Template
 *   7. incident_response_communication       — ISO 22301 Cl. 8.4.3 Incident-Response
 *   8. crisis_management_plan                — ISO 22301 Cl. 8.4.4 Krisenstabsordnung
 *   9. recovery_plans                        — ISO 22301 Cl. 8.4.5 Wiederanlauf
 *  10. exercise_testing_programme            — ISO 22301 Cl. 8.6 Übungs-Programm
 *  11. internal_audit_bcm                    — ISO 22301 Cl. 9.2 BCMS-Audit
 *  12. management_review_bcm                 — ISO 22301 Cl. 9.3 Managementbewertung
 *  13. nonconformity_corrective_action_bcm   — ISO 22301 Cl. 10.1 Korrekturmaßnahmen
 *  14. notfallhandbuch_bsi_2004              — BSI 200-4 Kap. 7 Notfallhandbuch
 *      (only BSI-tenants — but seeded centrally; gating is wizard-side)
 *
 * Idempotent — running twice without `--force` is a no-op for rows that
 * already exist. With `--force` existing rows are updated in place
 * (mirrors the SQL ON DUPLICATE KEY UPDATE pattern).
 *
 * Run as one-off after deploy:
 *   php bin/console app:policy-wizard:seed-bcm
 *   php bin/console app:policy-wizard:seed-bcm --force
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-bcm',
    description: 'Seeds the 14 BCM PolicyTemplate rows (W5-B).',
)]
final class SeedBcmPolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'bcm';

    /**
     * Canonical 14-row catalogue. Body / title translation keys follow
     * the §8.7 versioning scheme (`policy.bcm.<topic>.v1.body`). Real
     * translation content is authored in the W5-D content sprint.
     *
     * `linked_annex_a_controls` covers A.5.29 / A.5.30 where the BCM
     * topic touches ISO 27001:2022 (per §6 cross-mapping table). The
     * Notfallhandbuch row is the only BSI-anchored template and carries
     * `linked_bausteine = ['DER.4']`.
     *
     * @var list<array{
     *     key: string,
     *     topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     title_translation_key: string,
     *     body_translation_key: string,
     *     linked_annex_a_controls: list<string>|null,
     *     linked_bausteine: list<string>|null,
     *     affected_functions: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: bool,
     * }>
     */
    public const array TEMPLATES = [
        [
            'key' => 'bcm.bcms_top_level',
            'topic' => 'bcms_top_level',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 22301 Cl. 5.2',
            'title_translation_key' => 'policy.bcm.bcms_top_level.v1.title',
            'body_translation_key' => 'policy.bcm.bcms_top_level.v1.body',
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['TOP_MGMT', 'BCM', 'CRISIS_TEAM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.bcms_scope_statement',
            'topic' => 'bcms_scope_statement',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 22301 Cl. 4.3',
            'title_translation_key' => 'policy.bcm.bcms_scope_statement.v1.title',
            'body_translation_key' => 'policy.bcm.bcms_scope_statement.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['TOP_MGMT', 'BCM'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.bia_methodology',
            'topic' => 'bia_methodology',
            'document_type' => 'methodology',
            'norm_ref' => 'ISO 22301 Cl. 8.2.2',
            'title_translation_key' => 'policy.bcm.bia_methodology.v1.title',
            'body_translation_key' => 'policy.bcm.bia_methodology.v1.body',
            'linked_annex_a_controls' => ['A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'BUSINESS_PROCESS_OWNERS'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_BCM'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.risk_assessment_methodology_bcm',
            'topic' => 'risk_assessment_methodology_bcm',
            'document_type' => 'methodology',
            'norm_ref' => 'ISO 22301 Cl. 8.2.3',
            'title_translation_key' => 'policy.bcm.risk_assessment_methodology_bcm.v1.title',
            'body_translation_key' => 'policy.bcm.risk_assessment_methodology_bcm.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'RISK_MANAGEMENT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.bc_strategy',
            'topic' => 'bc_strategy',
            'document_type' => 'programme',
            'norm_ref' => 'ISO 22301 Cl. 8.3',
            'title_translation_key' => 'policy.bcm.bc_strategy.v1.title',
            'body_translation_key' => 'policy.bcm.bc_strategy.v1.body',
            'linked_annex_a_controls' => ['A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'IT_OPERATIONS', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.bc_plans',
            'topic' => 'bc_plans',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 8.4.4',
            'title_translation_key' => 'policy.bcm.bc_plans.v1.title',
            'body_translation_key' => 'policy.bcm.bc_plans.v1.body',
            'linked_annex_a_controls' => ['A.5.29'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'BUSINESS_PROCESS_OWNERS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.incident_response_communication',
            'topic' => 'incident_response_communication',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 8.4.3',
            'title_translation_key' => 'policy.bcm.incident_response_communication.v1.title',
            'body_translation_key' => 'policy.bcm.incident_response_communication.v1.body',
            'linked_annex_a_controls' => ['A.5.29'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'CRISIS_TEAM', 'COMMUNICATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.crisis_management_plan',
            'topic' => 'crisis_management_plan',
            'document_type' => 'plan',
            'norm_ref' => 'ISO 22301 Cl. 8.4.4',
            'title_translation_key' => 'policy.bcm.crisis_management_plan.v1.title',
            'body_translation_key' => 'policy.bcm.crisis_management_plan.v1.body',
            'linked_annex_a_controls' => ['A.5.29'],
            'linked_bausteine' => null,
            'affected_functions' => ['CRISIS_TEAM', 'TOP_MGMT', 'BCM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.recovery_plans',
            'topic' => 'recovery_plans',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 8.4.5',
            'title_translation_key' => 'policy.bcm.recovery_plans.v1.title',
            'body_translation_key' => 'policy.bcm.recovery_plans.v1.body',
            'linked_annex_a_controls' => ['A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.exercise_testing_programme',
            'topic' => 'exercise_testing_programme',
            'document_type' => 'programme',
            'norm_ref' => 'ISO 22301 Cl. 8.6',
            'title_translation_key' => 'policy.bcm.exercise_testing_programme.v1.title',
            'body_translation_key' => 'policy.bcm.exercise_testing_programme.v1.body',
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'CRISIS_TEAM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.internal_audit_bcm',
            'topic' => 'internal_audit_bcm',
            'document_type' => 'programme',
            'norm_ref' => 'ISO 22301 Cl. 9.2',
            'title_translation_key' => 'policy.bcm.internal_audit_bcm.v1.title',
            'body_translation_key' => 'policy.bcm.internal_audit_bcm.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'INTERNAL_AUDIT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_INTERNAL_AUDIT'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.management_review_bcm',
            'topic' => 'management_review_bcm',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 9.3',
            'title_translation_key' => 'policy.bcm.management_review_bcm.v1.title',
            'body_translation_key' => 'policy.bcm.management_review_bcm.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['TOP_MGMT', 'BCM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.nonconformity_corrective_action_bcm',
            'topic' => 'nonconformity_corrective_action_bcm',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 10.1',
            'title_translation_key' => 'policy.bcm.nonconformity_corrective_action_bcm.v1.title',
            'body_translation_key' => 'policy.bcm.nonconformity_corrective_action_bcm.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'INTERNAL_AUDIT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'bcm.notfallhandbuch_bsi_2004',
            'topic' => 'notfallhandbuch_bsi_2004',
            'document_type' => 'programme',
            'norm_ref' => 'BSI 200-4 Kap. 7',
            'title_translation_key' => 'policy.bcm.notfallhandbuch_bsi_2004.v1.title',
            'body_translation_key' => 'policy.bcm.notfallhandbuch_bsi_2004.v1.body',
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30'],
            'linked_bausteine' => ['DER.4'],
            'affected_functions' => ['BCM', 'CRISIS_TEAM', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
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
            'BCM PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
     *     linked_annex_a_controls: list<string>|null,
     *     linked_bausteine: list<string>|null,
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
        $template->setLinkedBausteine($row['linked_bausteine']);
        $template->setLinkedDoraArticles(null);
        $template->setAffectedFunctions($row['affected_functions']);
        $template->setReviewIntervalMonths($row['review_interval_months']);
        $template->setApprovalChain($row['approval_chain']);
        $template->setDpoSectionRequired($row['dpo_section_required']);
        $template->setClimateChangeWording(false);
        $template->setIsActive(true);
        $template->setVersion(1);
    }
}
