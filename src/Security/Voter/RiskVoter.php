<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Risk;
use App\Entity\User;
use App\Service\Responsibility\ResponsibilityResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 * - EDIT below ROLE_ADMIN: ROLE_MANAGER, the `risk.edit` permission or the
 *   risk owner (ResponsibilityResolver)
 * - Only admins can delete risk assessments
 *
 * Multi-tenancy:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Tenant validation on all operations
 */
final class RiskVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        // Lazy: the resolver collects the deadline sources (W1 "act where reminded").
        #[Autowire(lazy: true)]
        private readonly ?ResponsibilityResolver $responsibility = null,
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

        // In scope below ROLE_ADMIN: everyone may view; editing needs the
        // manager scope, the risk.edit permission or being the one who has to
        // act on the risk (risk owner via ResponsibilityResolver); delete stays
        // admin-only. Before W1 every ROLE_USER could edit every risk.
        return match ($attribute) {
            self::VIEW => true,
            self::EDIT => $this->hasReachableRole($user, 'ROLE_MANAGER') || $user->hasPermission('risk.edit')
                || ($this->responsibility?->isResponsibleFor($user, $risk) ?? false),
            default => false,
        };
    }
}
