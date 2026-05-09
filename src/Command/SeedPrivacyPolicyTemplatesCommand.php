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
 * Policy-Wizard W6-B — seed the 5 standalone Privacy / DPO documents
 * plus the thin A.5.34 cross-reference host = 6 PolicyTemplate rows.
 *
 * Source of truth: `docs/plans/policy-wizard/06-dpo-input.md` Decision
 * Matrix v2 (§0) + per-document detail (§2.1–§2.5) + ISO 27701:2025
 * clause mapping (§3.1).
 *
 * The wizard generates the *frameworks* governing the existing
 * `ProcessingActivity`, `DataProtectionImpactAssessment`,
 * `DataSubjectRequest`, `DataBreach` and `Consent` modules — the
 * records inside those entities stay artefacts of operational
 * execution. Per §2 of the DPO spec, owner defaults to `ROLE_DPO`,
 * CISO and Top-Mgmt approval is wired per-template.
 *
 * Every row carries:
 *   • `standard='gdpr'`
 *   • `linkedAnnexAControls=['A.5.34']` — every privacy doc cross-refs
 *     the ISO 27001 A.5.34 host
 *   • `iso27701_clauses_2025` + `iso27701_clauses_2019` — PIMS mapping
 *   • `dpo_section_required=true` for the 5 standalone docs; `false`
 *     for the thin A.5.34 host (it carries no own privacy section)
 *   • `affected_functions=['dpo']` — the W3 function-owner-review
 *     workflow gate
 *
 * Idempotent: re-running without `--force` is a no-op for rows that
 * already exist. With `--force` existing rows are updated in place.
 * `--dry-run` reports what would change without writing.
 *
 * Run after deploy:
 *   php bin/console app:policy-wizard:seed-privacy
 *   php bin/console app:policy-wizard:seed-privacy --force
 *   php bin/console app:policy-wizard:seed-privacy --dry-run
 *
 * Mirrors {@see SeedDoraPolicyTemplatesCommand} (W4-A) and
 * {@see SeedBsiPolicyTemplatesCommand} (W5-A) in shape so downstream
 * wizard tooling can treat all standards uniformly.
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-privacy',
    description: 'Seeds the 5 standalone Privacy/DPO templates + 1 thin A.5.34 host (W6-B).',
)]
final class SeedPrivacyPolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'gdpr';

    /**
     * Canonical 6-row catalogue. Body / title translation keys follow
     * the §8.7 versioning scheme (`policy.gdpr.<topic>.v1.body`); real
     * translation content is authored in W6-E.
     *
     * ISO 27701 clause mapping per `06-dpo-input.md` §3.1 — both 2025
     * and 2019 stored so the W6-B `iso27701.version` setting can pick
     * the right clause set without re-querying the source-of-truth doc.
     *
     * @var list<array{
     *     key: string,
     *     topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     title_translation_key: string,
     *     body_translation_key: string,
     *     iso27701_clauses_2025: list<string>,
     *     iso27701_clauses_2019: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: bool,
     * }>
     */
    public const array TEMPLATES = [
        // 1 — Privacy / Data-Protection Policy (top-level, §2.1)
        // ISO 27701 Cl. 5.1 (leadership) + 5.2 (privacy policy declaration).
        [
            'key' => 'gdpr.privacy_policy',
            'topic' => 'privacy_policy',
            'document_type' => 'policy',
            'norm_ref' => 'GDPR Art. 5/24',
            'title_translation_key' => 'policy.gdpr.privacy_policy.v1.title',
            'body_translation_key' => 'policy.gdpr.privacy_policy.v1.body',
            'iso27701_clauses_2025' => ['5.1', '5.2'],
            'iso27701_clauses_2019' => ['5.1', '5.2'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 2 — RoPA Methodology (§2.2)
        // ISO 27701 Cl. 7.2.8 (controller RoPA), unchanged 2019 → 2025.
        [
            'key' => 'gdpr.ropa_methodology',
            'topic' => 'ropa_methodology',
            'document_type' => 'methodology',
            'norm_ref' => 'GDPR Art. 30',
            'title_translation_key' => 'policy.gdpr.ropa_methodology.v1.title',
            'body_translation_key' => 'policy.gdpr.ropa_methodology.v1.body',
            'iso27701_clauses_2025' => ['7.2.8'],
            'iso27701_clauses_2019' => ['7.2.8'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO'],
            'dpo_section_required' => true,
        ],
        // 3 — DPIA Methodology (§2.3)
        // ISO 27701 Cl. 6.2 (privacy risk treatment) + 7.2.5 (DPIA).
        [
            'key' => 'gdpr.dpia_methodology',
            'topic' => 'dpia_methodology',
            'document_type' => 'methodology',
            'norm_ref' => 'GDPR Art. 35/36',
            'title_translation_key' => 'policy.gdpr.dpia_methodology.v1.title',
            'body_translation_key' => 'policy.gdpr.dpia_methodology.v1.body',
            'iso27701_clauses_2025' => ['6.2', '7.2.5'],
            'iso27701_clauses_2019' => ['6.2', '7.2.5'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO'],
            'dpo_section_required' => true,
        ],
        // 4 — Data-Subject-Rights Procedure (§2.4)
        // ISO 27701 Cl. 7.3.1–7.3.10 (data-subject obligations).
        [
            'key' => 'gdpr.dsr_procedure',
            'topic' => 'dsr_procedure',
            'document_type' => 'procedure',
            'norm_ref' => 'GDPR Art. 12-22',
            'title_translation_key' => 'policy.gdpr.dsr_procedure.v1.title',
            'body_translation_key' => 'policy.gdpr.dsr_procedure.v1.body',
            'iso27701_clauses_2025' => [
                '7.3.1', '7.3.2', '7.3.3', '7.3.4', '7.3.5',
                '7.3.6', '7.3.7', '7.3.8', '7.3.9', '7.3.10',
            ],
            'iso27701_clauses_2019' => [
                '7.3.1', '7.3.2', '7.3.3', '7.3.4', '7.3.5',
                '7.3.6', '7.3.7', '7.3.8', '7.3.9', '7.3.10',
            ],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO'],
            'dpo_section_required' => true,
        ],
        // 5 — Data Breach Notification Procedure (§2.5)
        // ISO 27701 Cl. 6.13 (2025) was a sub-clause of 6.13.1.5 in 2019.
        [
            'key' => 'gdpr.data_breach_notification_procedure',
            'topic' => 'data_breach_notification_procedure',
            'document_type' => 'procedure',
            'norm_ref' => 'GDPR Art. 33/34',
            'title_translation_key' => 'policy.gdpr.data_breach_notification_procedure.v1.title',
            'body_translation_key' => 'policy.gdpr.data_breach_notification_procedure.v1.body',
            'iso27701_clauses_2025' => ['6.13'],
            'iso27701_clauses_2019' => ['6.13.1.5'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 6 — Thin A.5.34 cross-reference host (Decision Matrix v2 row 18)
        // ISO 27701 §3.1 carries no own clause — the host is a topic-
        // specific policy stub satisfying ISO 27001 Cl. 5.2 + A.5.1 only.
        // dpo_section_required=false because the host has no privacy
        // section of its own (per §0.A — gated sections live elsewhere).
        [
            'key' => 'gdpr.iso_a534_thin_host',
            'topic' => 'iso_a534_thin_host',
            'document_type' => 'policy',
            'norm_ref' => 'A.5.34',
            'title_translation_key' => 'policy.gdpr.iso_a534_thin_host.v1.title',
            'body_translation_key' => 'policy.gdpr.iso_a534_thin_host.v1.body',
            'iso27701_clauses_2025' => [],
            'iso27701_clauses_2019' => [],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
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
            'Privacy PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
     *     iso27701_clauses_2025: list<string>,
     *     iso27701_clauses_2019: list<string>,
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
        // All privacy templates cross-reference the ISO 27001 A.5.34 host —
        // the thin host itself is the only template whose `topic`
        // *is* A.5.34. Keep the linkage explicit (not implicit via topic)
        // so downstream gap-reports can find every privacy doc by control.
        $template->setLinkedAnnexAControls(['A.5.34']);
        $template->setLinkedBausteine(null);
        $template->setLinkedBsiBausteine(null);
        $template->setBsiTier(null);
        $template->setLinkedDoraArticles(null);
        // Per §0.A of the DPO spec — every privacy template carries the
        // DPO function so the W3 function-owner-review workflow surfaces
        // the doc to the DPO inbox even when it sits inside an ISO host.
        $template->setAffectedFunctions(['dpo']);
        $template->setReviewIntervalMonths($row['review_interval_months']);
        $template->setApprovalChain($row['approval_chain']);
        $template->setIso27701Clauses2025(
            $row['iso27701_clauses_2025'] === [] ? null : $row['iso27701_clauses_2025'],
        );
        $template->setIso27701Clauses2019(
            $row['iso27701_clauses_2019'] === [] ? null : $row['iso27701_clauses_2019'],
        );
        $template->setDpoSectionRequired($row['dpo_section_required']);
        $template->setClimateChangeWording(false);
        $template->setIsActive(true);
        $template->setVersion(1);
    }
}
