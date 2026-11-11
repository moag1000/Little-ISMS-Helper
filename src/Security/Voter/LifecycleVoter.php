<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Security\Authority\ExplicitRole;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
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
 *
 * A transition role ROLE_AUDITOR means audit work (S27): it is decided as
 * {@see AuditWorkVoter::AUDIT_WORK} on the record — auditors, managers and the
 * audit team, not top management or the group roles that inherit ROLE_AUDITOR
 * only for reading.
 *
 * Roles and responsibility are decided on the voted token, never on the
 * logged-in one, so `Security::isGrantedForUser()` answers for the person the
 * LifecycleService checks (S21, {@see \App\Lifecycle\TransitionAccess}).
 */
final class LifecycleVoter extends Voter
{
    public const string ATTRIBUTE_PREFIX = 'lifecycle.';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly LifecycleConfigResolverInterface $resolver,
        private readonly ?ExplicitRole $explicitRole = null,
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

        // S28 (F-10): a step of the DPO or of top management is decided by people who hold the
        // named roles themselves — the roles ROLE_ADMIN inherits (CISO, …) do not open it, and
        // neither does being responsible only as admin of last resort.
        $assignedOnly = $this->explicitRole !== null && ExplicitRole::isReservedStep($allowedRoles);

        if (($effective['responsible'] ?? false) === true
            && $this->accessDecisionManager->decide(
                $token,
                [$assignedOnly ? RecordResponsibleVoter::RESPONSIBLE_BY_RECORD : RecordResponsibleVoter::RESPONSIBLE],
                $subject,
            )) {
            return true;
        }

        if ($allowedRoles === []) {
            // Empty roles list = nobody can perform this transition by default.
            return false;
        }

        $readOnly = null;
        foreach ($allowedRoles as $role) {
            if ($assignedOnly && $this->explicitRole->holdsAssigned($token->getRoleNames(), $role) !== true) {
                continue;
            }
            // S28: a read-only reviewer (auditor, top management, group roles without
            // ROLE_MANAGER) reaches ROLE_USER only for reading — a "everybody" step is not theirs.
            if ($role === 'ROLE_USER' && ($readOnly ??= $this->isReadOnlyReviewer($token))) {
                continue;
            }
            if ($role === 'ROLE_AUDITOR') {
                if ($this->accessDecisionManager->decide($token, [AuditWorkVoter::AUDIT_WORK], $subject)) {
                    return true;
                }
                continue;
            }
            if ($this->accessDecisionManager->decide($token, [$role])) {
                return true;
            }
        }
        return false;
    }

    private function isReadOnlyReviewer(TokenInterface $token): bool
    {
        return $this->accessDecisionManager->decide($token, ['ROLE_AUDITOR'])
            && !$this->accessDecisionManager->decide($token, ['ROLE_MANAGER']);
    }
}
