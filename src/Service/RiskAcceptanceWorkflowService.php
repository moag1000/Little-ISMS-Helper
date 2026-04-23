<?php

namespace App\Service;

use DateMalformedStringException;
use App\Entity\RiskAppetite;
use DomainException;
use DateTime;
use App\Entity\WorkflowInstance;
use Exception;
use App\Entity\Risk;
use App\Entity\User;
use App\Entity\Tenant;
use App\Repository\UserRepository;
use App\Service\RiskApprovalConfigResolver;
use App\Service\RiskApprovalConfigView;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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
    // Hardcoded Fallback-Werte falls Tenant keine Config hat (Phase 8L.F1:
    // Werte wanderten in RiskApprovalConfig-Entity per Tenant). Die Defaults
    // hier sind identisch zu den vorherigen PHP-Constants und werden vom
    // Resolver gespiegelt (RiskApprovalConfigView::defaults()).

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiskAppetitePrioritizationService $riskAppetitePrioritizationService,
        private readonly WorkflowService $workflowService,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly RiskApprovalConfigResolver $approvalConfigResolver,
    ) {}

    /**
     * Request formal acceptance for a risk
     *
     * @param Risk $risk The risk to be accepted
     * @param User $user User requesting the acceptance
     * @param string $justification Justification for accepting the risk
     * @throws DomainException If risk does not qualify for acceptance
     * @return array Status information about the acceptance request
     */
    public function requestAcceptance(Risk $risk, User $user, string $justification): array
    {
        $this->logger->info('Risk acceptance requested', [
            'risk_id' => $risk->getId(),
            'requester' => $user->getEmail(),
        ]);

        // 1. Validate risk qualifies for acceptance
        $this->validateRiskForAcceptance($risk);

        // 2. Check against risk appetite
        $appetiteCheck = $this->validateRiskAppetite($risk);

        if (!$appetiteCheck['acceptable']) {
            throw new DomainException($appetiteCheck['reason']);
        }

        // 3. Determine required approval level
        $approvalLevel = $this->determineApprovalLevel($risk);

        // 4. Set acceptance justification
        $risk->setAcceptanceJustification($justification);

        // 5. Handle based on approval level
        if ($approvalLevel === 'automatic') {
            return $this->processAutomaticAcceptance($risk, $user);
        }
        return $this->createApprovalWorkflow($risk, $user, $approvalLevel);
    }

    /**
     * Validate if risk qualifies for acceptance strategy
     *
     * @throws DomainException If risk doesn't qualify
     */
    private function validateRiskForAcceptance(Risk $risk): void
    {
        // Check if risk has "accept" treatment strategy
        if ($risk->getTreatmentStrategy() !== 'accept') {
            throw new DomainException(
                'Risk must have "accept" treatment strategy. Current strategy: ' . $risk->getTreatmentStrategy()
            );
        }

        // Risk must have assessment completed
        if (!$risk->getProbability() || !$risk->getImpact()) {
            throw new DomainException('Risk assessment must be completed before acceptance');
        }

        // Residual risk should be assessed
        if (!$risk->getResidualProbability() || !$risk->getResidualImpact()) {
            throw new DomainException('Residual risk must be assessed before acceptance');
        }

        // Check if already formally accepted
        if ($risk->isFormallyAccepted()) {
            throw new DomainException('Risk is already formally accepted');
        }
    }

    /**
     * Validate risk against organizational risk appetite
     *
     * @return array ['acceptable' => bool, 'reason' => string]
     */
    private function validateRiskAppetite(Risk $risk): array
    {
        $appetite = $this->riskAppetitePrioritizationService->getApplicableAppetite($risk);

        if (!$appetite instanceof RiskAppetite) {
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
        $exceedsAppetite = $this->riskAppetitePrioritizationService->exceedsAppetite($risk);

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
     * @return string 'automatic', 'manager', or 'executive'
     */
    public function determineApprovalLevel(Risk $risk): string
    {
        $residualScore = $risk->getResidualRiskLevel();
        $tenant = $risk->getTenant();
        $config = $tenant instanceof Tenant
            ? $this->approvalConfigResolver->resolveFor($tenant)
            : RiskApprovalConfigView::defaults();

        if ($residualScore <= $config->thresholdAutomatic) {
            return 'automatic';
        }
        if ($residualScore <= $config->thresholdManager) {
            return 'manager';
        }
        return 'executive';
    }

    /**
     * Process automatic acceptance for low risks
     *
     * @return array Status information
     */
    private function processAutomaticAcceptance(Risk $risk, User $user): array
    {
        $now = new DateTime();

        $risk->setFormallyAccepted(true);
        $risk->setAcceptanceApprovedBy($user->getFullName() . ' (automatic)');
        $risk->setAcceptanceApprovedAt($now);
        $risk->setStatus('accepted');

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Log acceptance
        $this->auditLogger->logRiskAcceptance(
            $risk,
            $user,
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
     * @return array Status information
     * @throws DateMalformedStringException
     */
    private function createApprovalWorkflow(Risk $risk, User $user, string $approvalLevel): array
    {
        $tenant = $risk->getTenant();
        $approver = $this->getRequiredApprover($tenant, $approvalLevel);

        if (!$approver instanceof User) {
            throw new DomainException(
                sprintf('No %s approver configured for tenant', $approvalLevel)
            );
        }

        // Create workflow instance
        $workflowInstance = $this->workflowService->startWorkflow(
            entityType: 'risk_acceptance',
            entityId: $risk->getId(),
            workflowName: 'risk_acceptance_' . $approvalLevel
        );

        if (!$workflowInstance instanceof WorkflowInstance) {
            // Fallback: Manual tracking if no workflow defined
            $this->logger->warning('No workflow configured for risk acceptance, using manual tracking');
            return $this->createManualApprovalRequest($risk, $approver, $approvalLevel);
        }

        // Send notification to approver
        $this->sendApprovalNotification($risk, $approver, $approvalLevel);

        // Log workflow creation
        $this->auditLogger->logRiskAcceptanceRequested(
            $risk,
            $user,
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
     */
    private function createManualApprovalRequest(Risk $risk, User $user, string $approvalLevel): array
    {
        // Set status to indicate pending approval
        $risk->setStatus('assessed'); // Keep in assessed until approved

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Send notification
        $this->sendApprovalNotification($risk, $user, $approvalLevel);

        return [
            'status' => 'pending_approval',
            'approval_level' => $approvalLevel,
            'approver' => $user->getFullName(),
            'message' => sprintf(
                'Approval request sent to %s. Manual approval required.',
                $user->getFullName()
            ),
            'workflow_id' => null,
        ];
    }

    /**
     * Get required approver based on approval level
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
     */
    private function sendApprovalNotification(Risk $risk, User $user, string $approvalLevel): void
    {
        try {
            $this->emailNotificationService->sendRiskAcceptanceRequest(
                risk: $risk,
                approvalLevel: $approvalLevel,
                approver: $user
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to send approval notification', [
                'error' => $e->getMessage(),
                'risk_id' => $risk->getId(),
                'approver' => $user->getEmail(),
            ]);
        }
    }

    /**
     * Approve risk acceptance
     *
     * @param string $comments Optional approval comments
     * @return array Status information
     * @throws TransportExceptionInterface
     */
    public function approveAcceptance(Risk $risk, User $user, string $comments = ''): array
    {
        $now = new DateTime();

        $risk->setFormallyAccepted(true);
        $risk->setAcceptanceApprovedBy($user->getFullName());
        $risk->setAcceptanceApprovedAt($now);
        $risk->setStatus('accepted');

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Log approval
        $this->auditLogger->logRiskAcceptanceApproved(
            $risk,
            $user,
            $comments
        );

        $this->logger->info('Risk acceptance approved', [
            'risk_id' => $risk->getId(),
            'approver' => $user->getEmail(),
        ]);

        // Send email notification to risk owner
        if ($risk->getRiskOwner() instanceof User) {
            try {
                $this->emailNotificationService->sendRiskAcceptanceApproved(
                    $risk,
                    $risk->getRiskOwner(),
                    $user
                );
            } catch (Exception $e) {
                $this->logger->error('Failed to send approval notification', [
                    'error' => $e->getMessage(),
                    'risk_id' => $risk->getId(),
                ]);
            }
        }

        return [
            'status' => 'accepted',
            'approved_by' => $user->getFullName(),
            'approved_at' => $now,
            'message' => 'Risk acceptance has been approved',
        ];
    }

    /**
     * Reject risk acceptance
     *
     * @param string $reason Reason for rejection
     * @return array Status information
     * @throws TransportExceptionInterface
     */
    public function rejectAcceptance(Risk $risk, User $user, string $reason): array
    {
        $risk->setStatus('assessed'); // Return to assessed status
        $risk->setFormallyAccepted(false);

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Log rejection
        $this->auditLogger->logRiskAcceptanceRejected(
            $risk,
            $user,
            $reason
        );

        $this->logger->info('Risk acceptance rejected', [
            'risk_id' => $risk->getId(),
            'rejector' => $user->getEmail(),
            'reason' => $reason,
        ]);

        // Send email notification to risk owner
        if ($risk->getRiskOwner() instanceof User) {
            try {
                $this->emailNotificationService->sendRiskAcceptanceRejected(
                    $risk,
                    $risk->getRiskOwner(),
                    $reason,
                    $user
                );
            } catch (Exception $e) {
                $this->logger->error('Failed to send rejection notification', [
                    'error' => $e->getMessage(),
                    'risk_id' => $risk->getId(),
                ]);
            }
        }

        return [
            'status' => 'rejected',
            'rejected_by' => $user->getFullName(),
            'reason' => $reason,
            'message' => 'Risk acceptance has been rejected. Additional mitigation required.',
        ];
    }

    /**
     * Get approval thresholds configuration — tenant-specific wenn Risk übergeben,
     * sonst defaults. Werte kommen aus RiskApprovalConfig (Phase 8L.F1).
     *
     * @return array Approval level configuration
     */
    public function getApprovalThresholds(?Risk $risk = null): array
    {
        $tenant = $risk?->getTenant();
        $config = $tenant instanceof Tenant
            ? $this->approvalConfigResolver->resolveFor($tenant)
            : RiskApprovalConfigView::defaults();

        return [
            'automatic' => [
                'max_score' => $config->thresholdAutomatic,
                'label' => 'Automatic Acceptance',
                'description' => sprintf('Low risks (score ≤ %d) are automatically accepted', $config->thresholdAutomatic),
            ],
            'manager' => [
                'min_score' => $config->thresholdAutomatic + 1,
                'max_score' => $config->thresholdManager,
                'label' => 'Manager Approval Required',
                'description' => sprintf('Medium risks (score %d-%d) require manager approval', $config->thresholdAutomatic + 1, $config->thresholdManager),
            ],
            'executive' => [
                'min_score' => $config->thresholdManager + 1,
                'max_score' => $config->thresholdExecutive,
                'label' => 'Executive Approval Required',
                'description' => sprintf('High/Critical risks (score %d-%d) require executive approval', $config->thresholdManager + 1, $config->thresholdExecutive),
            ],
        ];
    }
}
