<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\Tenant;
use App\Entity\WorkflowInstance;
use App\Enum\WorkflowInstanceStatus;
use App\Repository\WorkflowInstanceRepository;
use App\Service\Planning\Source\SourceAdapter;
use DateTimeInterface;

/**
 * SourceAdapter for WorkflowInstances (approval / regulatory workflows).
 *
 * Deadline field : dueDate — explicit due date set at dispatch time, if any.
 * Terminal statuses: approved, rejected, cancelled
 *   (pending, in_progress still require action from an approver)
 */
final class WorkflowInstanceAdapter implements SourceAdapter
{
    public function __construct(
        private readonly WorkflowInstanceRepository $repository,
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
}
