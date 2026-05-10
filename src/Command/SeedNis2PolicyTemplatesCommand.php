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
 * NIS2 Policy-Template Seed — 12 Pflicht-Richtlinien decken die in
 * Art. 21 Abs. 2 NIS2-Richtlinie (EU 2022/2555) genannten technischen
 * und organisatorischen Mindestmassnahmen sowie das Meldeverfahren
 * gemaess Art. 23 NIS2-RL ab. Deutsche Umsetzung: NIS2UmsuCG (Stand
 * Bundestags-Beschluss 13.11.2025), BSIG §§ 8a/8b.
 *
 * Mapping nach ISO/IEC 27001:2022 Annex A erfolgt ueber den bestehenden
 * Cross-Mapping-Katalog ({@see SeedNis2Iso27001MappingsCommand}); diese
 * Datei dupliziert es bewusst NICHT, sondern setzt nur die Annex-A-Refs,
 * die das jeweilige PolicyTemplate als Evidenz nachweisen kann (data-
 * reuse Pattern: ein Annex-A-Control deckt mehrere Frameworks ab).
 *
 * Idempotent — running the command twice without `--force` is a no-op
 * for rows that already exist. With `--force` existing rows are updated
 * in place. `--dry-run` reports what would change without writing.
 *
 * Run as one-off after deploy:
 *   php bin/console app:policy-wizard:seed-nis2
 *   php bin/console app:policy-wizard:seed-nis2 --force
 *   php bin/console app:policy-wizard:seed-nis2 --dry-run
 *
 * Mirrors {@see SeedDoraPolicyTemplatesCommand} (W4-A) and
 * {@see SeedBsiPolicyTemplatesCommand} (W5-A) in shape so downstream
 * wizard tooling can treat all four standards uniformly.
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-nis2',
    description: 'Seeds the 12 NIS2 Pflicht-Richtlinien (Art. 21 + Art. 23 NIS2-RL).',
)]
final class SeedNis2PolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'nis2';

    /**
     * Canonical 12-row catalogue. Body / title translation keys folgen
     * dem §8.7 Versioning-Schema (`policy.nis2.<topic>.v1.body`).
     *
     * @var list<array{
     *     key: string,
     *     topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     title_translation_key: string,
     *     body_translation_key: string,
     *     linked_annex_a_controls: list<string>,
     *     linked_dora_articles: list<string>,
     *     affected_functions: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: bool,
     * }>
     */
    public const array TEMPLATES = [
        // 1 — Cybersecurity-Governance (Art. 20 + Art. 21 Abs. 1)
        [
            'key' => 'nis2.governance_framework',
            'topic' => 'nis2_governance_framework',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 20-21 Abs. 1',
            'title_translation_key' => 'policy.nis2.nis2_governance_framework.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_governance_framework.v1.body',
            'linked_annex_a_controls' => ['A.5.1', 'A.5.2', 'A.5.3', 'A.5.4'],
            'linked_dora_articles' => [],
            'affected_functions' => ['TOP_MGMT', 'IT_OPERATIONS', 'RISK_MANAGEMENT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 2 — Risikomanagement-Konzept (Art. 21 Abs. 2 lit. a)
        [
            'key' => 'nis2.risk_management_policy',
            'topic' => 'nis2_risk_management_policy',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. a',
            'title_translation_key' => 'policy.nis2.nis2_risk_management_policy.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_risk_management_policy.v1.body',
            'linked_annex_a_controls' => ['A.5.1', 'A.5.7', 'A.5.8'],
            'linked_dora_articles' => [],
            'affected_functions' => ['RISK_MANAGEMENT', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 3 — Vorfallsbearbeitung (Art. 21 Abs. 2 lit. b + Art. 23 Meldefristen)
        [
            'key' => 'nis2.incident_handling_policy',
            'topic' => 'nis2_incident_handling_policy',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. b + Art. 23',
            'title_translation_key' => 'policy.nis2.nis2_incident_handling_policy.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_incident_handling_policy.v1.body',
            'linked_annex_a_controls' => ['A.5.24', 'A.5.25', 'A.5.26', 'A.5.27', 'A.5.28', 'A.6.8'],
            'linked_dora_articles' => [],
            'affected_functions' => ['IT_OPERATIONS', 'TOP_MGMT', 'LEGAL'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 4 — Business-Continuity + Krisenmanagement (Art. 21 Abs. 2 lit. c)
        [
            'key' => 'nis2.business_continuity_crisis_policy',
            'topic' => 'nis2_business_continuity_crisis_policy',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. c',
            'title_translation_key' => 'policy.nis2.nis2_business_continuity_crisis_policy.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_business_continuity_crisis_policy.v1.body',
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30', 'A.8.13', 'A.8.14'],
            'linked_dora_articles' => [],
            'affected_functions' => ['IT_OPERATIONS', 'TOP_MGMT', 'BCM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 5 — Lieferkettensicherheit (Art. 21 Abs. 2 lit. d)
        [
            'key' => 'nis2.supply_chain_security_policy',
            'topic' => 'nis2_supply_chain_security_policy',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. d',
            'title_translation_key' => 'policy.nis2.nis2_supply_chain_security_policy.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_supply_chain_security_policy.v1.body',
            'linked_annex_a_controls' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
            'linked_dora_articles' => [],
            'affected_functions' => ['PROCUREMENT', 'IT_OPERATIONS', 'RISK_MANAGEMENT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_PROCUREMENT'],
            'dpo_section_required' => false,
        ],
        // 6 — Sicherheit bei Beschaffung/Entwicklung/Wartung (Art. 21 Abs. 2 lit. e)
        [
            'key' => 'nis2.acquisition_development_security',
            'topic' => 'nis2_acquisition_development_security',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. e',
            'title_translation_key' => 'policy.nis2.nis2_acquisition_development_security.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_acquisition_development_security.v1.body',
            'linked_annex_a_controls' => ['A.8.25', 'A.8.26', 'A.8.27', 'A.8.28', 'A.8.29', 'A.8.30', 'A.8.31', 'A.8.32'],
            'linked_dora_articles' => [],
            'affected_functions' => ['IT_OPERATIONS', 'PROCUREMENT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 7 — Wirksamkeitsbewertung (Art. 21 Abs. 2 lit. f)
        [
            'key' => 'nis2.assessment_effectiveness_policy',
            'topic' => 'nis2_assessment_effectiveness_policy',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. f',
            'title_translation_key' => 'policy.nis2.nis2_assessment_effectiveness_policy.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_assessment_effectiveness_policy.v1.body',
            'linked_annex_a_controls' => ['A.5.35', 'A.5.36', 'A.8.34'],
            'linked_dora_articles' => [],
            'affected_functions' => ['INTERNAL_AUDIT', 'RISK_MANAGEMENT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 8 — Cyber-Hygiene + Schulung (Art. 21 Abs. 2 lit. g)
        [
            'key' => 'nis2.basic_cyber_hygiene_training',
            'topic' => 'nis2_basic_cyber_hygiene_training',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. g',
            'title_translation_key' => 'policy.nis2.nis2_basic_cyber_hygiene_training.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_basic_cyber_hygiene_training.v1.body',
            'linked_annex_a_controls' => ['A.6.3', 'A.7.7', 'A.8.1'],
            'linked_dora_articles' => [],
            'affected_functions' => ['HR', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_HR'],
            'dpo_section_required' => false,
        ],
        // 9 — Kryptographie + Schluesselmanagement (Art. 21 Abs. 2 lit. h)
        [
            'key' => 'nis2.cryptography_policy',
            'topic' => 'nis2_cryptography_policy',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. h',
            'title_translation_key' => 'policy.nis2.nis2_cryptography_policy.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_cryptography_policy.v1.body',
            'linked_annex_a_controls' => ['A.8.24'],
            'linked_dora_articles' => [],
            'affected_functions' => ['IT_OPERATIONS'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 10 — Zugriffskontrolle + Asset-Management (Art. 21 Abs. 2 lit. i)
        [
            'key' => 'nis2.access_control_asset_mgmt',
            'topic' => 'nis2_access_control_asset_mgmt',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. i',
            'title_translation_key' => 'policy.nis2.nis2_access_control_asset_mgmt.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_access_control_asset_mgmt.v1.body',
            'linked_annex_a_controls' => ['A.5.9', 'A.5.10', 'A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2', 'A.8.3'],
            'linked_dora_articles' => [],
            'affected_functions' => ['IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 11 — MFA + sichere Kommunikation (Art. 21 Abs. 2 lit. j)
        [
            'key' => 'nis2.mfa_communication_policy',
            'topic' => 'nis2_mfa_communication_policy',
            'document_type' => 'policy',
            'norm_ref' => 'NIS2 Art. 21 Abs. 2 lit. j',
            'title_translation_key' => 'policy.nis2.nis2_mfa_communication_policy.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_mfa_communication_policy.v1.body',
            'linked_annex_a_controls' => ['A.5.14', 'A.8.5', 'A.8.20', 'A.8.21'],
            'linked_dora_articles' => [],
            'affected_functions' => ['IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 12 — Meldeverfahren BSI/Aufsicht (Art. 23 NIS2-RL + NIS2UmsuCG)
        [
            'key' => 'nis2.reporting_obligations_procedure',
            'topic' => 'nis2_reporting_obligations_procedure',
            'document_type' => 'procedure',
            'norm_ref' => 'NIS2 Art. 23 + NIS2UmsuCG',
            'title_translation_key' => 'policy.nis2.nis2_reporting_obligations_procedure.v1.title',
            'body_translation_key' => 'policy.nis2.nis2_reporting_obligations_procedure.v1.body',
            'linked_annex_a_controls' => ['A.5.5', 'A.5.6', 'A.5.24', 'A.5.25', 'A.5.26', 'A.6.8'],
            'linked_dora_articles' => [],
            'affected_functions' => ['IT_OPERATIONS', 'LEGAL', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_LEGAL', 'ROLE_TOP_MGMT'],
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
            'NIS2 PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
        $template->setLinkedAnnexAControls(
            $row['linked_annex_a_controls'] === [] ? null : $row['linked_annex_a_controls'],
        );
        $template->setLinkedDoraArticles(
            $row['linked_dora_articles'] === [] ? null : $row['linked_dora_articles'],
        );
        $template->setLinkedBausteine(null);
        $template->setLinkedBsiBausteine(null);
        $template->setBsiTier(null);
        $template->setAffectedFunctions(
            $row['affected_functions'] === [] ? null : $row['affected_functions'],
        );
        $template->setReviewIntervalMonths($row['review_interval_months']);
        $template->setApprovalChain($row['approval_chain']);
        $template->setDpoSectionRequired($row['dpo_section_required']);
        $template->setClimateChangeWording(false);
        $template->setIsActive(true);
        $template->setVersion(1);
    }
}
