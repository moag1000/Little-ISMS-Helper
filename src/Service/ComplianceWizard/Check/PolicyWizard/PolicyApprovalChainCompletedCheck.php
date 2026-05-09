<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\WorkflowInstance;
use App\Repository\DocumentRepository;
use App\Repository\WorkflowInstanceRepository;

/**
 * For every published Policy-Wizard document the check verifies that the
 * approval-trail covers BOTH `ROLE_CISO` and `ROLE_TOP_MGMT` (ISO 27001
 * Cl. 5.1 leadership commitment + Cl. 5.2 top-level signature).
 *
 * Resolution path: each policy carries an approval `WorkflowInstance` (entity
 * type `App\Entity\Document`); the workflow's `approvalHistory` JSON stores
 * one entry per signed step. A step is considered covered when the matching
 * `WorkflowStep::approverRole` appears with `action = approved` in the
 * history.
 *
 * Policies without ANY workflow instance are flagged as "not signed" and
 * count against the score — that surfaces the legacy import case that
 * P1-Compliance-Manager review §"What worries me" #6 asked for.
 */
final class PolicyApprovalChainCompletedCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'policy_approval_chain_completed';
    private const STANDARD = 'iso27001';

    /**
     * @var list<string>
     */
    private const REQUIRED_ROLES = ['ROLE_CISO', 'ROLE_TOP_MGMT'];

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
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

        $total = count($policies);
        if ($total === 0) {
            // No policies = nothing to assert; treat as "not started" but
            // do not fail-out the wizard — the topic-checks will already
            // surface that the policies are missing.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['policies_total' => 0, 'policies_signed' => 0],
            );
        }

        $signed = 0;
        $unsigned = [];
        foreach ($policies as $policy) {
            if ($this->hasFullApprovalChain($policy)) {
                $signed++;
            } else {
                $unsigned[] = [
                    'document_id' => $policy->getId(),
                    'title' => $policy->getOriginalFilename() ?? $policy->getFilename(),
                ];
            }
        }

        $score = round(($signed / $total) * 100, 1);
        $passed = $signed === $total;

        $gap = null;
        if (!$passed) {
            $gap = [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_workflow_inbox',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($unsigned, 0, 5),
            ];
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: $passed,
            details: [
                'policies_total' => $total,
                'policies_signed' => $signed,
                'policies_unsigned' => count($unsigned),
                'required_roles' => self::REQUIRED_ROLES,
            ],
            gap: $gap,
        );
    }

    /**
     * A policy passes when the approval-trail contains an `approved` entry
     * whose `approver_role` (or `step_role`, depending on which key the
     * workflow service emits) matches each of the required roles.
     */
    private function hasFullApprovalChain(Document $policy): bool
    {
        $instances = $this->workflowInstanceRepository->findByEntity(
            Document::class,
            (int) $policy->getId(),
        );

        if ($instances === []) {
            return false;
        }

        $rolesSigned = [];
        foreach ($instances as $instance) {
            if (!$instance instanceof WorkflowInstance) {
                continue;
            }
            $history = $instance->getApprovalHistory() ?? [];
            foreach ($history as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $action = $entry['action'] ?? null;
                if ($action !== 'approved') {
                    continue;
                }
                $role = $entry['approver_role'] ?? $entry['step_role'] ?? null;
                if (is_string($role) && $role !== '') {
                    $rolesSigned[$role] = true;
                }
            }
        }

        foreach (self::REQUIRED_ROLES as $required) {
            if (!isset($rolesSigned[$required])) {
                return false;
            }
        }
        return true;
    }
}
