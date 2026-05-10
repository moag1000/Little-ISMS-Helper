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
 * Policy-Wizard W5-K — seed 8 KRITIS-sektorspezifische
 * B3S-PolicyTemplates (Branchenspezifische Sicherheits-Standards).
 *
 * Adressiert KRITIS-Betreiber, die nach BSIG § 8a Abs. 3 alle 24 Monate
 * gegenueber dem BSI nachweisen muessen, dass sie angemessene Vorkehrungen
 * zur Vermeidung von Stoerungen der Verfuegbarkeit, Integritaet, Authenti-
 * zitaet und Vertraulichkeit ihrer informationstechnischen Systeme,
 * Komponenten oder Prozesse getroffen haben — sektorspezifisch operatio-
 * nalisiert via B3S (vom BSI gemaess BSIG § 8a Abs. 2 anerkannt) und
 * ergaenzt um die Aufsicht der Sektorbehoerden (BNetzA, BaFin, BfArM,
 * BBK, BLE).
 *
 * Die 8 Templates decken vollstaendig die KRITIS-Sektoren der
 * BSI-KritisV §§ 2-7 + § 8 (BSIG) ab:
 *   1. Energie       (BSI-KritisV § 2 + EnWG § 11 Abs. 1b)
 *   2. Wasser        (BSI-KritisV § 3 + DVGW-W 1060/1100)
 *   3. IT/TK         (BSI-KritisV § 6 + TKG § 165 + BNetzA-Sicherheitsplan)
 *   4. Finanz/Vers.  (KWG § 25a/b + DORA + MaRisk AT 11.2)
 *   5. Gesundheit    (BSI-KritisV § 7 + KHZG + DigiG + § 75c SGB V)
 *   6. Transport     (BSI-KritisV § 5)
 *   7. Ernaehrung    (BSI-KritisV § 4)
 *   8. Staat/Verw.   (BSIG § 8 + UP Bund + IT-Konsolidierung Bund)
 *
 * Jeder Template-Row traegt:
 *   • `standard='kritis'`
 *   • `bsi_tier` — i.d.R. 'standard' (KRITIS = hoher Schutzbedarf)
 *   • `linked_bsi_bausteine` — IT-Grundschutz Bausteine inkl. IND.* fuer OT
 *   • `linked_annex_a_controls` — ISO 27001:2022 Annex A Cross-Mapping
 *   • `review_interval_months=24` — BSIG § 8a Abs. 3 Pflichtintervall
 *
 * Idempotent: Re-Run ohne `--force` ist No-Op fuer existierende Rows.
 * Mit `--force` werden bestehende Rows aktualisiert.
 * `--dry-run` meldet Aenderungen ohne DB-Write.
 *
 * Run nach Deploy:
 *   php bin/console app:policy-wizard:seed-kritis
 *   php bin/console app:policy-wizard:seed-kritis --force
 *   php bin/console app:policy-wizard:seed-kritis --dry-run
 *
 * Spiegelt {@see SeedBsiPolicyTemplatesCommand} (W5-A) in der Form,
 * sodass Wizard-Tooling beide Standards uniform behandeln kann.
 * Trennung als eigener Standard `kritis` (statt BSI-Sub-Standard) waehlt
 * klare Sektor-Aktivierung via IndustryPresetBundle (z.B. preset
 * `kritis_energie` aktiviert sowohl `bsi` Pflicht-Set als auch
 * `kritis_energie_b3s`-Topic).
 */
#[AsCommand(
    name: 'app:policy-wizard:seed-kritis',
    description: 'Seeds the 8 KRITIS sector-specific B3S policy templates (W5-K).',
)]
final class SeedKritisPolicyTemplatesCommand extends Command
{
    public const string STANDARD = 'kritis';

