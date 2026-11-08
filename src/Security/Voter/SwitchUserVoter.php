<?php

declare(strict_types=1);

namespace App\Security\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Who may impersonate whom (`?_switch_user=`, security.yaml `switch_user.role`).
 *
 * Symfony's default only asks whether the caller holds ROLE_ALLOWED_TO_SWITCH and lets
 * them become anyone. Anybody who came by that role (a crafted organisation restore did,
 * until RestoreTenantGuard stripped it) could become the super admin. Here the target
 * is never a super admin, and a caller other than the super admin may only become a user
 * who reaches no role the caller does not reach.
 */
final class SwitchUserVoter extends Voter
{
    public const string SWITCH = 'CAN_SWITCH_USER';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::SWITCH && $subject instanceof UserInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $caller = $token->getUser();
        if (!$caller instanceof UserInterface || !$subject instanceof UserInterface) {
            return false;
        }
        if ($caller->getUserIdentifier() === $subject->getUserIdentifier()) {
            return false;
        }

        $callerRoles = $this->roleHierarchy->getReachableRoleNames($token->getRoleNames());
        if (!in_array('ROLE_ALLOWED_TO_SWITCH', $callerRoles, true)) {
            return false;
        }

        $targetRoles = $this->roleHierarchy->getReachableRoleNames($subject->getRoles());
        if (in_array('ROLE_SUPER_ADMIN', $targetRoles, true)) {
            return false;
        }

        // The instance operator supports everyone else (Leitung included, which ROLE_ADMIN
        // does not inherit); anyone else who holds the switch role only goes downwards.
        if (in_array('ROLE_SUPER_ADMIN', $callerRoles, true)) {
            return true;
        }

        return array_diff($targetRoles, $callerRoles) === [];
    }
}
