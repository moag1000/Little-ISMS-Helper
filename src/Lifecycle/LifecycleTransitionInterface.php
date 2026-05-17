<?php

declare(strict_types=1);

namespace App\Lifecycle;

use App\Entity\User;

/**
 * Contract for the lifecycle-transition facade.
 *
 * Extracted from {@see LifecycleService} to enable test-double injection
 * in listeners (FieldCompletionAutoTransition, WorkflowAutoProgressionBridge)
 * without requiring the `final` service to be un-finalized.
 */
interface LifecycleTransitionInterface
{
    /**
     * @throws InvalidTransitionException
     */
    public function transition(
        object $entity,
        string $workflowName,
        string $transitionName,
        ?User $user = null,
        ?string $reason = null,
    ): void;
}
