<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\User;
use App\Enum\FourEyesApprovalRequestStatus;
use App\Exception\Tenant\TenantOrphanException;
use App\Exception\Workflow\InvalidStatusTransitionException;
use App\Repository\FourEyesApprovalRequestRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Generic four-eyes approval workflow for inheritance-implement, mapping-override,
 * bulk-tag-removal and import-large-commit. See DATA_REUSE_IMPROVEMENT_PLAN.md Anhang A.
 */
class FourEyesApprovalService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FourEyesApprovalRequestRepository $repository,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly CompliancePolicyService $policy,
    ) {
    }

    private function expiryDays(): int
    {
        return $this->policy->getInt(CompliancePolicyService::KEY_FOUR_EYES_EXPIRY_DAYS, 7);
    }

    private function rejectionMinLength(): int
    {
        return $this->policy->getInt(CompliancePolicyService::KEY_MIN_COMMENT_LENGTH, 20);
    }

    public function requestApproval(
        string $actionType,
        array $payload,
        User $requester,
        ?User $specificApprover = null,
    ): FourEyesApprovalRequest {
        if ($specificApprover !== null && $specificApprover->getId() === $requester->getId()) {
            throw new \App\Exception\BusinessRule\BusinessRuleException('Approver must differ from requester (segregation of duties).', 'self_approval');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw new TenantOrphanException(null, 'No tenant context when requesting four-eyes approval.');
        }

        $request = (new FourEyesApprovalRequest())
            ->setTenant($tenant)
            ->setActionType($actionType)
            ->setPayload($payload)
            ->setRequestedBy($requester)
            ->setRequestedApprover($specificApprover)
            ->setExpiresAt((new \DateTimeImmutable())->modify(sprintf('+%d days', max(1, $this->expiryDays()))))
            ->setStatus(FourEyesApprovalRequestStatus::Pending);

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'four_eyes.requested',
            'FourEyesApprovalRequest',
            $request->getId(),
            null,
            [
                'action_type' => $actionType,
                'requester_id' => $requester->getId(),
                'approver_id' => $specificApprover?->getId(),
            ],
            sprintf('4-eyes approval requested for %s', $actionType),
        );

        return $request;
    }

    public function approve(FourEyesApprovalRequest $request, User $approver): void
    {
        if (!$request->isPending()) {
            throw new InvalidStatusTransitionException(
                (string) $request->getStatus(),
                'approved',
                FourEyesApprovalRequest::class,
                'Approval request is not pending (status: ' . $request->getStatus() . ').',
            );
        }
        if ($request->getRequestedBy()?->getId() === $approver->getId()) {
            throw new \App\Exception\BusinessRule\BusinessRuleException('Approver must differ from requester.', 'self_approval');
        }
        if ($request->getRequestedApprover() !== null
            && $request->getRequestedApprover()->getId() !== $approver->getId()) {
            throw new \App\Exception\BusinessRule\BusinessRuleException('Only the designated approver can approve this request.', 'wrong_approver');
        }

        $request->setStatus(FourEyesApprovalRequestStatus::Approved)
            ->setApprovedBy($approver)
            ->setApprovedAt(new DateTimeImmutable());

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'four_eyes.approved',
            'FourEyesApprovalRequest',
            $request->getId(),
            null,
            [
                'action_type' => $request->getActionType(),
                'approver_id' => $approver->getId(),
            ],
            '4-eyes approval granted',
        );
    }

    public function reject(FourEyesApprovalRequest $request, User $approver, string $reason): void
    {
        if (!$request->isPending()) {
            throw new InvalidStatusTransitionException(
                (string) $request->getStatus(),
                'rejected',
                FourEyesApprovalRequest::class,
                'Approval request is not pending.',
            );
        }
        $minLen = $this->rejectionMinLength();
        if (mb_strlen(trim($reason)) < $minLen) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException(sprintf('Rejection reason requires at least %d characters.', $minLen), 'reason');
        }

        $request->setStatus(FourEyesApprovalRequestStatus::Rejected)
            ->setApprovedBy($approver)
            ->setApprovedAt(new DateTimeImmutable())
            ->setRejectionReason($reason);

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'four_eyes.rejected',
            'FourEyesApprovalRequest',
            $request->getId(),
            null,
            [
                'action_type' => $request->getActionType(),
                'approver_id' => $approver->getId(),
            ],
            '4-eyes approval rejected',
        );
    }

    public function cleanupExpired(): int
    {
        $count = $this->repository->markExpired();
        if ($count > 0) {
            $this->auditLogger->logCustom(
                'four_eyes.expired_batch',
                'FourEyesApprovalRequest',
                null,
                null,
                ['count' => $count],
                sprintf('Marked %d expired 4-eyes approval requests', $count),
            );
        }
        return $count;
    }
}
