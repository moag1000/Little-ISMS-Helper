<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for User-specific permissions
 */
class UserVoter extends Voter
{
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

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
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
        return $currentUser->hasPermission('user.view') || $currentUser->hasPermission('user.view_all');
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

        // Check if user has delete permission
        return $currentUser->hasPermission('user.delete');
    }
}
