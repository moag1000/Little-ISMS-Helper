<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Gspp;

use App\Entity\Document;
use App\Entity\EntityTag;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\Bsi\GsppReuseMap;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use App\Service\PolicyWizard\SectionExtension\GsppSectionCatalogue;

/**
 * Grundschutz++ (BSI-Standard 300-1) — every ISO 27001 topic policy that carries a
 * Grundschutz++ section must exist as a published or approved Document that the
 * Policy-Wizard generated with the `gspp` standard adopted (tag
 * `gspp-extension:applied`, set by DocumentGenerator when the section was appended).
 *
 * Score = covered topics / topics with a GS++ section. Missing topics are listed so the
 * wizard can re-run exactly those.
 */
final class GsppPolicyCoverageCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'gspp_policy_coverage';
    public const TAG = 'gspp-extension:applied';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly GsppReuseMap $reuseMap,
    ) {
    }

    public function getCheckId(): string
    {
        return self::CHECK_ID;
    }

    public function getStandard(): string
    {
        return GsppSectionCatalogue::STANDARD;
    }

    public function run(?Tenant $tenant): PolicyWizardCheckResult
    {
        $expected = array_keys($this->reuseMap->policyTopics());
        if ($tenant === null) {
            return new PolicyWizardCheckResult(self::CHECK_ID, 0.0, false, ['reason' => 'no_tenant']);
        }

        /** @var list<string> $covered */
        $covered = $this->documentRepository->createQueryBuilder('d')
            ->select('DISTINCT t.topic')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->innerJoin(EntityTag::class, 'et', 'WITH', 'et.entityClass = :class AND et.entityId = d.id')
            ->innerJoin('et.tag', 'tag')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->andWhere('tag.name = :tag')
            ->setParameter('class', Document::class)
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->setParameter('standard', 'iso27001')
            ->setParameter('tag', self::TAG)
            ->getQuery()
            ->getSingleColumnResult();

        $missing = array_values(array_diff($expected, $covered));
        $total = count($expected);
        $score = $total > 0 ? round((($total - count($missing)) / $total) * 100, 1) : 0.0;
        $details = ['expected_topics' => $total, 'covered_topics' => $total - count($missing)];

        if ($missing === []) {
            return new PolicyWizardCheckResult(self::CHECK_ID, $score, true, $details);
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: false,
            details: $details + ['missing_count' => count($missing)],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($missing, 0, 5),
            ],
        );
    }
}
