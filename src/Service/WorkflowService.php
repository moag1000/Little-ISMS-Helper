<?php

namespace App\Service;

use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Entity\User;
use App\Repository\WorkflowRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Workflow Management Service
 *
 * Manages the lifecycle of approval workflows for ISMS entities.
 * Supports multi-step approval processes with configurable approvers and SLA tracking.
 *
 * Features:
 * - Workflow instance creation and management
 * - Step-by-step approval tracking
 * - SLA-based due date calculation
 * - Approval/rejection with history tracking
 * - Role-based and user-based approver assignment
 * - Overdue workflow detection
 * - Pending approval queries per user
 *
 * Workflow States:
 * - pending: Workflow created but not started
 * - in_progress: Actively being processed
 * - approved: Successfully completed all steps
 * - rejected: Rejected at any step
 * - cancelled: Manually cancelled
 */
class WorkflowService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowRepository $workflowRepository,
        private WorkflowInstanceRepository $workflowInstanceRepository,
        private UserRepository $userRepository,
        private EmailNotificationService $emailService,
        private Security $security
    ) {}

    /**
     * Start a workflow for an entity
     *
     * @param string $entityType The entity type (e.g., 'Incident', 'Risk')
     * @param int $entityId The entity ID
     * @param string|null $workflowName Optional specific workflow name
     * @param bool $autoFlush Whether to automatically flush after persisting (default: true)
     * @return WorkflowInstance|null The workflow instance or null if no workflow found
     */
    public function startWorkflow(string $entityType, int $entityId, ?string $workflowName = null, bool $autoFlush = true): ?WorkflowInstance
    {
        // Find appropriate workflow
        $workflow = $workflowName
            ? $this->workflowRepository->findOneBy(['name' => $workflowName, 'entityType' => $entityType, 'isActive' => true])
            : $this->workflowRepository->findOneBy(['entityType' => $entityType, 'isActive' => true]);

        if (!$workflow) {
            return null;
        }

        // Check if workflow instance already exists
        // Note: findOneBy doesn't support array values, use QueryBuilder
        $qb = $this->entityManager->createQueryBuilder();
        $existingInstance = $qb->select('wi')
            ->from(WorkflowInstance::class, 'wi')
            ->where('wi.entityType = :entityType')
            ->andWhere('wi.entityId = :entityId')
            ->andWhere($qb->expr()->in('wi.status', ':statuses'))
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->setParameter('statuses', ['pending', 'in_progress'])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingInstance) {
            return $existingInstance;
        }

        // Create new workflow instance
        $instance = new WorkflowInstance();
        $instance->setWorkflow($workflow);
        $instance->setEntityType($entityType);
        $instance->setEntityId($entityId);
        $instance->setStatus('pending');
        $instance->setInitiatedBy($this->security->getUser());

        // Set first step as current
        $steps = $workflow->getSteps();
        if ($steps->count() > 0) {
            $firstStep = $steps->first();
            $instance->setCurrentStep($firstStep);

            // Calculate due date based on first step's SLA
            if ($firstStep->getDaysToComplete()) {
                $dueDate = (new \DateTimeImmutable())->modify('+' . $firstStep->getDaysToComplete() . ' days');
                $instance->setDueDate($dueDate);
            }

            $instance->setStatus('in_progress');

            // Handle notification step auto-progression or send assignment notification
            $this->handleStepAssignment($instance, $firstStep);
        }

        $this->entityManager->persist($instance);

        if ($autoFlush) {
            $this->entityManager->flush();
        }

        return $instance;
    }

    /**
     * Approve a workflow step
     */
    public function approveStep(WorkflowInstance $instance, User $approver, ?string $comments = null): bool
    {
        if ($instance->getStatus() !== 'in_progress') {
            return false;
        }

        $currentStep = $instance->getCurrentStep();
        if (!$currentStep) {
            return false;
        }

        // Check if user is allowed to approve
        if (!$this->canUserApprove($approver, $currentStep)) {
            return false;
        }

        // Add to approval history
        $instance->addApprovalHistoryEntry([
            'step_id' => $currentStep->getId(),
            'step_name' => $currentStep->getName(),
            'action' => 'approved',
            'approver_id' => $approver->getId(),
            'approver_name' => $approver->getFirstName() . ' ' . $approver->getLastName(),
            'comments' => $comments,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // Mark step as completed
        $instance->addCompletedStep($currentStep->getId());

        // Move to next step or complete workflow
        $nextStep = $this->moveToNextStep($instance);

        // Handle next step (notification or approval notification)
        if ($nextStep) {
            $this->handleStepAssignment($instance, $nextStep);
        }

        $this->entityManager->flush();

        return true;
    }

    /**
     * Reject a workflow step
     */
    public function rejectStep(WorkflowInstance $instance, User $approver, string $reason): bool
    {
        if ($instance->getStatus() !== 'in_progress') {
            return false;
        }

        $currentStep = $instance->getCurrentStep();
        if (!$currentStep) {
            return false;
        }

        // Check if user is allowed to reject
        if (!$this->canUserApprove($approver, $currentStep)) {
            return false;
        }

        // Add to approval history
        $instance->addApprovalHistoryEntry([
            'step_id' => $currentStep->getId(),
            'step_name' => $currentStep->getName(),
            'action' => 'rejected',
            'approver_id' => $approver->getId(),
            'approver_name' => $approver->getFirstName() . ' ' . $approver->getLastName(),
            'reason' => $reason,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $instance->setStatus('rejected');
        $instance->setCompletedAt(new \DateTimeImmutable());
        $instance->setComments($reason);

        $this->entityManager->flush();

        return true;
    }

    /**
     * Cancel a workflow instance
     */
    public function cancelWorkflow(WorkflowInstance $instance, string $reason): void
    {
        $instance->setStatus('cancelled');
        $instance->setCompletedAt(new \DateTimeImmutable());
        $instance->setComments($reason);

        $this->entityManager->flush();
    }

    /**
     * Move workflow to next step or complete
     *
     * @return WorkflowStep|null The next step, or null if workflow is complete
     */
    private function moveToNextStep(WorkflowInstance $instance): ?WorkflowStep
    {
        $workflow = $instance->getWorkflow();
        $currentStep = $instance->getCurrentStep();

        $steps = $workflow->getSteps()->toArray();
        $currentIndex = array_search($currentStep, $steps);

        if ($currentIndex !== false && isset($steps[$currentIndex + 1])) {
            // Move to next step
            $nextStep = $steps[$currentIndex + 1];
            $instance->setCurrentStep($nextStep);

            // Update due date based on next step's SLA
            if ($nextStep->getDaysToComplete()) {
                $dueDate = (new \DateTimeImmutable())->modify('+' . $nextStep->getDaysToComplete() . ' days');
                $instance->setDueDate($dueDate);
            }

            return $nextStep;
        } else {
            // Workflow completed
            $instance->setStatus('approved');
            $instance->setCompletedAt(new \DateTimeImmutable());
            $instance->setCurrentStep(null);

            return null;
        }
    }

    /**
     * Handle step assignment - send notifications or auto-progress notification steps
     */
    private function handleStepAssignment(WorkflowInstance $instance, WorkflowStep $step): void
    {
        if ($step->getStepType() === 'notification') {
            // Auto-progress notification steps
            $this->autoProgressNotificationStep($instance, $step);
        } else {
            // Send assignment notification for approval steps
            $this->sendStepAssignmentNotification($instance, $step);
        }
    }

    /**
     * Auto-progress a notification step (no approval required)
     */
    private function autoProgressNotificationStep(WorkflowInstance $instance, WorkflowStep $step): void
    {
        // Add to history
        $instance->addApprovalHistoryEntry([
            'step_id' => $step->getId(),
            'step_name' => $step->getName(),
            'action' => 'notification_sent',
            'approver_id' => null,
            'approver_name' => 'System',
            'comments' => 'Notification step automatically processed',
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // Mark step as completed
        $instance->addCompletedStep($step->getId());

        // Send notification to assigned users
        $recipients = $this->getStepApprovers($step);
        if (!empty($recipients)) {
            $this->emailService->sendWorkflowNotificationStepEmail($instance, $step, $recipients);
        }

        // Move to next step
        $nextStep = $this->moveToNextStep($instance);

        // Handle next step recursively (in case of consecutive notification steps)
        if ($nextStep) {
            $this->handleStepAssignment($instance, $nextStep);
        }
    }

    /**
     * Send notification to approvers when a step is assigned
     */
    private function sendStepAssignmentNotification(WorkflowInstance $instance, WorkflowStep $step): void
    {
        $recipients = $this->getStepApprovers($step);
        if (!empty($recipients)) {
            $this->emailService->sendWorkflowAssignmentNotification($instance, $step, $recipients);
        }
    }

    /**
     * Get all users who can approve a step
     *
     * @return User[]
     */
    private function getStepApprovers(WorkflowStep $step): array
    {
        $recipients = [];

        // Get approvers by user IDs
        $approverUserIds = $step->getApproverUsers() ?? [];
        if (!empty($approverUserIds)) {
            $usersByIds = $this->userRepository->findBy(['id' => $approverUserIds]);
            $recipients = array_merge($recipients, $usersByIds);
        }

        // Get approvers by role
        $approverRole = $step->getApproverRole();
        if ($approverRole) {
            $usersByRole = $this->userRepository->findByRole($approverRole);
            $recipients = array_merge($recipients, $usersByRole);
        }

        // Remove duplicates
        $recipientIds = [];
        $uniqueRecipients = [];
        foreach ($recipients as $user) {
            if (!in_array($user->getId(), $recipientIds)) {
                $recipientIds[] = $user->getId();
                $uniqueRecipients[] = $user;
            }
        }

        return $uniqueRecipients;
    }

    /**
     * Check if user can approve a step
     */
    public function canUserApprove(User $user, WorkflowStep $step): bool
    {
        // Check by role
        if ($step->getApproverRole()) {
            if (in_array($step->getApproverRole(), $user->getRoles())) {
                return true;
            }
        }

        // Check by user ID
        if ($step->getApproverUsers()) {
            if (in_array($user->getId(), $step->getApproverUsers())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get pending approvals for a user
     */
    public function getPendingApprovals(User $user): array
    {
        $instances = $this->workflowInstanceRepository->findBy(['status' => 'in_progress']);
        $pending = [];

        foreach ($instances as $instance) {
            $currentStep = $instance->getCurrentStep();
            if ($currentStep && $this->canUserApprove($user, $currentStep)) {
                $pending[] = $instance;
            }
        }

        return $pending;
    }

    /**
     * Get workflow instance for an entity
     */
    public function getWorkflowInstance(string $entityType, int $entityId): ?WorkflowInstance
    {
        return $this->workflowInstanceRepository->findOneBy([
            'entityType' => $entityType,
            'entityId' => $entityId,
        ], ['id' => 'DESC']);
    }

    /**
     * Get all active workflow instances
     */
    public function getActiveWorkflows(): array
    {
        return $this->workflowInstanceRepository->findBy([
            'status' => ['pending', 'in_progress']
        ], ['startedAt' => 'DESC']);
    }

    /**
     * Get overdue workflows
     */
    public function getOverdueWorkflows(): array
    {
        $instances = $this->workflowInstanceRepository->findBy([
            'status' => ['pending', 'in_progress']
        ]);

        return array_filter($instances, fn($instance) => $instance->isOverdue());
    }
}
