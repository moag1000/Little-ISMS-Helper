<?php

namespace App\Service;

use App\Entity\RiskTreatmentPlan;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Risk Treatment Plan Approval Service
 *
 * ISO 27005:2022 Clause 8.5.7 (Risk treatment plan approval) compliant
 * ISO 27001:2022 Clause 6.1.3 (Risk treatment) compliant
 *
 * Implements automatic approval workflow for risk treatment plans based on budget thresholds.
 *
 * Approval Levels (based on budget):
 * - Low-cost (< €10k): Single approval from Risk Manager (48h SLA)
 * - Medium-cost (€10k - €50k): Risk Manager + CISO approval required (72h SLA)
 * - High-cost (> €50k): Risk Manager + CISO + Management approval required (120h SLA)
 *
 * Prerequisites:
 * - Workflow definition must exist in database with entityType='RiskTreatmentPlan'
 * - Workflow should be created via: php bin/console app:seed-workflow-definitions
 *
 * Usage:
 * - Automatically triggered by WorkflowAutoTriggerListener on RiskTreatmentPlan postPersist
 * - Manual trigger: $service->requestApproval($plan)
 */
class RiskTreatmentPlanApprovalService
{
    // Cost thresholds for approval levels (in EUR)
    private const THRESHOLD_MEDIUM = 10000;  // €10,000
    private const THRESHOLD_HIGH = 50000;    // €50,000

    public function __construct(
        private readonly WorkflowService $workflowService,
        private readonly EmailNotificationService $emailService,
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger
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

        // Determine approval level based on budget
        $approvalLevel = $this->determineApprovalLevel($plan);

        // Check if workflow already exists for this plan
        $existingWorkflow = $this->workflowService->getWorkflowInstance(
            'RiskTreatmentPlan',
            $plan->getId()
        );

        if ($existingWorkflow && in_array($existingWorkflow->getStatus(), ['pending', 'in_progress'])) {
            $this->logger->info('Active workflow already exists for treatment plan', [
                'plan_id' => $plan->getId(),
                'workflow_id' => $existingWorkflow->getId(),
                'status' => $existingWorkflow->getStatus(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'workflow_already_active',
                'workflow_id' => $existingWorkflow->getId(),
            ];
        }

        // Start workflow using WorkflowService
        // This will look for a Workflow definition with entityType='RiskTreatmentPlan'
        try {
            $workflowInstance = $this->workflowService->startWorkflow(
                'RiskTreatmentPlan',
                $plan->getId(),
                'risk_treatment_plan_approval' // Optional: specific workflow name
            );

            if (!$workflowInstance) {
                $this->logger->warning('No workflow definition found for RiskTreatmentPlan', [
                    'plan_id' => $plan->getId(),
                ]);

                return [
                    'approval_level' => $approvalLevel,
                    'workflow_started' => false,
                    'reason' => 'no_workflow_definition',
                    'message' => 'Workflow definition for RiskTreatmentPlan not found. Please run: php bin/console app:seed-workflow-definitions',
                ];
            }

            // Send notifications to approvers
            $this->sendApprovalNotifications($plan, $workflowInstance, $approvalLevel);

            // Log audit event
            $this->auditLogger->logCustom(
                'risk_treatment_plan_approval_requested',
                'RiskTreatmentPlan',
                $plan->getId(),
                null, // oldValues
                [
                    'approval_level' => $approvalLevel,
                    'workflow_id' => $workflowInstance->getId(),
                    'budget' => $plan->getBudget(),
                ], // newValues
                sprintf('Approval requested for treatment plan (level: %s, budget: %s)',
                    $approvalLevel,
                    $plan->getBudget()
                ) // description
            );

            $this->logger->info('Treatment plan approval workflow started', [
                'plan_id' => $plan->getId(),
                'workflow_id' => $workflowInstance->getId(),
                'approval_level' => $approvalLevel,
                'status' => $workflowInstance->getStatus(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => true,
                'workflow_id' => $workflowInstance->getId(),
                'status' => $workflowInstance->getStatus(),
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to start treatment plan approval workflow', [
                'plan_id' => $plan->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'approval_level' => $approvalLevel,
                'workflow_started' => false,
                'reason' => 'workflow_start_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Determine approval level based on budget
     *
     * @param RiskTreatmentPlan $plan
     * @return string Approval level (low_cost, medium_cost, high_cost)
     */
    private function determineApprovalLevel(RiskTreatmentPlan $plan): string
    {
        $budget = $plan->getBudget() ? (float)$plan->getBudget() : 0;

        if ($budget >= self::THRESHOLD_HIGH) {
            return 'high_cost'; // > €50k: Management approval required
        } elseif ($budget >= self::THRESHOLD_MEDIUM) {
            return 'medium_cost'; // €10k - €50k: CISO approval required
        }

        return 'low_cost'; // < €10k: Risk Manager only
    }

    /**
     * Send approval notifications to workflow approvers
     *
     * @param RiskTreatmentPlan $plan
     * @param \App\Entity\WorkflowInstance $workflowInstance
     * @param string $approvalLevel
     */
    private function sendApprovalNotifications(
        RiskTreatmentPlan $plan,
        \App\Entity\WorkflowInstance $workflowInstance,
        string $approvalLevel
    ): void {
        // Get current step approver
        $currentStep = $workflowInstance->getCurrentStep();
        if (!$currentStep) {
            return;
        }

        $assignedUser = $currentStep->getAssignedUser();
        $assignedRole = $currentStep->getAssignedRole();

        // Send to specific user if assigned
        if ($assignedUser) {
            $this->sendNotificationToUser($plan, $assignedUser, $approvalLevel);
        }

        // Send to all users with assigned role
        if ($assignedRole) {
            $roleUsers = $this->userRepository->findByRole($assignedRole);
            foreach ($roleUsers as $user) {
                $this->sendNotificationToUser($plan, $user, $approvalLevel);
            }
        }
    }

    /**
     * Send notification email to a specific user
     *
     * @param RiskTreatmentPlan $plan
     * @param \App\Entity\User $user
     * @param string $approvalLevel
     */
    private function sendNotificationToUser(
        RiskTreatmentPlan $plan,
        \App\Entity\User $user,
        string $approvalLevel
    ): void {
        try {
            $this->emailService->sendEmail(
                $user->getEmail(),
                'Risk Treatment Plan Approval Required',
                'emails/treatment_plan_approval_notification.html.twig',
                [
                    'plan' => $plan,
                    'user' => $user,
                    'approval_level' => $approvalLevel,
                    'budget' => $plan->getBudget(),
                ]
            );

            $this->logger->info('Approval notification sent', [
                'plan_id' => $plan->getId(),
                'user_email' => $user->getEmail(),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to send approval notification', [
                'plan_id' => $plan->getId(),
                'user_email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