    /**
     * Canonical 8-row catalogue. Body / title translation keys follow
     * the §8.7 versioning scheme (`policy.kritis.<topic>.v1.body`); real
     * translation content lives in `translations/policy_kritis.{de,en}.yaml`.
     *
     * Pflicht-Niveau: KRITIS-Betreiber MUESSEN nach § 8a BSIG den "Stand
     * der Technik" einhalten — das entspricht regelhaft der Standard-
     * Absicherung (oder Kern-Absicherung fuer besonders schutzbeduerftige
     * Zielobjekte). Daher tier='standard' fuer alle Sektor-Profile.
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
        // 1 — Sektor Energie (BSI-KritisV § 2 + EnWG § 11.1b + IT-Sicherheitskatalog)
        [
            'key' => 'kritis.kritis_energie_b3s',
            'topic' => 'kritis_energie_b3s',
            'document_type' => 'sector_profile',
            'norm_ref' => 'BSIG § 8a + BSI-KritisV § 2 + EnWG § 11 Abs. 1b',
            'title_translation_key' => 'policy.kritis.kritis_energie_b3s.v1.title',
            'body_translation_key' => 'policy.kritis.kritis_energie_b3s.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'ISMS.1.A4', 'ORP.4.A8', 'CON.1.A3',
                'IND.1.A1', 'IND.1.A2', 'IND.1.A3', 'IND.1.A4', 'IND.2.A1',
                'NET.1.1.A1', 'NET.3.2.A1',
                'OPS.1.1.5.A1', 'OPS.1.2.5.A1', 'DER.1.A1', 'DER.2.1.A1',
            ],
            'linked_annex_a_controls' => [
                'A.5.7', 'A.5.24', 'A.5.30', 'A.7.4', 'A.8.14', 'A.8.16',
            ],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 2 — Sektor Wasser (BSI-KritisV § 3 + DVGW-Regelwerk W 1060/1100)
        [
            'key' => 'kritis.kritis_wasser_b3s',
            'topic' => 'kritis_wasser_b3s',
            'document_type' => 'sector_profile',
            'norm_ref' => 'BSIG § 8a + BSI-KritisV § 3 + DVGW W 1060/1100',
            'title_translation_key' => 'policy.kritis.kritis_wasser_b3s.v1.title',
            'body_translation_key' => 'policy.kritis.kritis_wasser_b3s.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'ISMS.1.A4', 'ORP.4.A8',
                'IND.1.A1', 'IND.1.A2', 'IND.2.A1', 'IND.2.A2',
                'NET.1.1.A1', 'NET.3.2.A1',
                'INF.1.A1', 'INF.2.A1',
                'OPS.1.1.5.A1', 'DER.1.A1', 'DER.2.1.A1', 'DER.4.A1',
            ],
            'linked_annex_a_controls' => [
                'A.5.30', 'A.7.1', 'A.7.4', 'A.8.14', 'A.8.16',
            ],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 3 — Sektor IT/TK (TKG § 165 + BSI-KritisV § 6 + BNetzA-Katalog)
        [
            'key' => 'kritis.kritis_itk_b3s',
            'topic' => 'kritis_itk_b3s',
            'document_type' => 'sector_profile',
            'norm_ref' => 'BSIG § 8a + BSI-KritisV § 6 + TKG § 165',
            'title_translation_key' => 'policy.kritis.kritis_itk_b3s.v1.title',
            'body_translation_key' => 'policy.kritis.kritis_itk_b3s.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'ISMS.1.A4', 'ORP.4.A1', 'ORP.4.A8', 'CON.1.A1',
                'NET.1.1.A1', 'NET.1.2.A1', 'NET.3.1.A1', 'NET.3.2.A1', 'NET.3.3.A1',
                'NET.4.1.A1', 'APP.3.1.A1',
                'OPS.1.1.5.A1', 'DER.1.A1', 'DER.2.1.A1',
            ],
            'linked_annex_a_controls' => [
                'A.5.7', 'A.5.24', 'A.5.30', 'A.8.16', 'A.8.20', 'A.8.21', 'A.8.22',
            ],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 4 — Sektor Finanz + Versicherungen (KWG + DORA + MaRisk AT 11.2 + BAIT-Erbe)
        [
            'key' => 'kritis.kritis_finanz_b3s',
            'topic' => 'kritis_finanz_b3s',
            'document_type' => 'sector_profile',
            'norm_ref' => 'BSIG § 8a + KWG § 25a/b + DORA Art. 5-15 + MaRisk AT 11.2',
            'title_translation_key' => 'policy.kritis.kritis_finanz_b3s.v1.title',
            'body_translation_key' => 'policy.kritis.kritis_finanz_b3s.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'ISMS.1.A4', 'ORP.4.A8', 'CON.1.A3', 'CON.3.A1',
                'OPS.2.2.A1', 'OPS.2.3.A1',
                'NET.1.1.A1',
                'OPS.1.1.5.A1', 'DER.1.A1', 'DER.2.1.A1', 'DER.4.A1',
            ],
            'linked_annex_a_controls' => [
                'A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23',
                'A.5.24', 'A.5.30', 'A.8.13', 'A.8.16',
            ],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 5 — Sektor Gesundheit (BSI-KritisV § 7 + KHZG + DigiG + § 75c SGB V)
        [
            'key' => 'kritis.kritis_gesundheit_b3s',
            'topic' => 'kritis_gesundheit_b3s',
            'document_type' => 'sector_profile',
            'norm_ref' => 'BSIG § 8a + BSI-KritisV § 7 + KHZG + § 75c SGB V',
            'title_translation_key' => 'policy.kritis.kritis_gesundheit_b3s.v1.title',
            'body_translation_key' => 'policy.kritis.kritis_gesundheit_b3s.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'ISMS.1.A4', 'ORP.4.A8', 'CON.1.A3', 'CON.2.A1', 'CON.6.A1',
                'APP.4.6.A1',
                'INF.1.A1', 'INF.2.A1',
                'OPS.1.1.5.A1', 'DER.1.A1', 'DER.2.1.A1', 'DER.4.A1',
            ],
            'linked_annex_a_controls' => [
                'A.5.30', 'A.5.34', 'A.7.4', 'A.8.10', 'A.8.13', 'A.8.16', 'A.8.24',
            ],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => true,
        ],
        // 6 — Sektor Transport + Verkehr (BSI-KritisV § 5)
        [
            'key' => 'kritis.kritis_transport_logistik_b3s',
            'topic' => 'kritis_transport_logistik_b3s',
            'document_type' => 'sector_profile',
            'norm_ref' => 'BSIG § 8a + BSI-KritisV § 5',
            'title_translation_key' => 'policy.kritis.kritis_transport_logistik_b3s.v1.title',
            'body_translation_key' => 'policy.kritis.kritis_transport_logistik_b3s.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'ISMS.1.A4', 'ORP.4.A8', 'CON.1.A1',
                'IND.1.A1', 'IND.2.A1',
                'NET.1.1.A1', 'NET.3.2.A1',
                'INF.1.A1', 'INF.2.A1',
                'OPS.1.1.5.A1', 'DER.1.A1', 'DER.2.1.A1', 'DER.4.A1',
            ],
            'linked_annex_a_controls' => [
                'A.5.30', 'A.7.1', 'A.7.4', 'A.8.14', 'A.8.16',
            ],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 7 — Sektor Ernaehrung (BSI-KritisV § 4)
        [
            'key' => 'kritis.kritis_ernaehrung_b3s',
            'topic' => 'kritis_ernaehrung_b3s',
            'document_type' => 'sector_profile',
            'norm_ref' => 'BSIG § 8a + BSI-KritisV § 4',
            'title_translation_key' => 'policy.kritis.kritis_ernaehrung_b3s.v1.title',
            'body_translation_key' => 'policy.kritis.kritis_ernaehrung_b3s.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'ISMS.1.A4', 'ORP.4.A8',
                'IND.1.A1', 'IND.2.A1',
                'NET.1.1.A1',
                'INF.1.A1', 'INF.2.A1',
                'OPS.1.1.5.A1', 'DER.1.A1', 'DER.2.1.A1', 'DER.4.A1',
            ],
            'linked_annex_a_controls' => [
                'A.5.30', 'A.7.1', 'A.7.4', 'A.8.14', 'A.8.16',
            ],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => false,
        ],
        // 8 — Sektor Staat + Verwaltung (BSIG § 8 + UP Bund + IT-Konsolidierung)
        [
            'key' => 'kritis.kritis_staat_verwaltung_b3s',
            'topic' => 'kritis_staat_verwaltung_b3s',
            'document_type' => 'sector_profile',
            'norm_ref' => 'BSIG § 8 + UP Bund + OZG + IT-Konsolidierung Bund',
            'title_translation_key' => 'policy.kritis.kritis_staat_verwaltung_b3s.v1.title',
            'body_translation_key' => 'policy.kritis.kritis_staat_verwaltung_b3s.v1.body',
            'bsi_tier' => PolicyTemplate::BSI_TIER_STANDARD,
            'linked_bsi_bausteine' => [
                'ISMS.1.A4', 'ORP.1.A1', 'ORP.4.A8', 'CON.1.A1', 'CON.2.A1',
                'OPS.2.2.A1',
                'NET.1.1.A1',
                'INF.1.A1', 'INF.2.A1',
                'OPS.1.1.5.A1', 'DER.1.A1', 'DER.2.1.A1', 'DER.4.A1',
            ],
            'linked_annex_a_controls' => [
                'A.5.24', 'A.5.30', 'A.5.34', 'A.7.1', 'A.7.4', 'A.8.16',
            ],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_CISO', 'ROLE_DPO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
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
            'KRITIS PolicyTemplate seed: created=%d updated=%d skipped=%d (dry_run=%s force=%s)',
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
     * Reduce a list of Baustein-Anforderung anchors (`ORP.4.A1`, `ORP.4.A2`)
     * to the unique Baustein roots (`ORP.4`). Keeps insertion order and
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
            // Strip a trailing Anforderung token if present (always Aon
            // shape — letter A followed by digits). Otherwise keep as-is.
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
