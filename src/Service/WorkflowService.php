<?php

namespace App\Service;

use DateTimeImmutable;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
        private readonly UserRepository $userRepository,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly Security $security
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
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $existingInstance = $queryBuilder->select('wi')
            ->from(WorkflowInstance::class, 'wi')
            ->where('wi.entityType = :entityType')
            ->andWhere('wi.entityId = :entityId')
            ->andWhere($queryBuilder->expr()->in('wi.status', ':statuses'))
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
        $workflowInstance = new WorkflowInstance();
        $workflowInstance->setWorkflow($workflow);
        $workflowInstance->setEntityType($entityType);
        $workflowInstance->setEntityId($entityId);
        $workflowInstance->setStatus('pending');
        $workflowInstance->setInitiatedBy($this->security->getUser());

        // Set first step as current
        $steps = $workflow->getSteps();
        if ($steps->count() > 0) {
            $firstStep = $steps->first();
            $workflowInstance->setCurrentStep($firstStep);

            // Calculate due date based on first step's SLA
            if ($firstStep->getDaysToComplete()) {
                $dueDate = new DateTimeImmutable()->modify('+' . $firstStep->getDaysToComplete() . ' days');
                $workflowInstance->setDueDate($dueDate);
            }

            $workflowInstance->setStatus('in_progress');

            // Handle notification step auto-progression or send assignment notification
            $this->handleStepAssignment($workflowInstance, $firstStep);
        }

        $this->entityManager->persist($workflowInstance);

        if ($autoFlush) {
            $this->entityManager->flush();
        }

        return $workflowInstance;
    }

    /**
     * Approve a workflow step
     */
    public function approveStep(WorkflowInstance $workflowInstance, User $user, ?string $comments = null): bool
    {
        if ($workflowInstance->getStatus() !== 'in_progress') {
            return false;
        }

        $currentStep = $workflowInstance->getCurrentStep();
        if (!$currentStep instanceof WorkflowStep) {
            return false;
        }

        // Check if user is allowed to approve
        if (!$this->canUserApprove($user, $currentStep)) {
            return false;
        }

        // Add to approval history
        $workflowInstance->addApprovalHistoryEntry([
            'step_id' => $currentStep->getId(),
            'step_name' => $currentStep->getName(),
            'action' => 'approved',
            'approver_id' => $user->getId(),
            'approver_name' => $user->getFirstName() . ' ' . $user->getLastName(),
            'comments' => $comments,
            'timestamp' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);

        // Mark step as completed
        $workflowInstance->addCompletedStep($currentStep->getId());

        // Move to next step or complete workflow
        $nextStep = $this->moveToNextStep($workflowInstance);

        // Handle next step (notification or approval notification)
        if ($nextStep instanceof WorkflowStep) {
            $this->handleStepAssignment($workflowInstance, $nextStep);
        }

        $this->entityManager->flush();

        return true;
    }

    /**
     * Reject a workflow step
     */
    public function rejectStep(WorkflowInstance $workflowInstance, User $user, string $reason): bool
    {
        if ($workflowInstance->getStatus() !== 'in_progress') {
            return false;
        }

        $currentStep = $workflowInstance->getCurrentStep();
        if (!$currentStep instanceof WorkflowStep) {
            return false;
        }

        // Check if user is allowed to reject
        if (!$this->canUserApprove($user, $currentStep)) {
            return false;
        }

        // Add to approval history
        $workflowInstance->addApprovalHistoryEntry([
            'step_id' => $currentStep->getId(),
            'step_name' => $currentStep->getName(),
            'action' => 'rejected',
            'approver_id' => $user->getId(),
            'approver_name' => $user->getFirstName() . ' ' . $user->getLastName(),
            'reason' => $reason,
            'timestamp' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);

        $workflowInstance->setStatus('rejected');
        $workflowInstance->setCompletedAt(new DateTimeImmutable());
        $workflowInstance->setComments($reason);

        $this->entityManager->flush();

        return true;
    }

    /**
     * Cancel a workflow instance
     */
    public function cancelWorkflow(WorkflowInstance $workflowInstance, string $reason): void
    {
        $workflowInstance->setStatus('cancelled');
        $workflowInstance->setCompletedAt(new DateTimeImmutable());
        $workflowInstance->setComments($reason);

        $this->entityManager->flush();
    }

    /**
     * Move workflow to next step or complete
     *
     * @return WorkflowStep|null The next step, or null if workflow is complete
     */
    private function moveToNextStep(WorkflowInstance $workflowInstance): ?WorkflowStep
    {
        $workflow = $workflowInstance->getWorkflow();
        $currentStep = $workflowInstance->getCurrentStep();

        $steps = $workflow->getSteps()->toArray();
        $currentIndex = array_search($currentStep, $steps);

        if ($currentIndex !== false && isset($steps[$currentIndex + 1])) {
            // Move to next step
            $nextStep = $steps[$currentIndex + 1];
            $workflowInstance->setCurrentStep($nextStep);

            // Update due date based on next step's SLA
            if ($nextStep->getDaysToComplete()) {
                $dueDate = new DateTimeImmutable()->modify('+' . $nextStep->getDaysToComplete() . ' days');
                $workflowInstance->setDueDate($dueDate);
            }

            return $nextStep;
        }
        // Workflow completed
        $workflowInstance->setStatus('approved');
        $workflowInstance->setCompletedAt(new DateTimeImmutable());
        $workflowInstance->setCurrentStep(null);
        return null;
    }

    /**
     * Handle step assignment - send notifications or auto-progress notification steps
     */
    private function handleStepAssignment(WorkflowInstance $workflowInstance, WorkflowStep $workflowStep): void
    {
        if ($workflowStep->getStepType() === 'notification') {
            // Auto-progress notification steps
            $this->autoProgressNotificationStep($workflowInstance, $workflowStep);
        } else {
            // Send assignment notification for approval steps
            $this->sendStepAssignmentNotification($workflowInstance, $workflowStep);
        }
    }

    /**
     * Auto-progress a notification step (no approval required)
     */
    private function autoProgressNotificationStep(WorkflowInstance $workflowInstance, WorkflowStep $workflowStep): void
    {
        // Add to history
        $workflowInstance->addApprovalHistoryEntry([
            'step_id' => $workflowStep->getId(),
            'step_name' => $workflowStep->getName(),
            'action' => 'notification_sent',
            'approver_id' => null,
            'approver_name' => 'System',
            'comments' => 'Notification step automatically processed',
            'timestamp' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);

        // Mark step as completed
        $workflowInstance->addCompletedStep($workflowStep->getId());

        // Send notification to assigned users
        $recipients = $this->getStepApprovers($workflowStep);
        if ($recipients !== []) {
            $this->emailNotificationService->sendWorkflowNotificationStepEmail($workflowInstance, $workflowStep, $recipients);
        }

        // Move to next step
        $nextStep = $this->moveToNextStep($workflowInstance);

        // Handle next step recursively (in case of consecutive notification steps)
        if ($nextStep instanceof WorkflowStep) {
            $this->handleStepAssignment($workflowInstance, $nextStep);
        }
    }

    /**
     * Send notification to approvers when a step is assigned
     */
    private function sendStepAssignmentNotification(WorkflowInstance $workflowInstance, WorkflowStep $workflowStep): void
    {
        $recipients = $this->getStepApprovers($workflowStep);
        if ($recipients !== []) {
            $this->emailNotificationService->sendWorkflowAssignmentNotification($workflowInstance, $workflowStep, $recipients);
        }
    }

    /**
     * Get all users who can approve a step
     *
     * @return User[]
     */
    private function getStepApprovers(WorkflowStep $workflowStep): array
    {
        $recipients = [];

        // Get approvers by user IDs
        $approverUserIds = $workflowStep->getApproverUsers() ?? [];
        if ($approverUserIds !== []) {
            $usersByIds = $this->userRepository->findBy(['id' => $approverUserIds]);
            $recipients = array_merge($recipients, $usersByIds);
        }

        // Get approvers by role
        $approverRole = $workflowStep->getApproverRole();
        if ($approverRole) {
            $usersByRole = $this->userRepository->findByRole($approverRole);
            $recipients = array_merge($recipients, $usersByRole);
        }

        // Remove duplicates
        $recipientIds = [];
        $uniqueRecipients = [];
        foreach ($recipients as $recipient) {
            if (!in_array($recipient->getId(), $recipientIds)) {
                $recipientIds[] = $recipient->getId();
                $uniqueRecipients[] = $recipient;
            }
        }

        return $uniqueRecipients;
    }

    /**
     * Check if user can approve a step
     */
    public function canUserApprove(User $user, WorkflowStep $workflowStep): bool
    {
        // Check by role
        if ($workflowStep->getApproverRole() && in_array($workflowStep->getApproverRole(), $user->getRoles())) {
            return true;
        }
        // Check by user ID
        return $workflowStep->getApproverUsers() && in_array($user->getId(), $workflowStep->getApproverUsers());
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

        return array_filter($instances, fn(WorkflowInstance $workflowInstance): bool => $workflowInstance->isOverdue());
    }
}
