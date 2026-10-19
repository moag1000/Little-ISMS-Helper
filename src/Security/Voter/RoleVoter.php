<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Role;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Role Voter
 *
 * Authorization for the Role entity (role definitions + their permission
 * sets, managed under /admin/roles).
 *
 * Role (and Permission) rows are instance-global — they carry no tenant and
 * are shared by every organisation. Changing one changes what users of ALL
 * organisations may do, so writing is an instance-operator task:
 *
 * - VIEW: ROLE_ADMIN (and above) or the `role.view` permission
 * - CREATE / EDIT / DELETE: ROLE_SUPER_ADMIN only. Neither an organisation
 *   admin nor a user holding the `role.create|edit|delete` permission may
 *   modify a global role — that would be a cross-organisation privilege
 *   escalation (OWASP A01). This subsumes the 3.15.3 hotfix rule (system
 *   roles SUPER_ADMIN-only; custom roles only while every holder is in the
 *   actor's scope): no holder set is ever in scope for a non-SUPER_ADMIN.
 * - System roles can never be deleted.
 * - Inactive users are denied everything.
 * - A denied EDIT/DELETE that is due to the role being global carries
 *   {@see REASON_OUT_OF_SCOPE}, so the UI can tell it apart from a plain
 *   missing permission.
 */
final class RoleVoter extends Voter
{
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * Vote reason set when EDIT/DELETE is denied because the role is global
     * (only ROLE_SUPER_ADMIN changes roles; system roles are never deleted) —
     * lets the UI tell this apart from a plain missing permission.
     */
    public const string REASON_OUT_OF_SCOPE = 'role.out_of_scope';

    public const string VIEW = 'role.view';
    public const string CREATE = 'role.create';
    public const string EDIT = 'role.edit';
    public const string DELETE = 'role.delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::CREATE, self::EDIT, self::DELETE], true)) {
            return false;
        }

        // CREATE needs no subject; VIEW is a pure permission check, so the role
        // list / compare pages vote without one (#[IsGranted(RoleVoter::VIEW)]);
        // the other actions need a Role.
        return $attribute === self::CREATE
            || ($attribute === self::VIEW && $subject === null)
            || $subject instanceof Role;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());
        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $reachableRoles, true);

        if ($subject instanceof Role && in_array($attribute, [self::EDIT, self::DELETE], true)) {
            $deniedAsGlobal = $isSuperAdmin
                ? $attribute === self::DELETE && $subject->isSystemRole()
                : $subject->isSystemRole()
                    || in_array('ROLE_ADMIN', $reachableRoles, true)
                    || $user->hasPermission($attribute);
            if ($deniedAsGlobal) {
                $vote?->addReason(self::REASON_OUT_OF_SCOPE);

                return false;
            }
        }

        return match ($attribute) {
            self::VIEW => in_array('ROLE_ADMIN', $reachableRoles, true) || $user->hasPermission('role.view'),
            self::CREATE, self::EDIT => $isSuperAdmin,
            self::DELETE => $isSuperAdmin && $subject instanceof Role && !$subject->isSystemRole(),
            default => false,
        };
    }
}
