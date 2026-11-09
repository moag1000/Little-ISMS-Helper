<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

/**
 * Votes on attributes of the form `lifecycle.<workflow_name>.<transition_name>`.
 *
 * Subject MUST be the entity (`Document`, `Risk`, ...). Resolver returns
 * the effective `roles` array (YAML + tenant DB-overlay). User wins if
 * holding ANY listed role.
 *
 * A transition with `responsible: true` in its YAML metadata is also open to the person
 * responsible for the record (`RECORD_RESPONSIBLE`, the holder the reminders go to) —
 * "role OR responsible", e.g. the objective owner recording an objective as achieved (S15).
 */
final class LifecycleVoter extends Voter
{
    public const string ATTRIBUTE_PREFIX = 'lifecycle.';

    public function __construct(
        private readonly Security $security,
        private readonly LifecycleConfigResolverInterface $resolver,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, self::ATTRIBUTE_PREFIX) && is_object($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // attribute format: lifecycle.<workflow>.<transition>
        $parts = explode('.', substr($attribute, strlen(self::ATTRIBUTE_PREFIX)), 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$workflowName, $transitionName] = $parts;

        $effective = $this->resolver->resolve($subject, $workflowName, $transitionName);
        $allowedRoles = $effective['roles'] ?? [];

        if (($effective['responsible'] ?? false) === true
            && $this->security->isGranted(RecordResponsibleVoter::RESPONSIBLE, $subject)) {
            return true;
        }

        if ($allowedRoles === []) {
            // Empty roles list = nobody can perform this transition by default.
            return false;
        }

        foreach ($allowedRoles as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }
        return false;
    }
}
