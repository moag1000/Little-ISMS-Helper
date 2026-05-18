<?php

declare(strict_types=1);

namespace App\Service;

use DateMalformedStringException;
use DateTimeImmutable;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Entity\User;
use App\Enum\WorkflowInstanceStatus;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\WorkflowRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\UserRepository;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Workflow Management Service — public API facade (Sprint Y.0 / Y.2).
 *
 * Manages the lifecycle of approval workflows for ISMS entities.
 * Supports multi-step approval processes with configurable approvers and SLA tracking.
 *
 * Sprint Y.0: Internal status mutations have been replaced with
 * LifecycleTransitionInterface::transition() calls against the
 * `workflow_instance_lifecycle` Symfony state-machine
 * (config/workflows/workflow_instance.yaml).
 * The public method signatures are UNCHANGED — callers are unaffected.
 *
 * Sprint Y.2: startWorkflow() now consults RegulatoryWorkflowLoader first;
 * if the requested workflow name is registered in the Symfony Workflow Registry
 * (config/workflows/regulatory/*.yaml), the YAML-defined steps are used to seed
 * the WorkflowInstance. Falls back to DB-backed WorkflowRepository for tenant-custom
 * workflows not yet migrated to YAML.
 *
 * Workflow States (now driven by Symfony Workflow):
 * - pending:     Workflow created but not yet started (initial_marking)
 * - in_progress: First step reached; actively being processed
 * - approved:    All steps completed successfully
 * - rejected:    Rejected at any step by an authorised reviewer
 * - cancelled:   Manually cancelled (from pending or in_progress)
 */
class WorkflowService
{
    private const WORKFLOW_NAME = 'workflow_instance_lifecycle';

    /**
     * Map: entityType → canonical YAML workflow name (Sprint Y.2).
     * Used when startWorkflow() is called without an explicit $workflowName.
     */
    private const ENTITY_TYPE_TO_YAML_WORKFLOW = [
        'DataBreach' => 'gdpr_data_breach',
        'Incident' => 'incident_high_severity',
        'Risk' => 'risk_treatment',
        'DPIA' => 'dpia',
        'DataSubjectRequest' => 'dsr',
        'CorrectiveAction' => 'capa',
        'ChangeRequest' => 'change_request',
        'ManagementReview' => 'management_review',
        'Control' => 'control_verification',
        'Supplier' => 'supplier_assessment',
        'Training' => 'training_verification',
        'BusinessContinuityPlan' => 'bc_plan_activation',
        'Document' => 'document_review',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRepository $workflowRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
        private readonly UserRepository $userRepository,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly Security $security,
        private readonly LifecycleTransitionInterface $lifecycleService,
        private readonly RegulatoryWorkflowLoader $regulatoryWorkflowLoader,
    ) {}

    /**
     * Start a workflow for an entity.
     *
     * Sprint Y.2: tries YAML-registered regulatory workflow first (via
     * RegulatoryWorkflowLoader), then falls back to DB-backed WorkflowRepository
     * for tenant-custom workflows not yet migrated to YAML.
     *
     * @param string $entityType The entity type (e.g., 'Incident', 'Risk')
     * @param int $entityId The entity ID
     * @param string|null $workflowName Optional specific workflow name (YAML name or DB name)
     * @param bool $autoFlush Whether to automatically flush after persisting (default: true)
     * @return WorkflowInstance|null The workflow instance or null if no workflow found
     * @throws DateMalformedStringException
     */
    public function startWorkflow(string $entityType, int $entityId, ?string $workflowName = null, bool $autoFlush = true): ?WorkflowInstance
    {
        // Sprint Y.2 — YAML-first lookup: resolve the YAML workflow name for this entity type.
        // When a regulatory YAML is registered, we prefer it over the DB row.
        $yamlWorkflowName = $workflowName ?? (self::ENTITY_TYPE_TO_YAML_WORKFLOW[$entityType] ?? null);
        $yamlSteps = $yamlWorkflowName !== null
            ? $this->regulatoryWorkflowLoader->getStepsForWorkflow($yamlWorkflowName)
            : null;

        // Find appropriate workflow — DB lookup (backwards-compat for tenant-custom workflows).
        // Even when a YAML workflow is used, we still look up the DB row so that
        // WorkflowInstance.workflow_id FK is set (required for history display until Y.4).
        $workflow = $workflowName
            ? $this->workflowRepository->findOneBy(['name' => $workflowName, 'entityType' => $entityType, 'isActive' => true])
            : $this->workflowRepository->findOneBy(['entityType' => $entityType, 'isActive' => true]);

        // If neither YAML steps nor a DB workflow was found, give up
        if ($yamlSteps === null && !$workflow) {
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

        // Create new workflow instance — status starts at 'pending' (initial_marking).
        // The setStatus() call here is the only allowed direct setter: it seeds the
        // Symfony Workflow marking-store on a brand-new (pre-persist) entity.
        // All subsequent mutations go through LifecycleTransitionInterface::transition().
        // When only YAML steps are available (no DB row), startWorkflow cannot create
        // a WorkflowInstance that satisfies the nullable: false FK constraint on workflow_id.
        // In that case we return null — callers must ensure a DB row exists (see
        // GenerateRegulatoryWorkflowsCommand which seeds DB rows from the same YAML data).
        if (!$workflow) {
            return null;
        }

        $workflowInstance = new WorkflowInstance(); // @lifecycle-initial-state
        $workflowInstance->setWorkflow($workflow);
        $workflowInstance->setEntityType($entityType);
        $workflowInstance->setEntityId($entityId);
        $workflowInstance->setStatus(WorkflowInstanceStatus::Pending); // @lifecycle-initial-state — seeds SM
        $workflowInstance->setInitiatedBy($this->security->getUser());

        $this->entityManager->persist($workflowInstance);

        // Resolve steps: YAML-loader takes precedence; fall back to DB-backed workflow.
        // Sprint Y.2: when YAML steps are available, we resolve the first step from YAML
        // metadata. DB workflow steps are still used for WorkflowStep entity FK references
        // when the DB row exists.
        $hasYamlSteps = $yamlSteps !== null && count($yamlSteps) > 0;
        $dbSteps = $workflow?->getSteps();
        $hasDbSteps = $dbSteps !== null && $dbSteps->count() > 0;

        if ($hasYamlSteps || $hasDbSteps) {
            // Prefer DB step entity for the currentStep FK if available (audit-trail)
            $firstStep = $hasDbSteps ? $dbSteps->first() : null;
            $firstStepDays = $hasYamlSteps
                ? (int) ($yamlSteps[0]['days_to_complete'] ?? 0)
                : ($firstStep?->getDaysToComplete() ?? 0);

            if ($firstStep instanceof WorkflowStep) {
                $workflowInstance->setCurrentStep($firstStep);
            }
            $workflowInstance->setCurrentStepIndex(0);

            // Calculate due date based on first step's SLA
            if ($firstStepDays > 0) {
                $dueDate = new DateTimeImmutable()->modify('+' . $firstStepDays . ' days');
                $workflowInstance->setDueDate($dueDate);
            }

            // Flush before transition so the entity has an ID for the SM
            $this->entityManager->flush();

            // Transition pending → in_progress via Symfony state-machine
            $this->lifecycleService->transition(
                $workflowInstance,
                self::WORKFLOW_NAME,
                'start',
                $this->security->getUser() instanceof User ? $this->security->getUser() : null,
            );

            // Handle notification step auto-progression or send assignment notification
            if ($firstStep instanceof WorkflowStep) {
                $this->handleStepAssignment($workflowInstance, $firstStep);
            }
        } elseif ($autoFlush) {
            // No steps — stay in 'pending'; flush if requested
            $this->entityManager->flush();
        }

        return $workflowInstance;
    }

    /**
     * Approve a workflow step
     */
    public function approveStep(WorkflowInstance $workflowInstance, User $user, ?string $comments = null): bool
    {
        if ($workflowInstance->getStatus() !== WorkflowInstanceStatus::InProgress->value) {
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
        // NOTE: on the final step, moveToNextStep calls lifecycleService->transition('approve')
        // which includes a flush. For intermediate steps, flush below handles persistence.
        $nextStep = $this->moveToNextStep($workflowInstance);

        // Handle next step (notification or approval notification)
        if ($nextStep instanceof WorkflowStep) {
            $this->handleStepAssignment($workflowInstance, $nextStep);
        }

        // Flush for intermediate steps; harmless double-flush on final step (Doctrine no-ops empty EM)
        $this->entityManager->flush();

        return true;
    }

    /**
     * Reject a workflow step
     */
    public function rejectStep(WorkflowInstance $workflowInstance, User $user, string $reason): bool
    {
        if ($workflowInstance->getStatus() !== WorkflowInstanceStatus::InProgress->value) {
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

        $workflowInstance->setCompletedAt(new DateTimeImmutable());
        $workflowInstance->setComments($reason);

        // Transition in_progress → rejected via Symfony state-machine (includes flush)
        $this->lifecycleService->transition(
            $workflowInstance,
            self::WORKFLOW_NAME,
            'reject',
            $user,
            $reason,
        );

        return true;
    }

    /**
     * Cancel a workflow instance
     */
    public function cancelWorkflow(WorkflowInstance $workflowInstance, string $reason): void
    {
        $workflowInstance->setCompletedAt(new DateTimeImmutable());
        $workflowInstance->setComments($reason);

        // Choose cancel vs cancel_in_progress depending on current state
        $transitionName = $workflowInstance->getStatus() === WorkflowInstanceStatus::Pending->value ? 'cancel' : 'cancel_in_progress';

        // Transition → cancelled via Symfony state-machine (includes flush)
        $this->lifecycleService->transition(
            $workflowInstance,
            self::WORKFLOW_NAME,
            $transitionName,
            null,
            $reason,
        );
    }

    /**
     * Move workflow to next step or complete.
     *
     * Intermediate step advancement does NOT change WorkflowInstance.status — the SM
     * only tracks the coarse-grained approval-chain state. Only the final step
     * triggers a transition (in_progress → approved via LifecycleTransitionInterface).
     * currentStepIndex is updated as a plain field write alongside the step-ID
     * reference in currentStep.
     *
     * @return WorkflowStep|null The next step, or null if workflow is complete
     * @throws DateMalformedStringException
     */
    public function moveToNextStep(WorkflowInstance $workflowInstance): ?WorkflowStep
    {
        $workflow = $workflowInstance->getWorkflow();
        $currentStep = $workflowInstance->getCurrentStep();

        $steps = $workflow->getSteps()->toArray();
        $currentIndex = array_search($currentStep, $steps, strict: true);

        if ($currentIndex !== false && isset($steps[$currentIndex + 1])) {
            // Move to next step — status stays in_progress; only index advances
            $nextStep = $steps[$currentIndex + 1];
            $workflowInstance->setCurrentStep($nextStep);
            $workflowInstance->setCurrentStepIndex((int) $currentIndex + 1);

            // Update due date based on next step's SLA
            if ($nextStep->getDaysToComplete()) {
                $dueDate = new DateTimeImmutable()->modify('+' . $nextStep->getDaysToComplete() . ' days');
                $workflowInstance->setDueDate($dueDate);
            }

            return $nextStep;
        }

        // All steps completed → transition in_progress → approved via Symfony SM
        $workflowInstance->setCompletedAt(new DateTimeImmutable());
        $workflowInstance->setCurrentStep(null);

        // LifecycleTransitionInterface::transition calls flush internally
        $this->lifecycleService->transition(
            $workflowInstance,
            self::WORKFLOW_NAME,
            'approve',
        );

        return null;
    }

    /**
     * Handle step assignment - send notifications or auto-progress notification steps
     */
    public function handleStepAssignment(WorkflowInstance $workflowInstance, WorkflowStep $workflowStep): void
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
     * Check if a workflow step has any eligible approvers (non-admin users who can act on it)
     *
     * Returns true if there are users with the required role or explicit user assignments.
     * Returns false if only admins can approve (which indicates a configuration issue).
     */
    public function hasEligibleApprovers(WorkflowStep $workflowStep): bool
    {
        // Check if specific users are assigned
        $approverUsers = $workflowStep->getApproverUsers() ?? [];
        if ($approverUsers !== []) {
            // Verify at least one of the assigned users exists and is active
            $existingUsers = $this->userRepository->findBy([
                'id' => $approverUsers,
                'isActive' => true,
            ]);
            if (count($existingUsers) > 0) {
                return true;
            }
        }

        // Check if a role is assigned and users with that role exist
        $approverRole = $workflowStep->getApproverRole();
        if ($approverRole) {
            $usersWithRole = $this->userRepository->findByRole($approverRole);
            // Filter to only active users
            $activeUsersWithRole = array_filter($usersWithRole, fn($user) => $user->isActive());
            if (count($activeUsersWithRole) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all eligible approvers for a workflow step (public version of getStepApprovers)
     *
     * @return User[]
     */
    public function getEligibleApprovers(WorkflowStep $workflowStep): array
    {
        return $this->getStepApprovers($workflowStep);
    }

    /**
     * Check if user can approve a step
     *
     * Uses Symfony Security to respect role hierarchy. ROLE_ADMIN and ROLE_SUPER_ADMIN
     * can approve any workflow step regardless of the required role.
     */
    public function canUserApprove(User $user, WorkflowStep $workflowStep): bool
    {
        // ROLE_ADMIN and ROLE_SUPER_ADMIN can approve any step
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Check by user ID (explicit assignment)
        if ($workflowStep->getApproverUsers() && in_array($user->getId(), $workflowStep->getApproverUsers())) {
            return true;
        }

        // Check by role (using Security to respect role hierarchy)
        if ($workflowStep->getApproverRole()) {
            return $this->security->isGranted($workflowStep->getApproverRole());
        }

        return false;
    }

    /**
     * Get pending approvals for a user
     */
    public function getPendingApprovals(User $user): array
    {
        $tenant = $user->getTenant();
        $criteria = ['status' => WorkflowInstanceStatus::InProgress->value];
        if ($tenant !== null) {
            $criteria['tenant'] = $tenant;
        }
        $instances = $this->workflowInstanceRepository->findBy($criteria);
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
