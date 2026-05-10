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
 * Seeds 15 additional BSI IT-Grundschutz Bausteine as PolicyTemplates,
 * complementing the 29 Pflicht-Richtlinien handled by
 * {@see SeedBsiPolicyTemplatesCommand}.
 *
 * Scope: technical layer Bausteine from APP / SYS / NET / INF / IND that
 * are common touch-points in real-world ISMS implementations
 * (Webserver, Datenbanken, Mail, Server, Clients, Netze, Gebaeude,
 * Rechenzentrum, OT/ICS).
 *
 * Each row carries:
 *   - `standard='bsi'`
 *   - `bsi_tier` — basis (most layer-Bausteine) or standard (NET.3.2 Firewall,
 *     INF.2 RZ, IND.1 OT — heavier baseline expected)
 *   - `linked_bsi_bausteine` — Baustein-Anforderung anchors per BSI Edition
 *   - `linked_annex_a_controls` — ISO 27001:2022 Annex A cross-mapping
 *
 * Translation bodies live in `translations/policy_bsi_batch5.{de,en}.yaml`
 * (batch4 is reserved for the IAM/Kryptokonzept Themen-Richtlinien wave).
 *
 * Idempotent: running twice without `--force` is a no-op for rows that
 * already exist. With `--force` existing rows are updated in place.
 *
 * Run after deploy:
 *   php bin/console app:policy-wizard:seed-bsi-extra
 *   php bin/console app:policy-wizard:seed-bsi-extra --force
 *   php bin/console app:policy-wizard:seed-bsi-extra --dry-run
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-bsi-extra',
    description: 'Seeds 15 additional BSI Bausteine (APP/SYS/NET/INF/IND) as PolicyTemplates.',
)]
final class SeedBsiExtraBausteineCommand extends Command
{
    public const string STANDARD = 'bsi';

