<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Control;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Control Voter
 *
 * Implements fine-grained authorization for ISO 27001 Control entity operations.
 * Enforces strict multi-tenancy isolation for control implementations.
 *
 * Supported Operations:
 * - VIEW: View ISO 27001 control details and implementation status
 * - EDIT: Modify control implementation and effectiveness
 * - DELETE: Remove controls (admin only)
 *
 * Security Rules:
 * - ROLE_SUPER_ADMIN bypasses all checks; ROLE_ADMIN only within its own tenant
 * - Users can only access controls from their own tenant
 * - Tenant isolation strictly enforced
 * - EDIT below ROLE_ADMIN needs ROLE_MANAGER (role hierarchy) or the
 *   fine-grained `control.edit` permission — ROLE_USER / ROLE_AUDITOR and
 *   the holding roles are read-only
 * - Only admins can delete controls
 *
 * Multi-tenancy:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Tenant validation on all operations
 * - Future: Can be extended with control owner/responsible party checks
 */
final class ControlVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function getRoleHierarchy(): RoleHierarchyInterface
    {
        return $this->roleHierarchy;
    }

    public const string VIEW = 'view';
    public const string EDIT = 'edit';
    public const string DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Control;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Security: User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        /** @var Control $control */
        $control = $subject;

        // Tenant scope first: SUPER_ADMIN everywhere, ROLE_ADMIN only inside
        // its own tenant, holding roles read-only down the tree.
        $scope = $this->decideTenantScope($user, $control->getTenant(), $attribute === self::VIEW);
        if ($scope !== null) {
            return $scope;
        }

        // In scope below ROLE_ADMIN: everyone may view; editing needs the
        // manager scope (or the control.edit permission); delete stays admin-only.
        return match ($attribute) {
            self::VIEW => true,
            self::EDIT => $this->hasReachableRole($user, 'ROLE_MANAGER') || $user->hasPermission('control.edit'),
            default => false,
        };
    }
}
