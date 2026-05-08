<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Bcm;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W5-C / ISO 22301 Cl. 5.2 — confirms the tenant has an approved
 * Top-Level Business Continuity Policy (Notfallleitlinie).
 *
 * A document qualifies when ALL of the following hold:
 * - `tenant` matches
 * - `status` is `approved` (top-management sign-off per Cl. 5.1)
 * - `isArchived` is false
 * - `generatedFromTemplate` has `standard='bcm'` and
 *   `topic='bcms_top_level'`
 *
 * Reference: `docs/plans/policy-wizard/04-bcm-input.md` §1.
 */
final class BcmTopLevelPolicyPresentCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'bcm_top_level_policy_present';
    private const STANDARD = 'bcm';
    private const TOPIC = 'bcms_top_level';

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
