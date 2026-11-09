<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Service\InitialAdminService;
use App\Security\RoleCeiling;
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
 * - ROLE_SUPER_ADMIN bypasses all checks; ROLE_ADMIN bypasses permission
 *   checks only for users of its own tenant
 * - Users can always view and edit themselves
 * - Users cannot delete themselves
 * - Initial setup admin cannot be deleted or deactivated
 * - Permission checks via hasPermission() method
 *
 * Multi-tenancy / scope (record scope):
 * - ROLE_SUPER_ADMIN is global and may act on any user
 * - ROLE_ADMIN administers users of its organisation tree: VIEW/EDIT/DELETE
 *   only users whose tenant is accessible to it (TenantContext::canAccessTenant
 *   — own tenant + descendants, the TenantOwnership tree comparison)
 * - Every other actor follows the record scope of HoldingTreeAccessTrait:
 *   own tenant; ROLE_GROUP_CISO / ROLE_KONZERN_AUDITOR may additionally VIEW
 *   (never edit/delete) users of descendant tenants
 * - Users without a tenant are reachable by ROLE_SUPER_ADMIN only
 * - Role ceiling ({@see RoleCeiling}): EDIT/DELETE of another account only
 *   when every reserved role it holds is within the actor's ceiling — so a
 *   non-SUPER_ADMIN never edits a SUPER_ADMIN or holding-role account
 * - Self VIEW/EDIT is always allowed (self-service); self DELETE stays in the
 *   own-tenant record scope
 * - UserTenantAssignment is an unmapped spec skeleton (no table, no writer);
 *   when the multi-tenant assignment lands
 *   (docs/superpowers/specs/2026-05-26-multi-tenant-user-assignment.md),
 *   extend the scope check here.
 */
final class UserVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public function __construct(
        private readonly InitialAdminService $initialAdminService,
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly TenantContext $tenantContext,
        private readonly RoleCeiling $roleCeiling,
    ) {
    }

    protected function getRoleHierarchy(): RoleHierarchyInterface
    {
        return $this->roleHierarchy;
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

        // Per-user attributes: the target must be in the actor's scope (see
        // decideUserScope()). Subject-less attributes (create, manage roles,
        // view all): ROLE_ADMIN passes — the controller scopes the result set
        // to the admin's organisation tree.
        if ($subject instanceof User) {
            $scope = $this->decideUserScope($user, $subject, $attribute);
            if ($scope !== null) {
                return $scope;
            }
        } elseif ($this->hasReachableRole($user, 'ROLE_ADMIN')) {
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
     * Scope pre-decision for a per-user attribute.
     *
     * @return bool|null true = grant, false = deny, null = in scope, apply the
     *                   permission rules (canView/canEdit/canDelete)
     */
    private function decideUserScope(User $actor, User $target, string $attribute): ?bool
    {
        if ($this->hasReachableRole($actor, 'ROLE_SUPER_ADMIN')) {
            return true;
        }

        if ($actor === $target || ($actor->getId() !== null && $actor->getId() === $target->getId())) {
            // Self-service view/edit; deleting oneself stays in the record scope.
            return $attribute === self::DELETE
                ? $this->decideTenantScope($actor, $target->getTenant(), false)
                : null;
        }

        // Role ceiling (S14): an account holding a role above the actor's own
        // (SUPER_ADMIN, holding roles such as Konzern-ISB/-Auditor, ROLE_ADMIN
        // for a manager with `user.edit`) is only viewed — never edited,
        // deleted or given a new password / e-mail / second factor.
        if ($attribute !== self::VIEW && !$this->roleCeiling->mayAdminister($actor, $target)) {
            return false;
        }

        $targetTenant = $target->getTenant();
        if ($targetTenant === null) {
            return false;
        }

        // User administration: ROLE_ADMIN acts on its own organisation and
        // its subsidiaries (TenantContext::canAccessTenant →
        // TenantOwnership::isSameOrDescendant).
        if ($this->hasReachableRole($actor, 'ROLE_ADMIN')) {
            return $this->tenantContext->canAccessTenant($targetTenant);
        }

        // Everyone else: record scope — own tenant, holding roles read below.
        return $this->isInTenantScope($actor, $targetTenant, $attribute === self::VIEW) ? null : false;
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
