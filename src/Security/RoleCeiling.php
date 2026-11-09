<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Role ceiling (S14): nobody grants, revokes or administers a role above
 * their own.
 *
 * A reserved role ({@see RoleNames::RESERVED}) may be changed by an actor when
 *  - it is privileged ({@see RoleNames::PRIVILEGED}): only ROLE_SUPER_ADMIN;
 *  - the actor reaches it through security.yaml `role_hierarchy`; or
 *  - it is an admin-delegated role ({@see RoleNames::ADMIN_DELEGATED}, top
 *    management, function owner) and the actor reaches ROLE_ADMIN.
 * Names that are not reserved (labels of custom Role entities) carry
 * permissions, not Symfony roles, and are not limited here.
 *
 * Accounts follow the same line: an actor administers (edits, deletes,
 * resets the second factor of) another account only when every reserved
 * role that account holds is within the actor's ceiling. So an organisation
 * admin can no longer take over a Konzern-ISB account, and a manager holding
 * `user.edit` cannot change an admin.
 */
final class RoleCeiling
{
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * @return list<string> the actor's roles, hierarchy applied
     */
    public function reachable(User $actor): array
    {
        return array_values(array_unique($this->roleHierarchy->getReachableRoleNames($actor->getRoles())));
    }

    /**
     * May $actor grant or revoke $role (on any account)? Self-assignment is
     * checked separately by {@see isSelfGrantForbidden()}.
     */
    public function mayChange(User $actor, ?string $role): bool
    {
        $role = RoleNames::normalise($role);
        if ($role === '' || !RoleNames::isReserved($role)) {
            return true;
        }
        $reachable = $this->reachable($actor);
        if (RoleNames::isPrivileged($role)) {
            return in_array('ROLE_SUPER_ADMIN', $reachable, true);
        }
        if (in_array($role, $reachable, true)) {
            return true;
        }

        return in_array($role, RoleNames::ADMIN_DELEGATED, true) && in_array('ROLE_ADMIN', $reachable, true);
    }

    /**
     * True for a role nobody may give themselves (top management: the admin
     * must not sign Leitung approvals of their own accord).
     */
    public static function isSelfGrantForbidden(?string $role): bool
    {
        return in_array(RoleNames::normalise($role), RoleNames::NEVER_SELF_GRANTED, true);
    }

    /**
     * May $actor administer $target (edit, delete, reset MFA)? Every reserved
     * role the target holds (User::getRoles(): stored roles plus those of
     * system custom roles) must be within the actor's ceiling.
     */
    public function mayAdminister(User $actor, User $target): bool
    {
        foreach ($target->getRoles() as $role) {
            if (!$this->mayChange($actor, $role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The roles of $candidates the actor may grant to $target (null = a new
     * account). Keeps the order of $candidates.
     *
     * @param array<array-key, string> $candidates
     * @return array<array-key, string>
     */
    public function grantable(User $actor, array $candidates, ?User $target = null): array
    {
        $self = $target !== null && self::isSameUser($actor, $target);

        return array_filter(
            $candidates,
            fn (string $role): bool => $this->mayChange($actor, $role)
                && !($self && self::isSelfGrantForbidden($role) && !in_array($role, $target->getRoles(), true)),
        );
    }

    public static function isSameUser(User $a, User $b): bool
    {
        return $a === $b || ($a->getId() !== null && $a->getId() === $b->getId());
    }
}
