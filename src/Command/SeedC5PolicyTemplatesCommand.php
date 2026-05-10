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
 * Policy-Wizard — seed the 12 BSI C5:2026 cloud-security policy templates.
 *
 * Source of truth: BSI C5:2020 + C5:2026-Erweiterung (Cloud Computing
 * Compliance Criteria Catalogue), 17 Kriterienbereiche reduced to the
 * 12 most policy-bearing domains (OIS, SPP, PSS, AM, PS, OPS, IDM, KOS,
 * COS, PI, BPM, COM). Cross-mapped to ISO/IEC 27017 (cloud-specific
 * controls), ISO/IEC 27018 (PII in public clouds), ISO/IEC 27001:2022
 * Annex A and BSI 200-2 OPS.2.2 / SYS.x Bausteine.
 *
 * Every row carries:
 *   • `standard='c5'`
 *   • `bsi_tier` — basis (Basiskriterien, Mindestumsetzung) | standard
 *     (Zusatzkriterien, kundenspezifischer Wahlkatalog)
 *   • `linked_bsi_bausteine` — primary C5:2026 criteria IDs + supporting
 *     IT-Grundschutz Bausteine (OPS.2.2 cloud usage, SYS.1.6 containers,
 *     CON.1 crypto etc.)
 *   • `linked_annex_a_controls` — ISO 27001:2022 Annex A cross-mapping
 *   • `review_interval_months` — 12 months default, 24 months for
 *     organisation/SPP/PI/COM where governance cadence is yearly-plus
 *
 * Idempotent: running twice without `--force` is a no-op for rows that
 * already exist. With `--force` existing rows are updated in place.
 * `--dry-run` reports what would change without writing.
 *
 * Run after deploy:
 *   php bin/console app:policy-wizard:seed-c5
 *   php bin/console app:policy-wizard:seed-c5 --force
 *   php bin/console app:policy-wizard:seed-c5 --dry-run
 *
 * Mirrors {@see SeedBsiPolicyTemplatesCommand} (W5-A) in shape so the
 * downstream wizard tooling can treat both BSI standards uniformly.
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-c5',
    description: 'Seeds the 12 BSI C5:2026 cloud-security policy templates.',
)]
final class SeedC5PolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'c5';

    /**
     * Canonical 12-row catalogue.
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
        // 1 — Organisation der Informationssicherheit (OIS)
        [
            'key' => 'c5.organisation_information_security',
            'topic' => 'c5_organisation_information_security',
            'document_type' => 'policy',
            'norm_ref' => 'C5 OIS-01',
            'title_translation_key' => 'policy.c5.c5_organisation_information_security.v1.title',
            'body_translation_key' => 'policy.c5.c5_organisation_information_security.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['C5:OIS-01', 'C5:OIS-02', 'C5:OIS-03', 'C5:OIS-04', 'C5:OIS-05', 'C5:OIS-06', 'C5:OIS-07', 'OPS.2.2.A1'],
            'linked_annex_a_controls' => ['A.5.1', 'A.5.2', 'A.5.3', 'A.5.4'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 2 — Sicherheits-Policies + Verfahren (SPP)
        [
            'key' => 'c5.security_policies_procedures',
            'topic' => 'c5_security_policies_procedures',
            'document_type' => 'policy',
            'norm_ref' => 'C5 SP-01',
            'title_translation_key' => 'policy.c5.c5_security_policies_procedures.v1.title',
            'body_translation_key' => 'policy.c5.c5_security_policies_procedures.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['C5:SP-01', 'C5:SP-02', 'C5:SP-03', 'C5:SP-04', 'OPS.2.2.A1'],
            'linked_annex_a_controls' => ['A.5.1'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 3 — Personalsicherheit (PSS)
        [
            'key' => 'c5.personnel_security',
            'topic' => 'c5_personnel_security',
            'document_type' => 'policy',
            'norm_ref' => 'C5 HR-01',
            'title_translation_key' => 'policy.c5.c5_personnel_security.v1.title',
            'body_translation_key' => 'policy.c5.c5_personnel_security.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['C5:HR-01', 'C5:HR-02', 'C5:HR-03', 'C5:HR-04', 'C5:HR-05', 'C5:HR-06', 'ORP.2.A1', 'ORP.3.A1'],
            'linked_annex_a_controls' => ['A.6.1', 'A.6.2', 'A.6.3', 'A.6.5', 'A.6.6'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_HR', 'ROLE_CISO'],
            'dpo_section_required' => true,
        ],
        // 4 — Asset-Management (AM)
        [
            'key' => 'c5.asset_management',
            'topic' => 'c5_asset_management',
            'document_type' => 'policy',
            'norm_ref' => 'C5 AM-01',
            'title_translation_key' => 'policy.c5.c5_asset_management.v1.title',
            'body_translation_key' => 'policy.c5.c5_asset_management.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['C5:AM-01', 'C5:AM-02', 'C5:AM-03', 'C5:AM-04', 'C5:AM-05', 'OPS.2.2.A4'],
            'linked_annex_a_controls' => ['A.5.9', 'A.5.10', 'A.5.11', 'A.5.12', 'A.5.13'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 5 — Physische Sicherheit (PS)
        [
            'key' => 'c5.physical_security',
            'topic' => 'c5_physical_security',
            'document_type' => 'policy',
            'norm_ref' => 'C5 PS-01',
            'title_translation_key' => 'policy.c5.c5_physical_security.v1.title',
            'body_translation_key' => 'policy.c5.c5_physical_security.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'C5:PS-01', 'C5:PS-02', 'C5:PS-03', 'C5:PS-04', 'C5:PS-05',
                'C5:PS-06', 'C5:PS-07', 'C5:PS-08', 'C5:PS-09', 'C5:PS-10', 'C5:PS-11',
                'INF.1', 'INF.2',
            ],
            'linked_annex_a_controls' => ['A.7.1', 'A.7.2', 'A.7.3', 'A.7.4', 'A.7.5', 'A.7.8'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 6 — Betriebssicherheit (OPS)
        [
            'key' => 'c5.operations_security',
            'topic' => 'c5_operations_security',
            'document_type' => 'policy',
            'norm_ref' => 'C5 OPS-01',
            'title_translation_key' => 'policy.c5.c5_operations_security.v1.title',
            'body_translation_key' => 'policy.c5.c5_operations_security.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'C5:OPS-01', 'C5:OPS-02', 'C5:OPS-03', 'C5:OPS-04', 'C5:OPS-05',
                'C5:OPS-06', 'C5:OPS-07', 'C5:OPS-08', 'C5:OPS-09', 'C5:OPS-10',
                'C5:OPS-11', 'C5:OPS-12', 'C5:OPS-13',
                'OPS.1.1.3', 'OPS.1.1.4', 'OPS.1.1.5',
            ],
            'linked_annex_a_controls' => ['A.8.6', 'A.8.7', 'A.8.8', 'A.8.9', 'A.8.13', 'A.8.15', 'A.8.16', 'A.8.32'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 7 — Identitaets- + Zugriffsmanagement (IDM)
        [
            'key' => 'c5.identity_access_management',
            'topic' => 'c5_identity_access_management',
            'document_type' => 'policy',
            'norm_ref' => 'C5 IDM-01',
            'title_translation_key' => 'policy.c5.c5_identity_access_management.v1.title',
            'body_translation_key' => 'policy.c5.c5_identity_access_management.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'C5:IDM-01', 'C5:IDM-02', 'C5:IDM-03', 'C5:IDM-04', 'C5:IDM-05',
                'C5:IDM-06', 'C5:IDM-07', 'C5:IDM-08', 'C5:IDM-09',
                'ORP.4.A1', 'ORP.4.A8',
            ],
            'linked_annex_a_controls' => ['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2', 'A.8.5'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 8 — Kryptographie + Schluesselmanagement (KOS — was CRY in C5:2020)
        [
            'key' => 'c5.cryptography_key_management',
            'topic' => 'c5_cryptography_key_management',
            'document_type' => 'programme',
            'norm_ref' => 'C5 CRY-01',
            'title_translation_key' => 'policy.c5.c5_cryptography_key_management.v1.title',
            'body_translation_key' => 'policy.c5.c5_cryptography_key_management.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => ['C5:CRY-01', 'C5:CRY-02', 'C5:CRY-03', 'C5:CRY-04', 'CON.1.A1', 'CON.1.A6'],
            'linked_annex_a_controls' => ['A.8.24'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 9 — Kommunikationssicherheit (COS — was KOS in C5:2020)
        [
            'key' => 'c5.communication_security',
            'topic' => 'c5_communication_security',
            'document_type' => 'policy',
            'norm_ref' => 'C5 KOS-01',
            'title_translation_key' => 'policy.c5.c5_communication_security.v1.title',
            'body_translation_key' => 'policy.c5.c5_communication_security.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'C5:KOS-01', 'C5:KOS-02', 'C5:KOS-03', 'C5:KOS-04',
                'C5:KOS-05', 'C5:KOS-06', 'C5:KOS-07',
                'NET.1.1', 'NET.3.1',
            ],
            'linked_annex_a_controls' => ['A.8.20', 'A.8.21', 'A.8.22', 'A.8.23'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => false,
        ],
        // 10 — Portabilitaet + Interoperabilitaet (PI)
        [
            'key' => 'c5.portability_interoperability',
            'topic' => 'c5_portability_interoperability',
            'document_type' => 'policy',
            'norm_ref' => 'C5 PI-01',
            'title_translation_key' => 'policy.c5.c5_portability_interoperability.v1.title',
            'body_translation_key' => 'policy.c5.c5_portability_interoperability.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => ['C5:PI-01', 'C5:PI-02', 'C5:PI-03', 'C5:PI-04', 'OPS.2.2.A12'],
            'linked_annex_a_controls' => ['A.5.23', 'A.5.30'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_PROCUREMENT'],
            'dpo_section_required' => false,
        ],
        // 11 — Beschaffung + Lieferanten-Mgmt (BPM — combines DEV+DLL)
        [
            'key' => 'c5.procurement_supplier_management',
            'topic' => 'c5_procurement_supplier_management',
            'document_type' => 'policy',
            'norm_ref' => 'C5 DLL-01',
            'title_translation_key' => 'policy.c5.c5_procurement_supplier_management.v1.title',
            'body_translation_key' => 'policy.c5.c5_procurement_supplier_management.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => [
                'C5:DLL-01', 'C5:DLL-02', 'C5:DLL-03', 'C5:DLL-04', 'C5:DLL-05', 'C5:DLL-06',
                'OPS.2.3.A1', 'OPS.2.3.A4',
            ],
            'linked_annex_a_controls' => ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_PROCUREMENT'],
            'dpo_section_required' => true,
        ],
        // 12 — Compliance + Audit (COM)
        [
            'key' => 'c5.compliance_audit',
            'topic' => 'c5_compliance_audit',
            'document_type' => 'policy',
            'norm_ref' => 'C5 COM-01',
            'title_translation_key' => 'policy.c5.c5_compliance_audit.v1.title',
            'body_translation_key' => 'policy.c5.c5_compliance_audit.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_BASIS,
            'linked_bsi_bausteine' => ['C5:COM-01', 'C5:COM-02', 'C5:COM-03', 'C5:COM-04', 'C5:COM-05'],
            'linked_annex_a_controls' => ['A.5.31', 'A.5.32', 'A.5.33', 'A.5.34', 'A.5.35', 'A.5.36'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
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
            'C5 PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
     * Reduce mixed list of C5 IDs (`C5:OIS-01`) and BSI Baustein-
     * Anforderungen (`OPS.2.2.A1`) to the unique Baustein/Domain roots.
     * For C5 IDs the root is the leading domain code (`C5:OIS`); for BSI
     * IT-Grundschutz it is the dotted-Baustein root (`OPS.2.2`).
     *
     * @param list<string> $anchors
     * @return list<string>|null
     */
    private function bausteineRoots(array $anchors): ?array
    {
        $seen = [];
        foreach ($anchors as $anchor) {
            // C5 anchors: `C5:OIS-01` -> root `C5:OIS`
            if (str_starts_with($anchor, 'C5:')) {
                $hyphen = strpos($anchor, '-');
                $root = $hyphen === false ? $anchor : substr($anchor, 0, $hyphen);
                if ($root === '' || isset($seen[$root])) {
                    continue;
                }
                $seen[$root] = true;
                continue;
            }
            // BSI Baustein-Anforderung anchors: strip trailing `.A<n>`
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
