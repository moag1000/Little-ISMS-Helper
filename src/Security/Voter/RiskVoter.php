<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Risk;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Risk Voter
 *
 * Implements fine-grained authorization for Risk entity operations.
 * Enforces strict multi-tenancy isolation for risk assessments.
 *
 * Supported Operations:
 * - VIEW: View risk assessment details
 * - EDIT: Modify risk assessments and treatment plans
 * - DELETE: Remove risk assessments (admin only)
 *
 * Security Rules:
 * - ROLE_SUPER_ADMIN bypasses all checks; ROLE_ADMIN only within its own tenant
 * - Users can only access risks from their own tenant
 * - Tenant isolation strictly enforced
 * - Only admins can delete risk assessments
 *
 * Multi-tenancy:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Tenant validation on all operations
 * - Future: Can be extended with risk owner checks
 */
final class RiskVoter extends Voter
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
            && $subject instanceof Risk;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Security: User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        /** @var Risk $risk */
        $risk = $subject;

        // Tenant scope first: SUPER_ADMIN everywhere, ROLE_ADMIN only inside
        // its own tenant, holding roles read-only down the tree.
        $scope = $this->decideTenantScope($user, $risk->getTenant(), $attribute === self::VIEW);
        if ($scope !== null) {
            return $scope;
        }

        // In scope below ROLE_ADMIN: view and edit, delete stays admin-only.
        return $attribute !== self::DELETE;
    }
}
