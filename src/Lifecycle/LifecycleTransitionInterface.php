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
     * @param  EligibleApprovers|null $eligibleApprovers Who else could grant this
     *                                     four-eyes transition (WorkflowService: users
     *                                     eligible for the authorising step). The
     *                                     single-approver escape then applies only when
     *                                     no OTHER active user of the organisation is
     *                                     eligible (one count query); without it the
     *                                     transition `roles` are counted.
     * @param  bool      $allowSelfApprovalEscape False when the caller passes a
     *                                     system-generated reason: the single-approver
     *                                     escape needs a reason the user typed (the
     *                                     offline counter-signature), so it is refused.
     * @param  bool      $automatic True when no human triggers this transition
     *                                     (cron, Doctrine listener, auto-progression).
     *                                     A four-eyes transition is then always refused
     *                                     with reason code `automatic_actor` — automatic
     *                                     progression never satisfies the two-person
     *                                     rule, the single-approver escape included.
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
        ?EligibleApprovers $eligibleApprovers = null,
        bool $allowSelfApprovalEscape = true,
        bool $automatic = false,
    ): void;
}
