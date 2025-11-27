<?php

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Incident;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

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
 * - ROLE_ADMIN bypasses all checks
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
class IncidentVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

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

        // Security: Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($incident, $user),
            self::EDIT => $this->canEdit($incident, $user),
            self::DELETE => $this->canDelete($user),
            default => false,
        };
    }

    private function canView(Incident $incident, User $user): bool
    {
        // Security: Multi-tenancy - users can view incidents from their tenant
        return $incident->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function canEdit(Incident $incident, User $user): bool
    {
        // Security: Users can edit incidents from their tenant
        // Could be extended with reporter/assigned user checks
        return $incident->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function canDelete(User $user): bool
    {
        // Security: Only admins can delete
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
