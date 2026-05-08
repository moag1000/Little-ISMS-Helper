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
 * Policy-Wizard W4-A — seed the 6 NEW DORA PolicyTemplate rows.
 *
 * Per `docs/plans/policy-wizard/03-dora-input.md` §10 cross-mapping
 * the DORA addon contributes 6 NEW documents on top of the ISO 27001
 * baseline (the remaining 18 mandates are EXTENSIONS to existing ISO
 * topic policies and 1 is a REPLACEMENT — see
 * {@see \App\Service\PolicyWizard\DoraExtensionCatalogue}). The 6 NEW
 * templates seeded here:
 *
 *   1. dora.ict_risk_management_framework — Art. 6 Framework
 *   2. dora.ict_risk_tolerance            — Art. 6.8 Tolerance Statement
 *   3. dora.detection_anomalous_activities— Art. 10
 *   4. dora.response_recovery             — Art. 11 (DORA-specific,
 *                                            EXTENDS A.5.29 partly)
 *   5. dora.learning_evolving             — Art. 13
 *   6. dora.communication_ict_incidents   — Art. 14
 *
 * Idempotent — running the command twice without `--force` is a no-op
 * for rows that already exist. With `--force` existing rows are
 * updated in place (mirrors the SQL ON DUPLICATE KEY UPDATE pattern).
 *
 * Run as one-off after deploy:
 *   php bin/console app:policy-wizard:seed-dora
 *   php bin/console app:policy-wizard:seed-dora --force
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-dora',
    description: 'Seeds the 6 NEW DORA PolicyTemplate rows (W4-A).',
)]
final class SeedDoraPolicyTemplatesCommand extends Command
{
    public const string DORA_VALIDITY_FROM = '2025-01-17';

    public const string STANDARD = 'dora';

    /**
     * Canonical 6-row catalogue. Body / title translation keys follow
     * the §8.7 versioning scheme (`policy.dora.<topic>.v1.body`); real
     * translation content is authored in W4-E.
     *
     * @var list<array{
     *     key: string,
     *     topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     title_translation_key: string,
     *     body_translation_key: string,
     *     linked_dora_articles: list<string>,
     *     affected_functions: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: bool,
     * }>
     */
    public const array TEMPLATES = [
        [
            'key' => 'dora.ict_risk_management_framework',
            'topic' => 'ict_risk_management_framework',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 6',
            'title_translation_key' => 'policy.dora.ict_risk_management_framework.v1.title',
            'body_translation_key' => 'policy.dora.ict_risk_management_framework.v1.body',
            'linked_dora_articles' => ['Art. 6', 'Art. 6.8'],
            'affected_functions' => ['IT_OPERATIONS', 'RISK_MANAGEMENT', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'dora.ict_risk_tolerance',
            'topic' => 'ict_risk_tolerance',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 6.8',
            'title_translation_key' => 'policy.dora.ict_risk_tolerance.v1.title',
            'body_translation_key' => 'policy.dora.ict_risk_tolerance.v1.body',
            'linked_dora_articles' => ['Art. 6.8'],
            'affected_functions' => ['RISK_MANAGEMENT', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'dora.detection_anomalous_activities',
            'topic' => 'detection_anomalous_activities',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 10',
            'title_translation_key' => 'policy.dora.detection_anomalous_activities.v1.title',
            'body_translation_key' => 'policy.dora.detection_anomalous_activities.v1.body',
            'linked_dora_articles' => ['Art. 10'],
            'affected_functions' => ['IT_OPERATIONS', 'SOC'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'dora.response_recovery',
            'topic' => 'response_recovery',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 11',
            'title_translation_key' => 'policy.dora.response_recovery.v1.title',
            'body_translation_key' => 'policy.dora.response_recovery.v1.body',
            'linked_dora_articles' => ['Art. 11'],
            'affected_functions' => ['IT_OPERATIONS', 'BCM', 'CRISIS_TEAM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'dora.learning_evolving',
            'topic' => 'learning_evolving',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 13',
            'title_translation_key' => 'policy.dora.learning_evolving.v1.title',
            'body_translation_key' => 'policy.dora.learning_evolving.v1.body',
            'linked_dora_articles' => ['Art. 13'],
            'affected_functions' => ['IT_OPERATIONS', 'HR', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_HR'],
            'dpo_section_required' => false,
        ],
        [
            'key' => 'dora.communication_ict_incidents',
            'topic' => 'communication_ict_incidents',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 14',
            'title_translation_key' => 'policy.dora.communication_ict_incidents.v1.title',
            'body_translation_key' => 'policy.dora.communication_ict_incidents.v1.body',
            'linked_dora_articles' => ['Art. 14'],
            'affected_functions' => ['COMMUNICATIONS', 'CRISIS_TEAM', 'TOP_MGMT'],
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
            'DORA PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
     *     linked_dora_articles: list<string>,
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
        $template->setLinkedDoraArticles($row['linked_dora_articles']);
        $template->setLinkedAnnexAControls(null);
        $template->setLinkedBausteine(null);
        $template->setAffectedFunctions($row['affected_functions']);
        $template->setReviewIntervalMonths($row['review_interval_months']);
        $template->setApprovalChain($row['approval_chain']);
        $template->setDpoSectionRequired($row['dpo_section_required']);
        $template->setClimateChangeWording(false);
        $template->setIsActive(true);
        $template->setVersion(1);
    }
}
