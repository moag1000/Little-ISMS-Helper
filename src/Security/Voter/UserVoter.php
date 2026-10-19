<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Service\InitialAdminService;
use App\Service\TenantContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * User Voter
 *
 * Implements fine-grained authorization for User entity operations.
 * Enforces permission-based access control with self-service capabilities.
 *
 * Supported Permissions:
 * - user.view: View other users
 * - user.view_all: View all users in the system
 * - user.create: Create new users
 * - user.edit: Edit other users
 * - user.delete: Delete users
 * - user.manage_roles: Assign/revoke roles
 * - user.manage_permissions: Manage user permissions
 *
 * Security Rules:
 * - Inactive users cannot perform any actions
 * - ROLE_ADMIN bypasses all permission checks (except initial admin protection)
 * - Users can always view and edit themselves
 * - Users cannot delete themselves
 * - Initial setup admin cannot be deleted or deactivated
 * - Permission checks via hasPermission() method
 *
 * Multi-tenancy / scope (security hotfix, admin scope):
 * - ROLE_SUPER_ADMIN is global and may act on any user
 * - Every other actor (incl. ROLE_ADMIN) may VIEW/EDIT/DELETE only users whose
 *   tenant is accessible to it (TenantContext::canAccessTenant — own tenant +
 *   descendants); users without a tenant are visible to SUPER_ADMIN only
 * - A non-SUPER_ADMIN actor can never EDIT/DELETE a ROLE_SUPER_ADMIN account
 * - Self VIEW/EDIT is always allowed (self-service)
 */
final class UserVoter extends Voter
{
    public function __construct(
        private readonly InitialAdminService $initialAdminService,
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly TenantContext $tenantContext,
    ) {
    }
    public const string VIEW = 'user.view';
    public const string CREATE = 'user.create';
    public const string EDIT = 'user.edit';
    public const string DELETE = 'user.delete';
    public const string MANAGE_ROLES = 'user.manage_roles';
    public const string MANAGE_PERMISSIONS = 'user.manage_permissions';
    public const string VIEW_ALL = 'user.view_all';

    protected function supports(string $attribute, mixed $subject): bool
    {
        $supportedAttributes = [
            self::VIEW,
            self::CREATE,
            self::EDIT,
            self::DELETE,
            self::MANAGE_ROLES,
            self::MANAGE_PERMISSIONS,
            self::VIEW_ALL,
        ];

        if (!in_array($attribute, $supportedAttributes)) {
            return false;
        }

        // For VIEW, EDIT, DELETE we need a User object
        if (in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])) {
            return $subject instanceof User;
        }

        // For CREATE, MANAGE_ROLES, MANAGE_PERMISSIONS, VIEW_ALL we don't need a subject
        return true;
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

        // Tenant / rank scope for every non-SUPER_ADMIN actor — applies
        // before the ROLE_ADMIN bypass so tenant admins stay in their tree.
        if (!in_array('ROLE_SUPER_ADMIN', $reachableRoles, true)
            && $subject instanceof User
            && in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && !$this->isWithinActorScope($user, $subject, $attribute)
        ) {
            return false;
        }

        // Admins can do everything (within the scope checked above)
        if (in_array('ROLE_ADMIN', $reachableRoles, true)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($user, $subject),
            self::CREATE => $user->hasPermission('user.create'),
            self::EDIT => $this->canEdit($user, $subject),
            self::DELETE => $this->canDelete($user, $subject),
            self::MANAGE_ROLES => $user->hasPermission('user.manage_roles'),
            self::MANAGE_PERMISSIONS => $user->hasPermission('user.manage_permissions'),
            self::VIEW_ALL => $user->hasPermission('user.view_all'),
            default => false,
        };
    }

    /**
     * Scope rule for non-SUPER_ADMIN actors: self is always in scope; a
     * SUPER_ADMIN target may only be viewed; otherwise the target's tenant
     * must be accessible to the actor (own + descendants). Tenantless targets
     * are out of scope.
     */
    private function isWithinActorScope(User $actor, User $target, string $attribute): bool
    {
        if ($actor === $target || ($actor->getId() !== null && $actor->getId() === $target->getId())) {
            return true;
        }

        if ($attribute !== self::VIEW && in_array('ROLE_SUPER_ADMIN', $target->getRoles(), true)) {
            return false;
        }

        $targetTenant = $target->getTenant();
        if ($targetTenant === null) {
            return false;
        }

        return $this->tenantContext->canAccessTenant($targetTenant);
    }

    private function canView(User $currentUser, User $targetUser): bool
    {
        // Users can always view themselves
        if ($currentUser->getId() === $targetUser->getId()) {
            return true;
        }
        // Check if user has view permission
        if ($currentUser->hasPermission('user.view')) {
            return true;
        }
        return $currentUser->hasPermission('user.view_all');
    }

    private function canEdit(User $currentUser, User $targetUser): bool
    {
        // Users can edit themselves (limited fields)
        if ($currentUser->getId() === $targetUser->getId()) {
            return true;
        }

        // Check if user has edit permission
        return $currentUser->hasPermission('user.edit');
    }

    private function canDelete(User $currentUser, User $targetUser): bool
    {
        // Users cannot delete themselves
        if ($currentUser->getId() === $targetUser->getId()) {
            return false;
        }

        // Cannot delete the initial setup admin
        if ($this->initialAdminService->isInitialAdmin($targetUser)) {
            return false;
        }

        // Check if user has delete permission
        return $currentUser->hasPermission('user.delete');
    }
}
