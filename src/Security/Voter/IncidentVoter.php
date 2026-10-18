<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Incident;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Incident Voter
 *
 * Implements fine-grained authorization for security Incident entity operations.
 * Enforces strict multi-tenancy isolation for incident response and forensics.
 *
 * Supported Operations:
 * - VIEW: View security incident details and investigation data
 * - EDIT: Modify incident status, severity, and response actions
 * - DELETE: Remove incident records (admin only)
 *
 * Security Rules:
 * - ROLE_SUPER_ADMIN bypasses all checks; ROLE_ADMIN only within its own tenant
 * - Users can only access incidents from their own tenant
 * - Tenant isolation strictly enforced
 * - Only admins can delete incident records
 *
 * Multi-tenancy:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Tenant validation on all operations
 * - Prevents cross-tenant incident data leakage
 * - Future: Can be extended with reporter/assigned user checks
 *
 * Incident Response:
 * - Supports ISO 27001 A.16: Information security incident management
 * - Enables proper access control for incident lifecycle tracking
 */
final class IncidentVoter extends Voter
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
            && $subject instanceof Incident;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Security: User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        /** @var Incident $incident */
        $incident = $subject;

        // Tenant scope first: SUPER_ADMIN everywhere, ROLE_ADMIN only inside
        // its own tenant. Holding roles read down the tree only when the
        // subsidiary has not opted out of cross-posting (9.P2.3
        // confidential-incident carve-out, e.g. HR cases that must not
        // reach the parent company's crisis team).
        $holdingRead = $attribute === self::VIEW && $incident->isVisibleToHolding();
        $scope = $this->decideTenantScope($user, $incident->getTenant(), $holdingRead);
        if ($scope !== null) {
            return $scope;
        }

        // In scope below ROLE_ADMIN: view and edit, delete stays admin-only.
        return $attribute !== self::DELETE;
    }
}
