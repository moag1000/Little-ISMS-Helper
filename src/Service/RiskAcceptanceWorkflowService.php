<?php

declare(strict_types=1);

namespace App\Service;

use DateMalformedStringException;
use App\Entity\RiskAppetite;
use DateTime;
use App\Entity\WorkflowInstance;
use App\Entity\Risk;
use App\Entity\User;
use App\Entity\Tenant;
use App\Exception\BusinessRule\BusinessRuleException;
use App\Enum\ManagementDecisionType;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\UserRepository;
use App\Security\Persona\PersonaResolver;
use App\Service\ManagementDecision\ManagementDecisionService;
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
    // Hardcoded Fallback-Werte falls Tenant keine Config hat (die Werte
    // liegen per Tenant in der RiskApprovalConfig-Entity). Die Defaults
    // hier sind identisch zu den vorherigen PHP-Constants und werden vom
    // Resolver gespiegelt (RiskApprovalConfigView::defaults()).

    /** S12 / ISO 27001 8.3: an acceptance is reviewed within at most this many months. */
    public const int MAX_ACCEPTANCE_MONTHS = 36;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiskAppetitePrioritizationService $riskAppetitePrioritizationService,
        private readonly WorkflowService $workflowService,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly RiskApprovalConfigResolver $approvalConfigResolver,
        private readonly LifecycleTransitionInterface $lifecycleService,
        private readonly ManagementDecisionService $managementDecisions,
        private readonly PersonaResolver $personaResolver,
    ) {}

    /**
     * Request formal acceptance for a risk
     *
     * @param Risk $risk The risk to be accepted
     * @param User $user User requesting the acceptance
     * @param string $justification Justification for accepting the risk
     * @throws BusinessRuleException If risk does not qualify for acceptance
     * @return array Status information about the acceptance request
     */
    public function requestAcceptance(Risk $risk, User $user, string $justification, ?\DateTimeInterface $expiry = null): array
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
            throw new \App\Exception\BusinessRule\BusinessRuleException($appetiteCheck['reason'], 'risk_appetite_exceeded');
        }

        // 3. Determine required approval level
        $approvalLevel = $this->determineApprovalLevel($risk);

        // 4. Set acceptance justification and the review horizon the approver accepts (ISO 27001 8.3)
        $risk->setAcceptanceJustification($justification);
        if ($expiry instanceof \DateTimeInterface) {
            $risk->setAcceptanceExpiryDate(DateTime::createFromInterface($expiry));
        }

        // 5. Handle based on approval level
        if ($approvalLevel === 'automatic') {
            return $this->processAutomaticAcceptance($risk, $user);
        }
        return $this->createApprovalWorkflow($risk, $user, $approvalLevel);
    }

    /**
     * S12: re-evaluation or extension of a granted acceptance. The standing
     * acceptance ends (audit log, lifecycle back to "assessed") and a new
     * request with the new reason, residual rating and expiry goes to the
     * approver of its level — an acceptance is never extended without one.
     *
     * @return array<string, mixed> same as requestAcceptance()
     */
    public function requestReevaluation(Risk $risk, User $user, string $justification, ?\DateTimeInterface $expiry = null): array
    {
        if ($risk->isFormallyAccepted()) {
            $this->endAcceptance($risk, $user, 'Re-evaluation requested: ' . $justification);
        }

        return $this->requestAcceptance($risk, $user, $justification, $expiry);
    }

    /** Ends a granted acceptance; who accepted when stays in the audit log and lifecycle history. */
    private function endAcceptance(Risk $risk, User $user, string $reason): void
    {
        $old = [
            'accepted_by' => $risk->getAcceptanceApprovedByUser()?->getEmail() ?? $risk->getAcceptanceApprovedBy(),
            'accepted_at' => $risk->getAcceptanceApprovedAt()?->format('Y-m-d'),
            'expiry' => $risk->getAcceptanceExpiryDate()?->format('Y-m-d'),
        ];
        $risk->setFormallyAccepted(false);
        $risk->setAcceptanceApprovedByUser(null);
        $risk->setAcceptanceApprovedBy(null);
        $risk->setAcceptanceApprovedAt(null);
        $this->entityManager->flush();

        if ($risk->getStatus() === RiskStatus::Accepted) {
            // The risk's manager or responsible person asks for a re-evaluation (controller gate);
            // ending an acceptance only takes a permission away, so it needs no risk-manager role (S21).
            $this->lifecycleService->transition($risk, 'risk_lifecycle', 'revert_to_assessed', $user, $reason, approverAuthorityVerified: true);
        }
        $this->auditLogger->logCustom(
            action: 'risk_acceptance_ended',
            entityType: 'Risk',
            entityId: $risk->getId(),
            oldValues: $old,
            newValues: null,
            description: 'Risk acceptance ended for re-evaluation',
            userName: $user->getEmail(),
        );
    }

    /**
     * S12 / ISO 27001 6.1.3 f: the risk owner (or a manager) approves the
     * treatment decision; who and when come from the login.
     */
    public function approveTreatmentDecision(Risk $risk, User $user, ?string $comment = null): void
    {
        $old = $risk->getDecisionApprovedByUser();
        $risk->setDecisionApprovedByUser($user)->setDecisionApprovalDate(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->auditLogger->logCustom(
            action: 'risk_decision_approved',
            entityType: 'Risk',
            entityId: $risk->getId(),
            oldValues: ['decision_approved_by' => $old?->getEmail()],
            newValues: [
                'decision_approved_by' => $user->getEmail(),
                'treatment' => $risk->getTreatmentStrategy()?->value,
                'comment' => $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 1000) : null,
            ],
            description: 'Risk treatment decision approved (ISO 27001 6.1.3 f)',
            userName: $user->getEmail(),
        );
    }

    /**
     * Validate if risk qualifies for acceptance strategy
     *
     * @throws BusinessRuleException If risk doesn't qualify
     */
    private function validateRiskForAcceptance(Risk $risk): void
    {
        // Check if risk has "accept" treatment strategy
        if ($risk->getTreatmentStrategy() !== TreatmentStrategy::Accept) {
            throw new \App\Exception\BusinessRule\BusinessRuleException(
                'Risk must have "accept" treatment strategy. Current strategy: ' . $risk->getTreatmentStrategy()?->value
            );
        }

        // Risk must have assessment completed
        if (!$risk->getProbability() || !$risk->getImpact()) {
            throw new \App\Exception\BusinessRule\BusinessRuleException('Risk assessment must be completed before acceptance', 'assessment_incomplete');
        }

        // Residual risk should be assessed
        if (!$risk->getResidualProbability() || !$risk->getResidualImpact()) {
            throw new \App\Exception\BusinessRule\BusinessRuleException('Residual risk must be assessed before acceptance', 'residual_incomplete');
        }

        // Check if already formally accepted
        if ($risk->isFormallyAccepted()) {
            throw new \App\Exception\BusinessRule\BusinessRuleException('Risk is already formally accepted', 'already_accepted');
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
        $tenant = $risk->getTenant();
        $config = $tenant instanceof Tenant
            ? $this->approvalConfigResolver->resolveFor($tenant)
            : RiskApprovalConfigView::defaults();

        $level = $config->levelFor($risk->getResidualRiskLevel());

        // ISO 27001 6.1.2 a / 6.1.3 f: risks are accepted automatically only
        // under acceptance criteria top management approved — without that
        // approval (or after a threshold changed) the risk manager decides.
        if ($level === 'automatic'
            && (!$tenant instanceof Tenant || $this->managementDecisions->criteriaApproval($tenant) === null)) {
            return 'manager';
        }

        return $level;
    }

    /**
     * Process automatic acceptance for low risks
     *
     * @return array Status information
     */
    private function processAutomaticAcceptance(Risk $risk, User $user): array
    {
        $now = new DateTime();

        $tenant = $risk->getTenant();
        $basis = $tenant instanceof Tenant ? $this->managementDecisions->criteriaApproval($tenant) : null;

        $risk->setFormallyAccepted(true);
        $risk->setAcceptanceApprovedBy($user->getFullName() . ' (automatic)');
        $risk->setAcceptanceApprovedAt($now);

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Every automatic acceptance points to the approval it rests on.
        if ($tenant instanceof Tenant && $basis !== null) {
            $this->managementDecisions->record(
                tenant: $tenant,
                type: ManagementDecisionType::AutoPolicy,
                title: (string) $risk->getTitle(),
                text: $risk->getAcceptanceJustification(),
                decidedBy: null,
                comment: null,
                subjectType: 'Risk',
                subjectId: $risk->getId(),
                snapshot: ['residual_score' => $risk->getResidualRiskLevel()],
                basis: $basis,
            );
        }

        // X.6: accept transition (assessed → accepted) via risk_lifecycle.
        // Risk must be in 'assessed' state; validateRiskForAcceptance() guards this path.
        $this->lifecycleService->transition(
            $risk,
            'risk_lifecycle',
            'accept',
            $user,
            'Automatically accepted (score ≤ threshold)',
            // Authority = the acceptance criteria top management approved (6.1.3 f), not the requester's role (S21).
            approverAuthorityVerified: true,
        );

        // Log acceptance
        $this->auditLogger->logRiskAcceptance(
            $risk,
            $user,
            sprintf('Automatically accepted (score %d within approved criteria)', $risk->getResidualRiskLevel())
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
            throw new \App\Exception\BusinessRule\BusinessRuleException(
                sprintf('No %s approver configured for tenant', $approvalLevel),
                'no_approver_configured'
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
        // X.6: Risk is already in 'assessed' state (guaranteed by validateRiskForAcceptance()).
        // No transition needed here — keep in assessed while manual approval is pending.
        // The explicit setStatus(Assessed) was a defensive no-op that bypassed LifecycleService;
        // removed as part of X.6 migration.
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
        $role = RiskApprovalConfigView::approverRole($approvalLevel);

        // Someone holding the role itself first (the board member, not an
        // admin who inherits it), then anyone reaching it through the hierarchy.
        $users = $this->userRepository->findBy(['tenant' => $tenant]);
        foreach ($users as $user) {
            if (in_array($role, $user->getRoles(), true)) {
                return $user;
            }
        }
        foreach ($users as $user) {
            if ($this->personaResolver->reaches($user, $role)) {
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
                user: $user,
                approvalLevel: $approvalLevel,
            );
        } catch (\Throwable $e) {
            // Catch-all: a failed notification must never abort the acceptance request.
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
        // ISO 27001 6.1.3 f: the approver is a user, not a typed name
        // (the free-text column is legacy and only read as a fallback).
        $risk->setAcceptanceApprovedByUser($user);
        $risk->setAcceptanceApprovedAt($now);

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // X.6: accept transition (assessed → accepted) via risk_lifecycle.
        // approveAcceptance() is called after manager/executive approval; risk stays
        // in 'assessed' during pending phase (createManualApprovalRequest does not change it).
        $this->lifecycleService->transition(
            $risk,
            'risk_lifecycle',
            'accept',
            $user,
            'Risk acceptance approved: ' . ($comments !== '' ? $comments : 'no comments'),
            // RiskAcceptanceVoter::APPROVE checked the approval tier (manager / CISO / top management).
            approverAuthorityVerified: true,
        );

        // A board-level acceptance is a decision of top management (6.1.3 f).
        $tenant = $risk->getTenant();
        if ($tenant instanceof Tenant && $this->determineApprovalLevel($risk) === 'executive') {
            $this->managementDecisions->record(
                tenant: $tenant,
                type: ManagementDecisionType::RiskAcceptance,
                title: (string) $risk->getTitle(),
                text: $risk->getAcceptanceJustification(),
                decidedBy: $user,
                comment: $comments !== '' ? $comments : null,
                subjectType: 'Risk',
                subjectId: $risk->getId(),
                snapshot: ['residual_score' => $risk->getResidualRiskLevel()],
            );
        }

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
            } catch (\Throwable $e) {
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
        $wasAccepted = $risk->getStatus() === RiskStatus::Accepted;
        $risk->setFormallyAccepted(false);
        // The request is decided: clear it, so the risk leaves the approver's queue
        // (My Day, risk page). The reason stays in the audit log and lifecycle history.
        $risk->setAcceptanceJustification(null);

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // revert_to_assessed (accepted → assessed) only when a granted acceptance is
        // withdrawn; a still pending request never left "assessed", and the transition
        // would throw there, so a rejection from the queue failed every time.
        if ($wasAccepted) {
            $this->lifecycleService->transition(
                $risk,
                'risk_lifecycle',
                'revert_to_assessed',
                $user,
                'Risk acceptance rejected: ' . $reason,
                // RiskAcceptanceVoter::APPROVE checked the approval tier.
                approverAuthorityVerified: true,
            );
        }

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
            } catch (\Throwable $e) {
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
     * sonst defaults. Werte kommen aus RiskApprovalConfig.
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
