<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\WorkflowInstance;
use App\Enum\WorkflowInstanceStatus;
use App\Lifecycle\LifecycleTransitionInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * When a WorkflowInstance reaches status 'approved', fires the parent
 * entity's lifecycle transition via LifecycleService. Bridges the legacy
 * multi-step approval-chain system (WorkflowInstance/WorkflowStep) with the
 * new Symfony-Workflow-backed state machine introduced in Task 13.
 *
 * Mapping from entity class → lifecycle workflow + transition is configured
 * in `config/packages/lifecycle.yaml` under the `lifecycle.approval_bridges`
 * parameter. Keys are fully-qualified entity class names; each value supplies:
 *   - workflow:    Symfony Workflow name (registered in config/workflows/*.yaml)
 *   - transition:  transition name to apply when approval is complete
 *
 * Like FieldCompletionAutoTransition, this listener is strictly best-effort:
 * exceptions during the lifecycle transition are caught and suppressed so the
 * original WorkflowInstance update is never rolled back.
 *
 * @see config/packages/lifecycle.yaml  lifecycle.approval_bridges parameter
 */
#[AsDoctrineListener(event: Events::postUpdate)]
final class WorkflowAutoProgressionBridge
{
    /**
     * @param array<class-string, array{workflow: string, transition: string}> $bridges
     */
    public function __construct(
        private readonly LifecycleTransitionInterface $lifecycleService,
        private readonly array $bridges = [],
    ) {}

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $entity = $event->getObject();

        if (!$entity instanceof WorkflowInstance) {
            return;
        }

        if ($entity->getStatus() !== WorkflowInstanceStatus::Approved->value) {
            return;
        }

        $entityClass = $entity->getEntityType();

        if ($entityClass === null || !isset($this->bridges[$entityClass])) {
            return;
        }

        $bridge = $this->bridges[$entityClass];
        $em = $event->getObjectManager();
        $parent = $em->find($entityClass, $entity->getEntityId());

        if ($parent === null) {
            return;
        }

        try {
            $this->lifecycleService->transition(
                $parent,
                $bridge['workflow'],
                $bridge['transition'],
                null,
                'Bridged from approval-chain (WorkflowInstance#' . $entity->getId() . ')',
            );
        } catch (\Throwable) {
            // Best-effort: never abort the WorkflowInstance update
        }
    }
}
