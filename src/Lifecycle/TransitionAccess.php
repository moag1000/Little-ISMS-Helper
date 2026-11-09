<?php

declare(strict_types=1);

namespace App\Lifecycle;

use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\Exception\FourEyesRequiredException;
use App\Lifecycle\Exception\TransitionAccessDeniedException;
use App\Security\Voter\LifecycleVoter;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * S21: who may apply a lifecycle transition is decided here, once, for every
 * path that reaches {@see LifecycleService::transition()} — lifecycle button,
 * domain controllers, REST, MCP, jobs, suggestion apply().
 *
 * The acting person is the four-eyes approver when one is given, otherwise
 * the passed user, otherwise the logged-in user of the request. That person
 * must pass the LifecycleVoter (`lifecycle.<workflow>.<transition>`): one of
 * the effective roles (YAML + organisation override, role hierarchy) or — for
 * transitions with `responsible: true` — the person responsible for the record.
 *
 * Not checked:
 * - `automatic: true` — nobody acts (listeners, cron, auto-progression);
 * - `approverAuthorityVerified: true` — the caller has checked the acting
 *   person against a more specific rule (approval-chain step approver,
 *   risk-acceptance tier, criteria approved by top management);
 * - transitions without roles and without `responsible` — service-only steps
 *   whose owning service decides (document sections, questionnaire answers by
 *   the supplier, notification pipeline, superseding a decision);
 * - no person at all (console command without a user).
 *
 * Two rules hold for every caller, automatic and authority-verified ones
 * included (S27):
 * - a running approval chain (`workflow_instance_lifecycle`) is decided step
 *   by step: every transition but `start` needs `approverAuthorityVerified`
 *   (WorkflowService after `canUserApprove()`) or `automatic`. The generic
 *   lifecycle button, REST and MCP can never approve, reject or cancel a chain
 *   with Leitung or DPO steps by the coarse instance roles;
 * - a four-eyes approver is the logged-in person: a second person named by
 *   somebody else (request body, form field) has not confirmed anything.
 *   Somebody else's confirmation goes through a counter-sign request
 *   ({@see \App\Lifecycle\FourEyes\LifecycleCountersign}). Without a session
 *   (console, worker) the caller's code names the approver.
 *
 * Durable tests: tests/Lifecycle/LifecycleServiceRoleContractTest.php,
 * tests/Lifecycle/GenericEndpointRespectsStepAuthorityTest.php,
 * tests/Lifecycle/EvidenceNamesActorTest.php.
 */
final class TransitionAccess
{
    /** The one approval-chain transition anybody allowed may apply: starting it. */
    public const string CHAIN_START = 'start';

    public function __construct(
        private readonly Security $security,
        private readonly LifecycleConfigResolverInterface $resolver,
    ) {
    }

    /**
     * @throws TransitionAccessDeniedException
     */
    public function assertAllowed(
        object $entity,
        string $workflowName,
        string $transitionName,
        ?User $user,
        ?User $fourEyesApprover,
        bool $authorityVerified,
        bool $automatic,
    ): void {
        if (!$this->isConfirmedApprover($fourEyesApprover)) {
            throw FourEyesRequiredException::unconfirmedApprover($workflowName, $transitionName);
        }
        if ($entity instanceof WorkflowInstance && $transitionName !== self::CHAIN_START
            && !$automatic && !$authorityVerified) {
            throw new TransitionAccessDeniedException($workflowName, $transitionName, ['approval-chain step']);
        }
        if ($automatic || $authorityVerified) {
            return;
        }

        $actor = $fourEyesApprover ?? $user ?? $this->requestUser();
        if (!$actor instanceof User) {
            return;
        }

        $effective = $this->resolver->resolve($entity, $workflowName, $transitionName);
        /** @var list<string> $roles */
        $roles = array_values(array_filter((array) ($effective['roles'] ?? []), 'is_string'));
        if ($roles === [] && ($effective['responsible'] ?? false) !== true) {
            return;
        }

        $attribute = LifecycleVoter::ATTRIBUTE_PREFIX . $workflowName . '.' . $transitionName;
        if ($this->security->isGrantedForUser($actor, $attribute, $entity)) {
            return;
        }

        throw new TransitionAccessDeniedException($workflowName, $transitionName, $roles);
    }

    /**
     * True when no approver is named, or the named approver is the person of
     * the current session (or there is no session: console, worker).
     */
    public function isConfirmedApprover(?User $fourEyesApprover): bool
    {
        if (!$fourEyesApprover instanceof User) {
            return true;
        }
        $sessionUser = $this->requestUser();

        return !$sessionUser instanceof User
            || ($sessionUser->getId() !== null && $sessionUser->getId() === $fourEyesApprover->getId())
            || $sessionUser === $fourEyesApprover;
    }

    /** True only when the named approver is the person of the current session (S27 evidence). */
    public function isSessionApprover(?User $fourEyesApprover): bool
    {
        $sessionUser = $this->requestUser();

        return $fourEyesApprover instanceof User && $sessionUser instanceof User
            && ($sessionUser === $fourEyesApprover
                || ($sessionUser->getId() !== null && $sessionUser->getId() === $fourEyesApprover->getId()));
    }

    private function requestUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
