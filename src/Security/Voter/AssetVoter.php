<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Asset;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Asset Voter
 *
 * Implements fine-grained authorization for Asset entity operations.
 * Enforces strict multi-tenancy isolation to prevent cross-tenant data access.
 *
 * Supported Operations:
 * - VIEW: View asset details
 * - EDIT: Modify asset information
 * - DELETE: Remove asset (admin only)
 *
 * Security Rules:
 * - ROLE_ADMIN bypasses all checks (can access all tenants)
 * - Users can only access assets from their own tenant
 * - Tenant isolation is strictly enforced (tenant !== null required)
 * - Only admins can delete assets
 *
 * Multi-tenancy:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Tenant validation on all operations
 * - Prevents horizontal privilege escalation
 */
final class AssetVoter extends Voter
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
            && $subject instanceof Asset;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Security: User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        /** @var Asset $asset */
        $asset = $subject;

        // Tenant scope first: SUPER_ADMIN everywhere, ROLE_ADMIN only inside
        // its own tenant, holding roles read-only down the tree.
        $scope = $this->decideTenantScope($user, $asset->getTenant(), $attribute === self::VIEW);
        if ($scope !== null) {
            return $scope;
        }

        // In scope below ROLE_ADMIN: view and edit, delete stays admin-only.
        return $attribute !== self::DELETE;
    }
}
