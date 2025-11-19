<?php

namespace App\Service;

use App\Entity\Risk;
use App\Entity\User;
use App\Entity\Tenant;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Risk Acceptance Workflow Service
 *
 * ISO 27005:2022 Section 8.4.4 (Risk acceptance) compliant workflow
 *
 * Implements formal risk acceptance process with approval levels:
 * - Automatic acceptance: Risk score <= 3
 * - Manager approval required: Risk score 4-7
 * - Executive approval required: Risk score 8-25
 *
 * Features:
 * - Risk appetite validation before acceptance
 * - Approval level determination based on risk score
 * - Workflow integration for approval tracking
 * - Email notifications to approvers
 * - Automatic rejection if risk exceeds appetite threshold
 *
 * Priority 2.1 - Risk Acceptance Workflow (High Impact, High Effort)
 */
class RiskAcceptanceWorkflowService
{
    // Approval level thresholds (from Appendix B of audit report)
    private const APPROVAL_AUTOMATIC = 3;     // Score <= 3: Auto-accept
    private const APPROVAL_MANAGER = 7;       // Score 4-7: Manager approval
    private const APPROVAL_EXECUTIVE = 25;    // Score 8-25: Executive approval

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RiskAppetitePrioritizationService $appetiteService,
        private WorkflowService $workflowService,
        private EmailNotificationService $emailService,
        private UserRepository $userRepository,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger
    ) {}

    /**
     * Request formal acceptance for a risk
     *
     * @param Risk $risk The risk to be accepted
     * @param User $requester User requesting the acceptance
     * @param string $justification Justification for accepting the risk
     * @throws \DomainException If risk does not qualify for acceptance
     * @return array Status information about the acceptance request
     */
    public function requestAcceptance(Risk $risk, User $requester, string $justification): array
    {
        $this->logger->info('Risk acceptance requested', [
            'risk_id' => $risk->getId(),
            'requester' => $requester->getEmail(),
        ]);

        // 1. Validate risk qualifies for acceptance
        $this->validateRiskForAcceptance($risk);

        // 2. Check against risk appetite
        $appetiteCheck = $this->validateRiskAppetite($risk);

        if (!$appetiteCheck['acceptable']) {
            throw new \DomainException($appetiteCheck['reason']);
        }

        // 3. Determine required approval level
        $approvalLevel = $this->determineApprovalLevel($risk);

        // 4. Set acceptance justification
        $risk->setAcceptanceJustification($justification);

        // 5. Handle based on approval level
        if ($approvalLevel === 'automatic') {
            return $this->processAutomaticAcceptance($risk, $requester);
        } else {
            return $this->createApprovalWorkflow($risk, $requester, $approvalLevel);
        }
    }

    /**
     * Validate if risk qualifies for acceptance strategy
     *
     * @param Risk $risk
     * @throws \DomainException If risk doesn't qualify
     */
    private function validateRiskForAcceptance(Risk $risk): void
    {
        // Check if risk has "accept" treatment strategy
        if ($risk->getTreatmentStrategy() !== 'accept') {
            throw new \DomainException(
                'Risk must have "accept" treatment strategy. Current strategy: ' . $risk->getTreatmentStrategy()
            );
        }

        // Risk must have assessment completed
        if (!$risk->getProbability() || !$risk->getImpact()) {
            throw new \DomainException('Risk assessment must be completed before acceptance');
        }

        // Residual risk should be assessed
        if (!$risk->getResidualProbability() || !$risk->getResidualImpact()) {
            throw new \DomainException('Residual risk must be assessed before acceptance');
        }

        // Check if already formally accepted
        if ($risk->isFormallyAccepted()) {
            throw new \DomainException('Risk is already formally accepted');
        }
    }

    /**
     * Validate risk against organizational risk appetite
     *
     * @param Risk $risk
     * @return array ['acceptable' => bool, 'reason' => string]
     */
    private function validateRiskAppetite(Risk $risk): array
    {
        $appetite = $this->appetiteService->getApplicableAppetite($risk);

        if (!$appetite) {
            // No appetite defined - log warning but allow
            $this->logger->warning('No risk appetite defined for tenant', [
                'tenant_id' => $risk->getTenant()->getId(),
                'risk_id' => $risk->getId()
            ]);

            return [
                'acceptable' => true,
                'reason' => 'No risk appetite defined',
                'appetite' => null
            ];
        }

        // Check if approved appetite
        if (!$appetite->isApproved()) {
            return [
                'acceptable' => false,
                'reason' => 'Risk appetite must be approved before use. Please request appetite approval first.',
                'appetite' => $appetite
            ];
        }

        // Check if residual risk exceeds appetite
        $exceedsAppetite = $this->appetiteService->exceedsAppetite($risk);

        if ($exceedsAppetite) {
            return [
                'acceptable' => false,
                'reason' => sprintf(
                    'Risk (residual level: %d) exceeds organizational risk appetite (max: %d). Additional mitigation required before acceptance.',
                    $risk->getResidualRiskLevel(),
                    $appetite->getMaxAcceptableRisk()
                ),
                'appetite' => $appetite
            ];
        }

        return [
            'acceptable' => true,
            'reason' => 'Risk is within organizational appetite',
            'appetite' => $appetite
        ];
    }

    /**
     * Determine required approval level based on residual risk score
     *
     * @param Risk $risk
     * @return string 'automatic', 'manager', or 'executive'
     */
    private function determineApprovalLevel(Risk $risk): string
    {
        $residualScore = $risk->getResidualRiskLevel();

        if ($residualScore <= self::APPROVAL_AUTOMATIC) {
            return 'automatic';
        } elseif ($residualScore <= self::APPROVAL_MANAGER) {
            return 'manager';
        } else {
            return 'executive';
        }
    }

    /**
     * Process automatic acceptance for low risks
     *
     * @param Risk $risk
     * @param User $requester
     * @return array Status information
     */
    private function processAutomaticAcceptance(Risk $risk, User $requester): array
    {
        $now = new \DateTime();

        $risk->setFormallyAccepted(true);
        $risk->setAcceptanceApprovedBy($requester->getFullName() . ' (automatic)');
        $risk->setAcceptanceApprovedAt($now);
        $risk->setStatus('accepted');

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Log acceptance
        $this->auditLogger->logRiskAcceptance(
            $risk,
            $requester,
            'Automatically accepted (score <= 3)'
        );

        $this->logger->info('Risk automatically accepted', [
            'risk_id' => $risk->getId(),
            'score' => $risk->getResidualRiskLevel(),
        ]);

        return [
            'status' => 'accepted',
            'approval_level' => 'automatic',
            'message' => 'Risk has been automatically accepted (low risk score)',
            'approved_at' => $now,
        ];
    }

    /**
     * Create approval workflow for manager or executive approval
     *
     * @param Risk $risk
     * @param User $requester
     * @param string $approvalLevel
     * @return array Status information
     */
    private function createApprovalWorkflow(Risk $risk, User $requester, string $approvalLevel): array
    {
        $tenant = $risk->getTenant();
        $approver = $this->getRequiredApprover($tenant, $approvalLevel);

        if (!$approver) {
            throw new \DomainException(
                sprintf('No %s approver configured for tenant', $approvalLevel)
            );
        }

        // Create workflow instance
        $workflowInstance = $this->workflowService->startWorkflow(
            entityType: 'risk_acceptance',
            entityId: $risk->getId(),
            workflowName: 'risk_acceptance_' . $approvalLevel
        );

        if (!$workflowInstance) {
            // Fallback: Manual tracking if no workflow defined
            $this->logger->warning('No workflow configured for risk acceptance, using manual tracking');
            return $this->createManualApprovalRequest($risk, $requester, $approver, $approvalLevel);
        }

        // Send notification to approver
        $this->sendApprovalNotification($risk, $approver, $approvalLevel);

        // Log workflow creation
        $this->auditLogger->logRiskAcceptanceRequested(
            $risk,
            $requester,
            $approver,
            $approvalLevel
        );

        return [
            'status' => 'pending_approval',
            'approval_level' => $approvalLevel,
            'approver' => $approver->getFullName(),
            'message' => sprintf(
                'Acceptance request sent to %s for %s approval',
                $approver->getFullName(),
                $approvalLevel
            ),
            'workflow_id' => $workflowInstance->getId(),
        ];
    }

    /**
     * Create manual approval request (fallback when workflow not configured)
     *
     * @param Risk $risk
     * @param User $requester
     * @param User $approver
     * @param string $approvalLevel
     * @return array
     */
    private function createManualApprovalRequest(Risk $risk, User $requester, User $approver, string $approvalLevel): array
    {
        // Set status to indicate pending approval
        $risk->setStatus('assessed'); // Keep in assessed until approved

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Send notification
        $this->sendApprovalNotification($risk, $approver, $approvalLevel);

        return [
            'status' => 'pending_approval',
            'approval_level' => $approvalLevel,
            'approver' => $approver->getFullName(),
            'message' => sprintf(
                'Approval request sent to %s. Manual approval required.',
                $approver->getFullName()
            ),
            'workflow_id' => null,
        ];
    }

    /**
     * Get required approver based on approval level
     *
     * @param Tenant $tenant
     * @param string $approvalLevel
     * @return User|null
     */
    private function getRequiredApprover(Tenant $tenant, string $approvalLevel): ?User
    {
        $role = match($approvalLevel) {
            'manager' => 'ROLE_MANAGER',
            'executive' => 'ROLE_ADMIN',
            default => 'ROLE_MANAGER'
        };

        // Find user with required role in tenant
        $users = $this->userRepository->findBy(['tenant' => $tenant]);

        foreach ($users as $user) {
            if (in_array($role, $user->getRoles(), true)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Send email notification to approver
     *
     * @param Risk $risk
     * @param User $approver
     * @param string $approvalLevel
     */
    private function sendApprovalNotification(Risk $risk, User $approver, string $approvalLevel): void
    {
        try {
            $this->emailService->sendRiskAcceptanceRequest(
                risk: $risk,
                approver: $approver,
                approvalLevel: $approvalLevel
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to send approval notification', [
                'error' => $e->getMessage(),
                'risk_id' => $risk->getId(),
                'approver' => $approver->getEmail(),
            ]);
        }
    }

    /**
     * Approve risk acceptance
     *
     * @param Risk $risk
     * @param User $approver
     * @param string $comments Optional approval comments
     * @return array Status information
     */
    public function approveAcceptance(Risk $risk, User $approver, string $comments = ''): array
    {
        $now = new \DateTime();

        $risk->setFormallyAccepted(true);
        $risk->setAcceptanceApprovedBy($approver->getFullName());
        $risk->setAcceptanceApprovedAt($now);
        $risk->setStatus('accepted');

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Log approval
        $this->auditLogger->logRiskAcceptanceApproved(
            $risk,
            $approver,
            $comments
        );

        $this->logger->info('Risk acceptance approved', [
            'risk_id' => $risk->getId(),
            'approver' => $approver->getEmail(),
        ]);

        return [
            'status' => 'accepted',
            'approved_by' => $approver->getFullName(),
            'approved_at' => $now,
            'message' => 'Risk acceptance has been approved',
        ];
    }

    /**
     * Reject risk acceptance
     *
     * @param Risk $risk
     * @param User $rejector
     * @param string $reason Reason for rejection
     * @return array Status information
     */
    public function rejectAcceptance(Risk $risk, User $rejector, string $reason): array
    {
        $risk->setStatus('assessed'); // Return to assessed status
        $risk->setFormallyAccepted(false);

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Log rejection
        $this->auditLogger->logRiskAcceptanceRejected(
            $risk,
            $rejector,
            $reason
        );

        $this->logger->info('Risk acceptance rejected', [
            'risk_id' => $risk->getId(),
            'rejector' => $rejector->getEmail(),
            'reason' => $reason,
        ]);

        return [
            'status' => 'rejected',
            'rejected_by' => $rejector->getFullName(),
            'reason' => $reason,
            'message' => 'Risk acceptance has been rejected. Additional mitigation required.',
        ];
    }

    /**
     * Get approval thresholds configuration
     *
     * @return array Approval level configuration
     */
    public function getApprovalThresholds(): array
    {
        return [
            'automatic' => [
                'max_score' => self::APPROVAL_AUTOMATIC,
                'label' => 'Automatic Acceptance',
                'description' => 'Low risks (score ≤ 3) are automatically accepted'
            ],
            'manager' => [
                'min_score' => self::APPROVAL_AUTOMATIC + 1,
                'max_score' => self::APPROVAL_MANAGER,
                'label' => 'Manager Approval Required',
                'description' => 'Medium risks (score 4-7) require manager approval'
            ],
            'executive' => [
                'min_score' => self::APPROVAL_MANAGER + 1,
                'max_score' => self::APPROVAL_EXECUTIVE,
                'label' => 'Executive Approval Required',
                'description' => 'High/Critical risks (score 8-25) require executive approval'
            ]
        ];
    }
}
