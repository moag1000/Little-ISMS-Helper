<?php

declare(strict_types=1);

namespace App\Lifecycle;

use App\Entity\User;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
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
 * Durable test: tests/Lifecycle/LifecycleServiceRoleContractTest.php.
 */
final class TransitionAccess
{
    public function __construct(
        private readonly Security $security,
        private readonly LifecycleConfigResolverInterface $resolver,
    ) {}

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

    private function requestUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
