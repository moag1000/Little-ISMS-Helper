<?php

declare(strict_types=1);

namespace App\Lifecycle;

use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\Exception\TransitionException;
use Symfony\Component\Workflow\Registry;

/**
 * Facade over Symfony Workflow component. Keeps the audit-s3 P-4 API
 * stable while internally delegating state-machine logic to
 * `symfony/workflow`.
 *
 * Callers must pass a `$workflowName` registered in
 * `config/workflows/*.yaml`. The marking_store is `method`, so the
 * entity must expose `getStatus()/setStatus()`.
 */
final class LifecycleService
{
    public function __construct(
        private readonly Registry $workflowRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @throws InvalidTransitionException
     */
    public function transition(
        object $entity,
        string $workflowName,
        string $transitionName,
        ?User $user = null,
        ?string $reason = null,
    ): void {
        if (!method_exists($entity, 'getStatus') || !method_exists($entity, 'setStatus')) {
            throw new \LogicException(sprintf(
                'Entity %s lacks getStatus()/setStatus() and cannot be lifecycle-managed.',
                $entity::class,
            ));
        }

        $workflow = $this->workflowRegistry->get($entity, $workflowName);
        $current = (string) $entity->getStatus();

        try {
            $workflow->apply($entity, $transitionName, [
                'user' => $user,
                'reason' => $reason,
            ]);
        } catch (NotEnabledTransitionException $e) {
            $allowed = array_map(
                static fn ($t) => $t->getName(),
                $workflow->getEnabledTransitions($entity),
            );
            throw new InvalidTransitionException(
                message: sprintf(
                    'Transition "%s" not enabled for %s in state "%s". Allowed: %s.',
                    $transitionName,
                    $entity::class,
                    $current,
                    $allowed === [] ? '<none>' : implode(', ', $allowed),
                ),
                entityClass: $entity::class,
                fromStatus: $current,
                toStatus: '<unknown>',
                allowedTransitions: $allowed,
                previous: $e,
            );
        } catch (TransitionException $e) {
            throw new InvalidTransitionException(
                message: $e->getMessage(),
                entityClass: $entity::class,
                fromStatus: $current,
                toStatus: '<unknown>',
                allowedTransitions: [],
                previous: $e,
            );
        }

        $this->entityManager->flush();
    }
}
