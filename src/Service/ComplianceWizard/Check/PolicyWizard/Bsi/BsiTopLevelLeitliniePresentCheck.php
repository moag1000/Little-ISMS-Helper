<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Bsi;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W5-C / BSI 200-1 Kap. 6.2 + ISMS.1.A4 — confirms the tenant has an
 * approved IT-Sicherheitsleitlinie (Top-Level Information Security
 * Policy) generated from a BSI-flagged template.
 *
 * A document qualifies when ALL of the following hold:
 * - `tenant` matches
 * - `status` is `approved` (Leadership-Sign-Off; `published` alone is
 *   not enough — BSI 200-2 Kap. 4 demands explicit Leitungs-Genehmigung)
 * - `isArchived` is false
 * - `generatedFromTemplate` has `standard='bsi'` and `topic='it_security_policy'`
 *
 * Reference: `docs/plans/policy-wizard/02-bsi-input.md` §1 + §3.1.
 */
final class BsiTopLevelLeitliniePresentCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'bsi_top_level_leitlinie_present';
    private const STANDARD = 'bsi';
    private const TOPIC = 'it_security_policy';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
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

        $count = (int) $this->documentRepository->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status = :approved')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->andWhere('t.topic = :topic')
            ->setParameter('tenant', $tenant)
            ->setParameter('approved', 'approved')
            ->setParameter('standard', self::STANDARD)
            ->setParameter('topic', self::TOPIC)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count > 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['approved_documents' => $count],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: ['approved_documents' => 0],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }
}
