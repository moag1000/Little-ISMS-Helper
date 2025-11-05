<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Generic voter for entity-based permissions
 */
class EntityVoter extends Voter
{
    // Entity actions
    public const VIEW = 'view';
    public const CREATE = 'create';
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    public const APPROVE = 'approve';
    public const EXPORT = 'export';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // This voter supports these actions
        $supportedActions = [
            self::VIEW,
            self::CREATE,
            self::EDIT,
            self::DELETE,
            self::APPROVE,
            self::EXPORT,
        ];

        if (!in_array($attribute, $supportedActions)) {
            return false;
        }

        // We support all entity objects
        return is_object($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // User must be logged in
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

        // Get entity name
        $entityClass = get_class($subject);
        $entityName = strtolower((new \ReflectionClass($entityClass))->getShortName());

        // Check permission based on entity and action
        $permissionName = $entityName . '.' . $attribute;

        return $user->hasPermission($permissionName);
    }
}
