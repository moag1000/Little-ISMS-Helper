<?php

namespace App\Security\Voter;

use App\Entity\Role;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for Role management permissions
 */
class RoleVoter extends Voter
{
    public const VIEW = 'role.view';
    public const CREATE = 'role.create';
    public const EDIT = 'role.edit';
    public const DELETE = 'role.delete';

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
            self::VIEW => $user->hasPermission('role.view'),
            self::CREATE => $user->hasPermission('role.create'),
            self::EDIT => $this->canEdit($user, $subject),
            self::DELETE => $this->canDelete($user, $subject),
            default => false,
        };
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
