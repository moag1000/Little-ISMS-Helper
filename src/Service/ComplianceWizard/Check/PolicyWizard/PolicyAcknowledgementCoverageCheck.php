<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\UserRepository;

/**
 * For every published Policy-Wizard document the check verifies that at least
 * `THRESHOLD_PERCENT` of the required-audience users acknowledged the policy
 * (per the `PolicyAcknowledgement` entity).
 *
 * Required audience = the count of `User.isActive=true` rows in the tenant.
 * A more granular role-scoped audience is out of scope for the MVP — the
 * audit-defensible position is "everyone with an active user account" until
 * ships per-policy audience-scoping.
 *
 * Reference: ISO 27001 Cl. 7.3 awareness + ISO 27002 §6.3 awareness, education
 * and training (95 % is the empirical industry benchmark; the threshold is a
 * named constant so audits / tenants can re-tune without touching the algorithm).
 */
final class PolicyAcknowledgementCoverageCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'policy_acknowledgement_coverage';
    public const THRESHOLD_PERCENT = 95.0;
    private const STANDARD = 'iso27001';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly PolicyAcknowledgementRepository $acknowledgementRepository,
        private readonly UserRepository $userRepository,
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

        /** @var list<Document> $policies */
        $policies = $this->documentRepository->createQueryBuilder('d')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->setParameter('standard', self::STANDARD)
            ->getQuery()
            ->getResult();

        $totalPolicies = count($policies);
        if ($totalPolicies === 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: ['reason' => 'no_data', 'policies_total' => 0, 'audience_size' => 0],
                gap: [
                    'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                    'priority' => 'high',
                    'route' => 'app_policy_wizard_index',
                    'translation_domain' => 'policy_wizard',
                ],
            );
        }

        // Own organisation only — the tenant filter does not cover User, so
        // findActiveUsers() counted every active account of the instance.
        $audience = count($this->userRepository->findActiveByTenant($tenant));
        if ($audience === 0) {
            // No active users: acknowledgement coverage cannot be evidenced.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: ['reason' => 'no_data', 'policies_total' => $totalPolicies, 'audience_size' => 0],
                gap: [
                    'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                    'priority' => 'high',
                    'route' => 'app_policy_wizard_index',
                    'translation_domain' => 'policy_wizard',
                ],
            );
        }

        $policiesAtThreshold = 0;
        $undercovered = [];
        foreach ($policies as $policy) {
            $acks = count($this->acknowledgementRepository->findByDocument($policy));
            $coverage = ($acks / $audience) * 100.0;
            if ($coverage >= self::THRESHOLD_PERCENT) {
                $policiesAtThreshold++;
            } else {
                $undercovered[] = [
                    'document_id' => $policy->getId(),
                    'title' => $policy->getOriginalFilename() ?? $policy->getFilename(),
                    'coverage_percent' => round($coverage, 1),
                ];
            }
        }

        $score = round(($policiesAtThreshold / $totalPolicies) * 100, 1);
        $passed = $policiesAtThreshold === $totalPolicies;

        $gap = null;
        if (!$passed) {
            $gap = [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'high',
                'route' => 'app_document_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($undercovered, 0, 5),
            ];
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: $passed,
            details: [
                'policies_total' => $totalPolicies,
                'policies_at_threshold' => $policiesAtThreshold,
                'audience_size' => $audience,
                'threshold_percent' => self::THRESHOLD_PERCENT,
            ],
            gap: $gap,
        );
    }
}
