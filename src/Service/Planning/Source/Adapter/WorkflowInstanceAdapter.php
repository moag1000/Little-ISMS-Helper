<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\Tenant;
use App\Entity\WorkflowInstance;
use App\Enum\WorkflowInstanceStatus;
use App\Repository\UserRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Planning\Source\SourceAdapter;
use App\Service\Responsibility\Responsibility;
use App\Service\WorkflowService;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SourceAdapter for WorkflowInstances (approval / regulatory workflows).
 *
 * Deadline field : dueDate — explicit due date set at dispatch time, if any.
 * Terminal statuses: approved, rejected, cancelled
 *   (pending, in_progress still require action from an approver)
 */
final class WorkflowInstanceAdapter implements SourceAdapter
{
    /** Step approver keyword: whoever is responsible for the workflow's record. */
    public const string APPROVER_OWNER = 'owner';

    public function __construct(
        private readonly WorkflowInstanceRepository $repository,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {}

    public function slug(): string
    {
        return 'workflow';
    }

    public function label(): string
    {
        return 'Workflow-Instanz';
    }

    public function requiredModule(): string
    {
        return 'workflows';
    }

    /** @return iterable<WorkflowInstance> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findActiveForTenant($tenant);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof WorkflowInstance);
        return $item->getDueDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof WorkflowInstance);
        $workflowName = $item->getWorkflow()?->getName();
        if ($workflowName !== null) {
            return sprintf('Workflow #%d — %s', (int) $item->getId(), $workflowName);
        }
        return sprintf('Workflow #%d', (int) $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof WorkflowInstance);
        return in_array($item->getStatusEnum(), [
            WorkflowInstanceStatus::Approved,
            WorkflowInstanceStatus::Rejected,
            WorkflowInstanceStatus::Cancelled,
        ], true);
    }

    public function ownsRecurrence(): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof WorkflowInstance);
        return (int) $item->getId();
    }

    /** @return iterable<WorkflowInstance> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->findConvertible($tenant);
    }

    public function supports(object $item): bool
    {
        return $item instanceof WorkflowInstance;
    }

    /**
     * The current step's named approvers, else its role. `approver_role: owner`
     * hands the step to whoever is responsible for the workflow's record
     * (risk owner, document owner, …) — never to a role nobody holds.
     */
    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof WorkflowInstance);
        $step = $item->getCurrentStep();
        if ($step === null) {
            return Responsibility::nobody();
        }
        $named = $this->userRepository !== null && $item->getTenant() !== null
            ? $this->userRepository->findByIdsInTenant($step->getApproverUsers() ?? [], $item->getTenant())
            : [];
        $role = (string) $step->getApproverRole();
        $responsibility = Responsibility::holders(...$named);
        if ($role === self::APPROVER_OWNER) {
            return Responsibility::ofRecord($this->subjectOf($item))->orRoles('ROLE_MANAGER');
        }

        return $role !== '' ? $responsibility->orRoles($role) : $responsibility;
    }

    public function linkOf(object $item): ?RecordLink
    {
        assert($item instanceof WorkflowInstance);

        return new RecordLink('app_workflow_instance_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'workflows_pending', leadDays: null, decision: true);
    }

    private function subjectOf(WorkflowInstance $instance): ?object
    {
        $class = WorkflowService::subjectClass($instance->getEntityType());
        $id = $instance->getEntityId();
        if ($this->entityManager === null || $class === null || $id === null
            || !$this->entityManager->getMetadataFactory()->hasMetadataFor($class)) {
            return null;
        }

        return $this->entityManager->find($class, $id);
    }
}
