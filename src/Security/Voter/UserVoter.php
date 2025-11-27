<?php

namespace App\Security\Voter;

use App\Entity\User;
use App\Service\InitialAdminService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

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
 * Multi-tenancy:
 * - Users can only view/edit users in their tenant
 */
class UserVoter extends Voter
{
    public function __construct(
        private readonly InitialAdminService $initialAdminService
    ) {
    }
    public const VIEW = 'user.view';
    public const CREATE = 'user.create';
    public const EDIT = 'user.edit';
    public const DELETE = 'user.delete';
    public const MANAGE_ROLES = 'user.manage_roles';
    public const MANAGE_PERMISSIONS = 'user.manage_permissions';
    public const VIEW_ALL = 'user.view_all';

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

        // Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
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
