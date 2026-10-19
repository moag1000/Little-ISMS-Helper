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
     * @param  User|null $fourEyesApprover Required when the transition declares
     *                                     `four_eyes: true` in YAML metadata.
     *                                     Enforced by FourEyesValidator subscriber.
     * @param  bool      $approverAuthorityVerified True when the caller has already
     *                                     verified $fourEyesApprover's authority
     *                                     against a more specific rule than the
     *                                     transition's `roles` (WorkflowService:
     *                                     the step's approver_role / approver_users).
     *                                     FourEyesValidator then skips its role
     *                                     check; the distinct-user rule still applies.
     *
     * @throws InvalidTransitionException
     * @throws \App\Lifecycle\Exception\ReasonRequiredException
     * @throws \App\Lifecycle\Exception\FourEyesRequiredException
     */
    public function transition(
        object $entity,
        string $workflowName,
        string $transitionName,
        ?User $user = null,
        ?string $reason = null,
        ?User $fourEyesApprover = null,
        bool $approverAuthorityVerified = false,
    ): void;
}
