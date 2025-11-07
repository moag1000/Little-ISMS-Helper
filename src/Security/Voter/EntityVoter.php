<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Entity Voter
 *
 * Generic fallback voter providing dynamic permission checking for any entity.
 * Implements convention-based authorization using entity name and action patterns.
 *
 * Supported Operations:
 * - VIEW: View entity details
 * - CREATE: Create new entities
 * - EDIT: Modify existing entities
 * - DELETE: Remove entities
 * - APPROVE: Approve entity workflows
 * - EXPORT: Export entity data
 *
 * Security Rules:
 * - ROLE_ADMIN bypasses all checks
 * - Inactive users cannot perform any actions
 * - Dynamic permission resolution: {entity_name}.{action}
 * - Works with any entity object through reflection
 *
 * Permission Resolution:
 * Example: Checking 'edit' on a Training entity resolves to 'training.edit' permission
 * This provides flexible authorization without creating entity-specific voters
 *
 * Best Practice:
 * - Use specific voters (AssetVoter, RiskVoter, etc.) for complex entity-specific logic
 * - Use this voter as a fallback for simple entities without special authorization rules
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