    /**
     * 15 additional BSI Bausteine. Topic key prefix `bsi_<layer>_<id>_<slug>`
     * deliberately differs from the 29 Pflicht-Richtlinien (which use the
     * functional topic name like `iam`, `crypto_concept`) to keep the two
     * catalogues addressable separately downstream.
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
        // 1 — APP.3.2 Webserver
        [
            'key' => 'bsi.bsi_app_3_2_web_server',
            'topic' => 'bsi_app_3_2_web_server',
            'document_type' => 'policy',
            'norm_ref' => 'APP.3.2',
            'title_translation_key' => 'policy.bsi.bsi_app_3_2_web_server.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_app_3_2_web_server.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'APP.3.2.A1', 'APP.3.2.A2', 'APP.3.2.A3', 'APP.3.2.A4',
                'APP.3.2.A5', 'APP.3.2.A6', 'APP.3.2.A7', 'APP.3.2.A11',
            ],
            'linked_annex_a_controls' => ['A.8.9', 'A.8.20', 'A.8.21', 'A.8.23', 'A.8.26'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 2 — APP.3.4 Samba
        [
            'key' => 'bsi.bsi_app_3_4_samba',
            'topic' => 'bsi_app_3_4_samba',
            'document_type' => 'policy',
            'norm_ref' => 'APP.3.4',
            'title_translation_key' => 'policy.bsi.bsi_app_3_4_samba.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_app_3_4_samba.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'APP.3.4.A1', 'APP.3.4.A2', 'APP.3.4.A3', 'APP.3.4.A4',
                'APP.3.4.A5', 'APP.3.4.A6', 'APP.3.4.A7',
            ],
            'linked_annex_a_controls' => ['A.5.15', 'A.8.2', 'A.8.20'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 3 — APP.4.3 Relationale Datenbanksysteme
        [
            'key' => 'bsi.bsi_app_4_3_relational_database',
            'topic' => 'bsi_app_4_3_relational_database',
            'document_type' => 'policy',
            'norm_ref' => 'APP.4.3',
            'title_translation_key' => 'policy.bsi.bsi_app_4_3_relational_database.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_app_4_3_relational_database.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'APP.4.3.A1', 'APP.4.3.A2', 'APP.4.3.A3', 'APP.4.3.A4',
                'APP.4.3.A5', 'APP.4.3.A6', 'APP.4.3.A7', 'APP.4.3.A8',
                'APP.4.3.A11',
            ],
            'linked_annex_a_controls' => ['A.8.2', 'A.8.13', 'A.8.15', 'A.8.24'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO'],
            'dpo_section_required' => true,
        ],
        // 4 — APP.5.3 Allgemeiner E-Mail-Client und -Server
        [
            'key' => 'bsi.bsi_app_5_3_email',
            'topic' => 'bsi_app_5_3_email',
            'document_type' => 'policy',
            'norm_ref' => 'APP.5.3',
            'title_translation_key' => 'policy.bsi.bsi_app_5_3_email.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_app_5_3_email.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'APP.5.3.A1', 'APP.5.3.A2', 'APP.5.3.A3', 'APP.5.3.A4',
                'APP.5.3.A5', 'APP.5.3.A6', 'APP.5.3.A7', 'APP.5.3.A8',
            ],
            'linked_annex_a_controls' => ['A.5.14', 'A.8.20', 'A.8.23'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO'],
            'dpo_section_required' => true,
        ],
        // 5 — SYS.1.1 Allgemeiner Server
        [
            'key' => 'bsi.bsi_sys_1_1_general_server',
            'topic' => 'bsi_sys_1_1_general_server',
            'document_type' => 'policy',
            'norm_ref' => 'SYS.1.1',
            'title_translation_key' => 'policy.bsi.bsi_sys_1_1_general_server.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_sys_1_1_general_server.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'SYS.1.1.A1', 'SYS.1.1.A2', 'SYS.1.1.A3', 'SYS.1.1.A4',
                'SYS.1.1.A5', 'SYS.1.1.A6', 'SYS.1.1.A7', 'SYS.1.1.A8',
                'SYS.1.1.A9', 'SYS.1.1.A10',
            ],
            'linked_annex_a_controls' => ['A.8.1', 'A.8.5', 'A.8.8', 'A.8.9', 'A.8.18'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 6 — SYS.1.2.2 Windows Server (2022)
        [
            'key' => 'bsi.bsi_sys_1_2_2_windows_server',
            'topic' => 'bsi_sys_1_2_2_windows_server',
            'document_type' => 'policy',
            'norm_ref' => 'SYS.1.2.2',
            'title_translation_key' => 'policy.bsi.bsi_sys_1_2_2_windows_server.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_sys_1_2_2_windows_server.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'SYS.1.2.2.A1', 'SYS.1.2.2.A2', 'SYS.1.2.2.A3', 'SYS.1.2.2.A4',
                'SYS.1.2.2.A5', 'SYS.1.2.2.A6', 'SYS.1.2.2.A7',
            ],
            'linked_annex_a_controls' => ['A.8.5', 'A.8.8', 'A.8.9'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 7 — SYS.1.3 Server unter Linux und Unix
        [
            'key' => 'bsi.bsi_sys_1_3_linux_unix_server',
            'topic' => 'bsi_sys_1_3_linux_unix_server',
            'document_type' => 'policy',
            'norm_ref' => 'SYS.1.3',
            'title_translation_key' => 'policy.bsi.bsi_sys_1_3_linux_unix_server.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_sys_1_3_linux_unix_server.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'SYS.1.3.A1', 'SYS.1.3.A2', 'SYS.1.3.A3', 'SYS.1.3.A4',
                'SYS.1.3.A5', 'SYS.1.3.A6', 'SYS.1.3.A7', 'SYS.1.3.A8',
                'SYS.1.3.A10',
            ],
            'linked_annex_a_controls' => ['A.8.5', 'A.8.8', 'A.8.9', 'A.8.18'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 8 — SYS.2.1 Allgemeiner Client
        [
            'key' => 'bsi.bsi_sys_2_1_general_client',
            'topic' => 'bsi_sys_2_1_general_client',
            'document_type' => 'policy',
            'norm_ref' => 'SYS.2.1',
            'title_translation_key' => 'policy.bsi.bsi_sys_2_1_general_client.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_sys_2_1_general_client.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'SYS.2.1.A1', 'SYS.2.1.A2', 'SYS.2.1.A3', 'SYS.2.1.A4',
                'SYS.2.1.A5', 'SYS.2.1.A6', 'SYS.2.1.A7', 'SYS.2.1.A8',
                'SYS.2.1.A9', 'SYS.2.1.A10',
            ],
            'linked_annex_a_controls' => ['A.5.10', 'A.8.1', 'A.8.5', 'A.8.7'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 9 — SYS.4.5 Wechseldatentraeger (Removable Media)
        [
            'key' => 'bsi.bsi_sys_4_5_storage_systems',
            'topic' => 'bsi_sys_4_5_storage_systems',
            'document_type' => 'policy',
            'norm_ref' => 'SYS.4.5',
            'title_translation_key' => 'policy.bsi.bsi_sys_4_5_storage_systems.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_sys_4_5_storage_systems.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'SYS.4.5.A1', 'SYS.4.5.A2', 'SYS.4.5.A3', 'SYS.4.5.A4',
                'SYS.4.5.A5', 'SYS.4.5.A6',
            ],
            'linked_annex_a_controls' => ['A.7.10', 'A.7.14', 'A.8.10', 'A.8.12', 'A.8.24'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO'],
            'dpo_section_required' => true,
        ],
        // 10 — NET.1.1 Netzarchitektur und -design
        [
            'key' => 'bsi.bsi_net_1_1_network_architecture',
            'topic' => 'bsi_net_1_1_network_architecture',
            'document_type' => 'programme',
            'norm_ref' => 'NET.1.1',
            'title_translation_key' => 'policy.bsi.bsi_net_1_1_network_architecture.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_net_1_1_network_architecture.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'NET.1.1.A1', 'NET.1.1.A2', 'NET.1.1.A3', 'NET.1.1.A4',
                'NET.1.1.A5', 'NET.1.1.A6', 'NET.1.1.A7', 'NET.1.1.A8',
            ],
            'linked_annex_a_controls' => ['A.8.20', 'A.8.21', 'A.8.22'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 11 — NET.3.1 Router und Switches
        [
            'key' => 'bsi.bsi_net_3_1_router_switches',
            'topic' => 'bsi_net_3_1_router_switches',
            'document_type' => 'policy',
            'norm_ref' => 'NET.3.1',
            'title_translation_key' => 'policy.bsi.bsi_net_3_1_router_switches.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_net_3_1_router_switches.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'NET.3.1.A1', 'NET.3.1.A2', 'NET.3.1.A3', 'NET.3.1.A4',
                'NET.3.1.A5', 'NET.3.1.A6', 'NET.3.1.A7',
            ],
            'linked_annex_a_controls' => ['A.8.5', 'A.8.20', 'A.8.21'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 12 — NET.3.2 Firewall (heightened tier)
        [
            'key' => 'bsi.bsi_net_3_2_firewall',
            'topic' => 'bsi_net_3_2_firewall',
            'document_type' => 'policy',
            'norm_ref' => 'NET.3.2',
            'title_translation_key' => 'policy.bsi.bsi_net_3_2_firewall.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_net_3_2_firewall.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'NET.3.2.A1', 'NET.3.2.A2', 'NET.3.2.A3', 'NET.3.2.A4',
                'NET.3.2.A5', 'NET.3.2.A6', 'NET.3.2.A7', 'NET.3.2.A8',
                'NET.3.2.A9', 'NET.3.2.A10', 'NET.3.2.A11',
            ],
            'linked_annex_a_controls' => ['A.8.20', 'A.8.21', 'A.8.22', 'A.8.23'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 13 — INF.1 Allgemeines Gebaeude
        [
            'key' => 'bsi.bsi_inf_1_general_building',
            'topic' => 'bsi_inf_1_general_building',
            'document_type' => 'policy',
            'norm_ref' => 'INF.1',
            'title_translation_key' => 'policy.bsi.bsi_inf_1_general_building.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_inf_1_general_building.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'INF.1.A1', 'INF.1.A2', 'INF.1.A3', 'INF.1.A4',
                'INF.1.A5', 'INF.1.A6', 'INF.1.A7', 'INF.1.A8',
                'INF.1.A9', 'INF.1.A10', 'INF.1.A11',
            ],
            'linked_annex_a_controls' => ['A.7.1', 'A.7.2', 'A.7.3', 'A.7.5', 'A.7.6', 'A.7.7'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_FACILITIES'],
            'dpo_section_required' => false,
        ],
        // 14 — INF.2 Rechenzentrum sowie Serverraum (heightened tier)
        [
            'key' => 'bsi.bsi_inf_2_data_center',
            'topic' => 'bsi_inf_2_data_center',
            'document_type' => 'programme',
            'norm_ref' => 'INF.2',
            'title_translation_key' => 'policy.bsi.bsi_inf_2_data_center.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_inf_2_data_center.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'INF.2.A1', 'INF.2.A2', 'INF.2.A3', 'INF.2.A4',
                'INF.2.A5', 'INF.2.A6', 'INF.2.A7', 'INF.2.A8',
                'INF.2.A9', 'INF.2.A10', 'INF.2.A11', 'INF.2.A12',
            ],
            'linked_annex_a_controls' => ['A.7.1', 'A.7.4', 'A.7.5', 'A.7.8', 'A.7.11', 'A.7.12'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_FACILITIES', 'ROLE_BCM'],
            'dpo_section_required' => false,
        ],
        // 15 — IND.1 Prozessleit- und Automatisierungstechnik (OT/ICS,
        //      heightened tier — Air-Gap, Lifecycle 15+ Jahre, IEC 62443)
        [
            'key' => 'bsi.bsi_ind_1_2_ot_network_architecture',
            'topic' => 'bsi_ind_1_2_ot_network_architecture',
            'document_type' => 'programme',
            'norm_ref' => 'IND.1',
            'title_translation_key' => 'policy.bsi.bsi_ind_1_2_ot_network_architecture.v1.title',
            'body_translation_key' => 'policy.bsi.bsi_ind_1_2_ot_network_architecture.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'IND.1.A1', 'IND.1.A2', 'IND.1.A3', 'IND.1.A4',
                'IND.1.A5', 'IND.1.A6', 'IND.1.A7', 'IND.1.A8',
                'IND.1.A9', 'IND.1.A10', 'IND.1.A11', 'IND.1.A12',
                'IND.1.A13', 'IND.1.A14', 'IND.1.A15',
            ],
            'linked_annex_a_controls' => ['A.5.7', 'A.7.4', 'A.8.20', 'A.8.21', 'A.8.32'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_OT_LEAD', 'ROLE_BCM'],
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
            'BSI extra-Bausteine seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
     * Reduce a list of Baustein-Anforderung anchors (`SYS.1.1.A1`, `SYS.1.1.A2`)
     * to the unique Baustein roots (`SYS.1.1`). Keeps insertion order and
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
