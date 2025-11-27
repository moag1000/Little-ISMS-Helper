<?php

namespace App\Service;

use App\Entity\RiskTreatmentPlan;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Risk Treatment Plan Approval Service
 *
 * ISO 27005:2022 Clause 8.5.7 (Risk treatment plan approval) compliant
 * ISO 27001:2022 Clause 6.1.3 (Risk treatment) compliant
 *
 * Implements automatic approval workflow for risk treatment plans:
 * - Low-cost plans (< €10k): Single approval from Risk Manager
 * - Medium-cost plans (€10k - €50k): Risk Manager + CISO approval required
 * - High-cost plans (> €50k): Risk Manager + CISO + Management approval required
 *
 * Features:
 * - Automatic approval routing based on estimated cost
 * - Multi-level approval chain for high-value plans
 * - Role-based notification to appropriate approvers
 * - Audit trail for compliance
 * - Budget threshold enforcement
 *
 * Approval Levels:
 * - Level 1 (< €10k): Risk Manager approval sufficient
 * - Level 2 (€10k - €50k): CISO approval required
 * - Level 3 (> €50k): Management approval required
 */
class RiskTreatmentPlanApprovalService
{
    // Cost thresholds for approval levels (in EUR)
    private const THRESHOLD_MEDIUM = 10000;  // €10,000
    private const THRESHOLD_HIGH = 50000;    // €50,000

    // Approval SLAs (hours)
    private const SLA_LOW_COST = 48;      // 2 days
    private const SLA_MEDIUM_COST = 72;   // 3 days
    private const SLA_HIGH_COST = 120;    // 5 days

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowService $workflowService,
        private readonly EmailNotificationService $emailService,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {}

