<?php

namespace App\Service;

use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Entity\User;
use App\Repository\WorkflowRepository;
use App\Repository\WorkflowInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class WorkflowService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowRepository $workflowRepository,
        private WorkflowInstanceRepository $workflowInstanceRepository,
        private Security $security
    ) {}

    /**
     * Start a workflow for an entity
     */
    public function startWorkflow(string $entityType, int $entityId, ?string $workflowName = null): ?WorkflowInstance
    {
        // Find appropriate workflow
        $workflow = $workflowName
            ? $this->workflowRepository->findOneBy(['name' => $workflowName, 'entityType' => $entityType, 'isActive' => true])
            : $this->workflowRepository->findOneBy(['entityType' => $entityType, 'isActive' => true]);

        if (!$workflow) {
            return null;
        }

        // Check if workflow instance already exists
        $existingInstance = $this->workflowInstanceRepository->findOneBy([
            'entityType' => $entityType,
            'entityId' => $entityId,
            'status' => ['pending', 'in_progress']
        ]);

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
        }

        $this->entityManager->persist($instance);
        $this->entityManager->flush();

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
        $this->moveToNextStep($instance);

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
     */
    private function moveToNextStep(WorkflowInstance $instance): void
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
        } else {
            // Workflow completed
            $instance->setStatus('approved');
            $instance->setCompletedAt(new \DateTimeImmutable());
            $instance->setCurrentStep(null);
        }
    }

    /**
     * Check if user can approve a step
     */
    private function canUserApprove(User $user, WorkflowStep $step): bool
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
