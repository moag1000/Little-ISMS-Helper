<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\RoleRepository;
use App\Service\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Role Voter
 *
 * Implements fine-grained authorization for Role entity operations.
 * Protects system roles from unauthorized modification or deletion.
 *
 * Supported Operations:
 * - VIEW: View role definitions and permissions
 * - CREATE: Create new custom roles
 * - EDIT: Modify role permissions and attributes
 * - DELETE: Remove custom roles
 *
 * Security Rules:
 * - ROLE_ADMIN bypasses all checks
 * - Inactive users cannot perform any actions
 * - Permission-based access control (role.view, role.create, role.edit, role.delete)
 * - System roles (ROLE_USER, ROLE_ADMIN) cannot be edited or deleted —
 *   except by ROLE_SUPER_ADMIN (the ROLE_ADMIN bypass does not cover them)
 * - Custom roles are global (no tenant): a non-SUPER_ADMIN actor may EDIT or
 *   DELETE one only while every holder is a user it may edit per UserVoter
 *   (tenant inside the actor's tree, not a SUPER_ADMIN account); otherwise a
 *   change would alter what users of foreign tenants may do. CREATE and
 *   assignment are unaffected (security hotfix, admin scope)
 *
 * System Role Protection:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Prevents privilege escalation by protecting core system roles
 * - Ensures role integrity for security-critical operations
 */
final class RoleVoter extends Voter
{
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly RoleRepository $roleRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public const string VIEW = 'role.view';
    public const string CREATE = 'role.create';
    public const string EDIT = 'role.edit';
    public const string DELETE = 'role.delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        $supportedAttributes = [
            self::VIEW,
            self::CREATE,
            self::EDIT,
            self::DELETE,
        ];

        if (!in_array($attribute, $supportedAttributes)) {
            return false;
        }

        // For CREATE we don't need a subject
        if ($attribute === self::CREATE) {
            return true;
        }

        // For other actions we need a Role object
        return $subject instanceof Role;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Inactive users cannot do anything
        if (!$user->isActive()) {
            return false;
        }

        $reachableRoles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        // Security hotfix: system roles are global (shared by all tenants) and
        // their names map to Symfony roles — only ROLE_SUPER_ADMIN may change them.
        if ($subject instanceof Role
            && $subject->isSystemRole()
            && in_array($attribute, [self::EDIT, self::DELETE], true)
            && !in_array('ROLE_SUPER_ADMIN', $reachableRoles, true)
        ) {
            return false;
        }

        // Security hotfix: custom roles are global — editing/deleting one
        // changes every holder, so all holders must be within the actor's scope.
        if ($subject instanceof Role
            && in_array($attribute, [self::EDIT, self::DELETE], true)
            && !in_array('ROLE_SUPER_ADMIN', $reachableRoles, true)
            && $this->hasHoldersOutsideActorScope($user, $subject)
        ) {
            return false;
        }

        // Admins can do everything else
        if (in_array('ROLE_ADMIN', $reachableRoles, true)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $user->hasPermission('role.view'),
            self::CREATE => $user->hasPermission('role.create'),
            self::EDIT => $this->canEdit($user, $subject),
            self::DELETE => $this->canDelete($user, $subject),
            default => false,
        };
    }

    /**
     * Same scope as UserVoter::EDIT for non-SUPER_ADMIN actors (self always,
     * otherwise tenant accessible via TenantContext and not a SUPER_ADMIN),
     * evaluated as one COUNT query instead of hydrating every holder.
     */
    private function hasHoldersOutsideActorScope(User $actor, Role $role): bool
    {
        if ($role->getId() === null) {
            return false; // not persisted yet => no holders
        }

        $tenantIds = [];
        foreach ($this->tenantContext->getAccessibleTenants() as $tenant) {
            if ($tenant->getId() !== null) {
                $tenantIds[] = $tenant->getId();
            }
        }

        return $this->roleRepository->countHoldersOutsideTenants($role, $tenantIds, $actor->getId()) > 0;
    }

    private function canEdit(User $user, Role $role): bool
    {
        // System roles cannot be edited
        if ($role->isSystemRole()) {
            return false;
        }

        return $user->hasPermission('role.edit');
    }

    private function canDelete(User $user, Role $role): bool
    {
        // System roles cannot be deleted
        if ($role->isSystemRole()) {
            return false;
        }

        return $user->hasPermission('role.delete');
    }
}