    /**
     * Request approval for a risk treatment plan
     *
     * @param RiskTreatmentPlan $plan The treatment plan requiring approval
     * @return array Approval status and workflow information
     */
    public function requestApproval(RiskTreatmentPlan $plan): array
    {
        $this->logger->info('Requesting approval for risk treatment plan', [
            'plan_id' => $plan->getId(),
            'risk_id' => $plan->getRisk()?->getId(),
            'budget' => $plan->getBudget(),
        ]);

        // Determine approval level based on estimated cost
        $approvalLevel = $this->determineApprovalLevel($plan);
        $slaHours = $this->getSlaForApprovalLevel($approvalLevel);

        // Get approvers based on level
        $approvers = $this->getApproversForLevel($approvalLevel);

        if (empty($approvers)) {
            $this->logger->warning('No approvers found for treatment plan', [
                'plan_id' => $plan->getId(),
                'approval_level' => $approvalLevel,
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'no_approvers_found',
                'sla_hours' => $slaHours,
            ];
        }

        // Check for existing active workflow to prevent duplicates
        $existingWorkflow = $this->workflowService->getActiveWorkflowForEntity($plan);
        if ($existingWorkflow) {
            $this->logger->info('Active workflow already exists for treatment plan', [
                'plan_id' => $plan->getId(),
                'workflow_id' => $existingWorkflow->getId(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'workflow_already_active',
                'workflow_id' => $existingWorkflow->getId(),
            ];
        }

        // Create workflow instance
        try {
            $workflow = $this->createApprovalWorkflow($plan, $approvalLevel, $approvers, $slaHours);

            // Send notifications
            $this->sendApprovalNotifications($plan, $approvers, $approvalLevel);

            // Log audit event
            $this->auditLogger->log(
                'risk_treatment_plan_approval_requested',
                'RiskTreatmentPlan',
                $plan->getId(),
                [
                    'approval_level' => $approvalLevel,
                    'approvers' => array_map(fn($u) => $u->getEmail(), $approvers),
                    'budget' => $plan->getBudget(),
                    'sla_hours' => $slaHours,
                ]
            );

            $this->logger->info('Treatment plan approval workflow created', [
                'plan_id' => $plan->getId(),
                'workflow_id' => $workflow->getId(),
                'approval_level' => $approvalLevel,
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => true,
                'workflow_id' => $workflow->getId(),
                'approvers_count' => count($approvers),
                'sla_hours' => $slaHours,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to create treatment plan approval workflow', [
                'plan_id' => $plan->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'workflow_creation_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Determine approval level based on estimated cost
     *
     * @param RiskTreatmentPlan $plan
     * @return string Approval level (low_cost, medium_cost, high_cost)
     */
    private function determineApprovalLevel(RiskTreatmentPlan $plan): string
    {
        $cost = $plan->getBudget() ?? 0;

        if ($cost >= self::THRESHOLD_HIGH) {
            return 'high_cost'; // > €50k: Management approval required
        } elseif ($cost >= self::THRESHOLD_MEDIUM) {
            return 'medium_cost'; // €10k - €50k: CISO approval required
        }

        return 'low_cost'; // < €10k: Risk Manager only
    }

    /**
     * Get SLA hours for approval level
     *
     * @param string $approvalLevel
     * @return int SLA hours
     */
    private function getSlaForApprovalLevel(string $approvalLevel): int
    {
        return match ($approvalLevel) {
            'high_cost' => self::SLA_HIGH_COST,
            'medium_cost' => self::SLA_MEDIUM_COST,
            default => self::SLA_LOW_COST,
        };
    }

    /**
     * Get approvers for approval level
     *
     * @param string $approvalLevel
     * @return User[] Array of users who can approve
     */
    private function getApproversForLevel(string $approvalLevel): array
    {
        $approvers = [];

        // Level 1: Risk Manager (always required)
        $riskManagers = $this->userRepository->findByRole('ROLE_RISK_MANAGER');
        $approvers = array_merge($approvers, $riskManagers);

        // Level 2+: CISO
        if (in_array($approvalLevel, ['medium_cost', 'high_cost'])) {
            $cisos = $this->userRepository->findByRole('ROLE_CISO');
            $approvers = array_merge($approvers, $cisos);
        }

        // Level 3: Management (CEO, CTO, etc.)
        if ($approvalLevel === 'high_cost') {
            $management = $this->userRepository->findByRole('ROLE_MANAGEMENT');
            $approvers = array_merge($approvers, $management);
        }

        // Fallback: If no specific role found, use admins
        if (empty($approvers)) {
            $this->logger->warning('No role-specific approvers found, falling back to admins', [
                'approval_level' => $approvalLevel,
            ]);
            $approvers = $this->userRepository->findByRole('ROLE_ADMIN');
        }

        return array_unique($approvers, SORT_REGULAR);
    }

    /**
     * Create approval workflow instance
     *
     * @param RiskTreatmentPlan $plan
     * @param string $approvalLevel
     * @param User[] $approvers
     * @param int $slaHours
     * @return \App\Entity\WorkflowInstance
     */
    private function createApprovalWorkflow(
        RiskTreatmentPlan $plan,
        string $approvalLevel,
        array $approvers,
        int $slaHours
    ): \App\Entity\WorkflowInstance {
        // Find or create workflow definition
        $workflowDefinition = $this->workflowService->findOrCreateWorkflowDefinition(
            'risk_treatment_plan_approval',
            'Risk Treatment Plan Approval',
            sprintf('Approval workflow for risk treatment plans (%s)', $approvalLevel)
        );

        // Calculate deadline
        $deadline = new \DateTime();
        $deadline->modify(sprintf('+%d hours', $slaHours));

        // Create workflow instance
        $instance = $this->workflowService->startWorkflow(
            $workflowDefinition,
            $plan,
            [
                'approval_level' => $approvalLevel,
                'budget' => $plan->getBudget(),
                'risk_id' => $plan->getRisk()?->getId(),
                'approvers' => array_map(fn($u) => $u->getId(), $approvers),
                'deadline' => $deadline->format('Y-m-d H:i:s'),
            ]
        );

        // Add approval steps based on level
        $this->addApprovalSteps($instance, $approvalLevel, $approvers);

        return $instance;
    }

    /**
     * Add approval steps to workflow instance
     *
     * @param \App\Entity\WorkflowInstance $instance
     * @param string $approvalLevel
     * @param User[] $approvers
     */
    private function addApprovalSteps(
        \App\Entity\WorkflowInstance $instance,
        string $approvalLevel,
        array $approvers
    ): void {
        $stepOrder = 1;

        // Step 1: Risk Manager approval
        $riskManagers = array_filter($approvers, fn($u) => in_array('ROLE_RISK_MANAGER', $u->getRoles()));
        if (!empty($riskManagers)) {
            $this->workflowService->addWorkflowStep(
                $instance,
                'risk_manager_approval',
                'Risk Manager Review',
                reset($riskManagers),
                $stepOrder++
            );
        }

        // Step 2: CISO approval (if required)
        if (in_array($approvalLevel, ['medium_cost', 'high_cost'])) {
            $cisos = array_filter($approvers, fn($u) => in_array('ROLE_CISO', $u->getRoles()));
            if (!empty($cisos)) {
                $this->workflowService->addWorkflowStep(
                    $instance,
                    'ciso_approval',
                    'CISO Review',
                    reset($cisos),
                    $stepOrder++
                );
            }
        }

        // Step 3: Management approval (if required)
        if ($approvalLevel === 'high_cost') {
            $management = array_filter($approvers, fn($u) => in_array('ROLE_MANAGEMENT', $u->getRoles()));
            if (!empty($management)) {
                $this->workflowService->addWorkflowStep(
                    $instance,
                    'management_approval',
                    'Management Review',
                    reset($management),
                    $stepOrder++
                );
            }
        }
    }

    /**
     * Send approval notifications to approvers
     *
     * @param RiskTreatmentPlan $plan
     * @param User[] $approvers
     * @param string $approvalLevel
     */
    private function sendApprovalNotifications(
        RiskTreatmentPlan $plan,
        array $approvers,
        string $approvalLevel
    ): void {
        $planUrl = $this->urlGenerator->generate(
            'app_risk_treatment_plan_show',
            ['id' => $plan->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        foreach ($approvers as $approver) {
            try {
                $this->emailService->sendEmail(
                    $approver->getEmail(),
                    'Risk Treatment Plan Approval Required',
                    'emails/treatment_plan_approval_notification.html.twig',
                    [
                        'plan' => $plan,
                        'approver' => $approver,
                        'approval_level' => $approvalLevel,
                        'plan_url' => $planUrl,
                        'budget' => $plan->getBudget(),
                    ]
                );

                $this->logger->info('Approval notification sent', [
                    'plan_id' => $plan->getId(),
                    'approver_email' => $approver->getEmail(),
                ]);

            } catch (\Exception $e) {
                $this->logger->error('Failed to send approval notification', [
                    'plan_id' => $plan->getId(),
                    'approver_email' => $approver->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
