<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use App\Service\PolicyWizard\GdprSectionCatalogue;

/**
 * W6-D / GDPR Art. 5/24/25 — confirms every catalogue entry from
 * {@see GdprSectionCatalogue::SECTIONS} has a generated
 * {@see DocumentSection} on the corresponding ISO topic Document.
 *
 * Per `docs/plans/policy-wizard/06-dpo-input.md` §0 Decision Matrix v2,
 * the DPO addon contributes 10 sections that MERGE into existing ISO
 * 27001 host policies. This check enforces that each (iso_topic,
 * section_key) pair is materialised against a tenant Document — gap-
 * listing missing pairs so the wizard can re-run the affected ISO
 * topics in a single click.
 *
 * Sections that are not yet approved still count for coverage — the
 * presence of the section row evidences the GDPR addon was applied to
 * the host. Approval workflow is captured by separate per-section
 * approval checks (W6-A scope).
 */
final class GdprSectionCoverageCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'gdpr_section_coverage';
    private const STANDARD = 'gdpr';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly GdprSectionCatalogue $sectionCatalogue,
    ) {
    }

    public function getCheckId(): string
    {
        return self::CHECK_ID;
    }

    public function getStandard(): string
    {
        return self::STANDARD;
    }

    public function run(?Tenant $tenant): PolicyWizardCheckResult
    {
        if ($tenant === null) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: ['reason' => 'no_tenant'],
            );
        }

        $catalogueRows = $this->sectionCatalogue->all();
        $expected = count($catalogueRows);
        if ($expected === 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'expected_sections' => 0,
                    'reason' => 'no_catalogue_rows',
                ],
            );
        }

        $covered = 0;
        $missing = [];
        foreach ($catalogueRows as $row) {
            $count = (int) $this->documentRepository->createQueryBuilder('d')
                ->select('COUNT(s.id)')
                ->innerJoin('d.generatedFromTemplate', 't')
                ->innerJoin(DocumentSection::class, 's', 'ON', 's.document = d')
                ->where('d.tenant = :tenant')
                ->andWhere('d.isArchived = false')
                ->andWhere('t.topic = :isoTopic')
                ->andWhere('s.sectionKey = :sectionKey')
                ->setParameter('tenant', $tenant)
                ->setParameter('isoTopic', $row['iso_topic'])
                ->setParameter('sectionKey', $row['section_key'])
                ->getQuery()
                ->getSingleScalarResult();

            if ($count > 0) {
                $covered++;
            } else {
                $missing[] = [
                    'iso_topic' => $row['iso_topic'],
                    'section_key' => $row['section_key'],
                    'gdpr_articles' => $row['gdpr_articles'],
                ];
            }
        }

        if ($missing === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'expected_sections' => $expected,
                    'covered_sections' => $covered,
                ],
            );
        }

        $score = round(($covered / $expected) * 100, 1);
        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: false,
            details: [
                'expected_sections' => $expected,
                'covered_sections' => $covered,
                'missing_count' => count($missing),
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'high',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($missing, 0, 5),
            ],
        );
    }
}
