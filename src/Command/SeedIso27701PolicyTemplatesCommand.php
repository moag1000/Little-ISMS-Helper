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
 * Seeds the 10 ISO 27701:2025 PIMS-Pflicht-Dokumente.
 *
 * ISO 27701 is the Privacy Information Management System extension of
 * ISO 27001 — every clause assumes a working ISMS as a prerequisite.
 * The 10 templates seeded here cover the dual-role catalogue (Cl. 7
 * Controller + Cl. 8 Processor) plus the cross-cutting management
 * documents (PII inventory / RoPA, consent + lawful basis, data
 * subject rights, PIA/DPIA methodology, breach notification, third
 * country transfers including Schrems-II TIAs, retention & disposal).
 *
 * Standard = `iso27701`. Each row carries:
 *   - `linkedAnnexAControls = ['A.5.34']` — every PIMS document
 *     cross-references the ISO 27001 A.5.34 host (Privacy + PII).
 *   - `iso27701_clauses_2025` AND `iso27701_clauses_2019` — the 2019
 *     mapping is preserved for tenants still on the legacy audit
 *     cycle (`iso27701.version=2019`).
 *   - `affected_functions = ['dpo']` — surfaces every PIMS doc to
 *     the DPO inbox via the function-owner-review workflow gate.
 *   - `approval_chain` — DPO is mandatory; CISO + Top-Mgmt where the
 *     document touches enterprise-level policy or legal exposure.
 *   - `dpo_section_required = true` — every PIMS doc has a gated
 *     DPO sign-off section per Art. 38 Abs. 3 DSGVO independence.
 *
 * Idempotent: re-running without `--force` is a no-op for rows that
 * already exist. With `--force` existing rows are updated in place.
 * `--dry-run` reports what would change without writing.
 *
 * Run after deploy:
 *   php bin/console app:policy-wizard:seed-iso27701
 *   php bin/console app:policy-wizard:seed-iso27701 --force
 *   php bin/console app:policy-wizard:seed-iso27701 --dry-run
 *
 * Mirrors {@see SeedPrivacyPolicyTemplatesCommand} (W6-B) and
 * {@see SeedDoraPolicyTemplatesCommand} (W4-A) in shape so downstream
 * wizard tooling can treat all standards uniformly.
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-iso27701',
    description: 'Seeds the 10 ISO 27701:2025 PIMS-Pflicht-Policy-Templates.',
)]
final class SeedIso27701PolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'iso27701';

    /**
     * Canonical 10-row catalogue. Translation keys follow the
     * `policy.iso27701.<topic>.v1.{title,body}` scheme; bodies live
     * in the `policy_iso27701.{de,en}.yaml` translation domain.
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
        // 1 — PIMS Top-Level Policy (Cl. 5.2 + 6.1.1)
        [
            'key' => 'iso27701.pims_top_level',
            'topic' => 'pims_top_level',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 27701:2025 Cl. 5.2 + 6.1.1',
            'title_translation_key' => 'policy.iso27701.pims_top_level.v1.title',
            'body_translation_key' => 'policy.iso27701.pims_top_level.v1.body',
            'iso27701_clauses_2025' => ['5.2', '6.1.1'],
            'iso27701_clauses_2019' => ['5.2', '6.1.1'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 2 — Pflichten als Verantwortlicher (Cl. 7.2 + Anhang A)
        [
            'key' => 'iso27701.role_data_controller_responsibilities',
            'topic' => 'role_data_controller_responsibilities',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 27701:2025 Cl. 7.2 + Annex A',
            'title_translation_key' => 'policy.iso27701.role_data_controller_responsibilities.v1.title',
            'body_translation_key' => 'policy.iso27701.role_data_controller_responsibilities.v1.body',
            'iso27701_clauses_2025' => ['7.2', '7.2.1', '7.2.2', '7.2.3', '7.2.4', '7.2.5', '7.2.6', '7.2.7', '7.2.8'],
            'iso27701_clauses_2019' => ['7.2', '7.2.1', '7.2.2', '7.2.3', '7.2.4', '7.2.5', '7.2.6', '7.2.7', '7.2.8'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 3 — Pflichten als Auftragsverarbeiter (Cl. 8.2 + Anhang B)
        [
            'key' => 'iso27701.role_data_processor_responsibilities',
            'topic' => 'role_data_processor_responsibilities',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 27701:2025 Cl. 8.2 + Annex B',
            'title_translation_key' => 'policy.iso27701.role_data_processor_responsibilities.v1.title',
            'body_translation_key' => 'policy.iso27701.role_data_processor_responsibilities.v1.body',
            'iso27701_clauses_2025' => ['8.2', '8.2.1', '8.2.2', '8.2.3', '8.2.4', '8.2.5', '8.2.6'],
            'iso27701_clauses_2019' => ['8.2', '8.2.1', '8.2.2', '8.2.3', '8.2.4', '8.2.5', '8.2.6'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 4 — PII-Inventar + Verarbeitungsverzeichnis (Cl. 7.2.1 + 8.2.1, DSGVO Art. 30)
        [
            'key' => 'iso27701.pii_inventory_records_processing',
            'topic' => 'pii_inventory_records_processing',
            'document_type' => 'methodology',
            'norm_ref' => 'ISO 27701:2025 Cl. 7.2.1 + 8.2.1 / GDPR Art. 30',
            'title_translation_key' => 'policy.iso27701.pii_inventory_records_processing.v1.title',
            'body_translation_key' => 'policy.iso27701.pii_inventory_records_processing.v1.body',
            'iso27701_clauses_2025' => ['7.2.1', '7.2.8', '8.2.1', '8.2.6'],
            'iso27701_clauses_2019' => ['7.2.1', '7.2.8', '8.2.1', '8.2.6'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO'],
            'dpo_section_required' => true,
        ],
        // 5 — Einwilligung + Rechtsgrundlage (Cl. 7.2.2-7.2.5, DSGVO Art. 6+7+9)
        [
            'key' => 'iso27701.consent_management_legal_basis',
            'topic' => 'consent_management_legal_basis',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 27701:2025 Cl. 7.2.2-7.2.5 / GDPR Art. 6+7+9',
            'title_translation_key' => 'policy.iso27701.consent_management_legal_basis.v1.title',
            'body_translation_key' => 'policy.iso27701.consent_management_legal_basis.v1.body',
            'iso27701_clauses_2025' => ['7.2.2', '7.2.3', '7.2.4', '7.2.5'],
            'iso27701_clauses_2019' => ['7.2.2', '7.2.3', '7.2.4', '7.2.5'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO'],
            'dpo_section_required' => true,
        ],
        // 6 — Betroffenenrechte (Cl. 7.3, DSGVO Art. 12-22)
        [
            'key' => 'iso27701.data_subject_rights_procedure',
            'topic' => 'data_subject_rights_procedure',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 27701:2025 Cl. 7.3 / GDPR Art. 12-22',
            'title_translation_key' => 'policy.iso27701.data_subject_rights_procedure.v1.title',
            'body_translation_key' => 'policy.iso27701.data_subject_rights_procedure.v1.body',
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
        // 7 — PIA/DSFA-Methodik (Cl. 7.2.5, DSGVO Art. 35+36)
        [
            'key' => 'iso27701.pia_dpia_methodology',
            'topic' => 'pia_dpia_methodology',
            'document_type' => 'methodology',
            'norm_ref' => 'ISO 27701:2025 Cl. 7.2.5 / GDPR Art. 35+36',
            'title_translation_key' => 'policy.iso27701.pia_dpia_methodology.v1.title',
            'body_translation_key' => 'policy.iso27701.pia_dpia_methodology.v1.body',
            'iso27701_clauses_2025' => ['6.2', '7.2.5'],
            'iso27701_clauses_2019' => ['6.2', '7.2.5'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO'],
            'dpo_section_required' => true,
        ],
        // 8 — PII-Breach-Meldung (Cl. 6.13, DSGVO Art. 33+34)
        [
            'key' => 'iso27701.data_breach_notification',
            'topic' => 'data_breach_notification',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 27701:2025 Cl. 6.13 / GDPR Art. 33+34',
            'title_translation_key' => 'policy.iso27701.data_breach_notification.v1.title',
            'body_translation_key' => 'policy.iso27701.data_breach_notification.v1.body',
            'iso27701_clauses_2025' => ['6.13'],
            'iso27701_clauses_2019' => ['6.13.1.5'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 9 — Drittland-Uebermittlung (Cl. 7.5 + 8.5, DSGVO Art. 44-49 incl. Schrems II)
        [
            'key' => 'iso27701.third_party_transfer_policy',
            'topic' => 'third_party_transfer_policy',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 27701:2025 Cl. 7.5 + 8.5 / GDPR Art. 44-49',
            'title_translation_key' => 'policy.iso27701.third_party_transfer_policy.v1.title',
            'body_translation_key' => 'policy.iso27701.third_party_transfer_policy.v1.body',
            'iso27701_clauses_2025' => ['7.5', '7.5.1', '7.5.2', '7.5.3', '7.5.4', '8.5', '8.5.1', '8.5.2', '8.5.3', '8.5.4'],
            'iso27701_clauses_2019' => ['7.5', '7.5.1', '7.5.2', '7.5.3', '7.5.4', '8.5', '8.5.1', '8.5.2', '8.5.3', '8.5.4'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 10 — Aufbewahrung + Loeschung (Cl. 7.4 + 8.4, DSGVO Art. 5+17, BDSG)
        [
            'key' => 'iso27701.retention_disposal_policy',
            'topic' => 'retention_disposal_policy',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 27701:2025 Cl. 7.4 + 8.4 / GDPR Art. 5+17',
            'title_translation_key' => 'policy.iso27701.retention_disposal_policy.v1.title',
            'body_translation_key' => 'policy.iso27701.retention_disposal_policy.v1.body',
            'iso27701_clauses_2025' => ['7.4', '7.4.1', '7.4.2', '7.4.3', '7.4.4', '7.4.5', '7.4.6', '7.4.7', '7.4.8', '7.4.9', '8.4', '8.4.1', '8.4.2', '8.4.3'],
            'iso27701_clauses_2019' => ['7.4', '7.4.1', '7.4.2', '7.4.3', '7.4.4', '7.4.5', '7.4.6', '7.4.7', '7.4.8', '7.4.9', '8.4', '8.4.1', '8.4.2', '8.4.3'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_DPO', 'ROLE_CISO'],
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
            'ISO 27701 PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
        // Every PIMS document cross-references the ISO 27001 A.5.34
        // Privacy + PII control — that is the link from the ISMS host
        // into the PIMS catalogue.
        $template->setLinkedAnnexAControls(['A.5.34']);
        $template->setLinkedBausteine(null);
        $template->setLinkedBsiBausteine(null);
        $template->setBsiTier(null);
        $template->setLinkedDoraArticles(null);
        // Surface every PIMS doc to the DPO inbox via the W3
        // function-owner-review workflow gate.
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
