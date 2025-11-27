<?php

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Risk;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

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
 * - ROLE_ADMIN bypasses all checks
 * - Users can only access risks from their own tenant
 * - Tenant isolation strictly enforced
 * - Only admins can delete risk assessments
 *
 * Multi-tenancy:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Tenant validation on all operations
 * - Future: Can be extended with risk owner checks
 */
class RiskVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

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

        // Security: Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($risk, $user),
            self::EDIT => $this->canEdit($risk, $user),
            self::DELETE => $this->canDelete($user),
            default => false,
        };
    }

    private function canView(Risk $risk, User $user): bool
    {
        // Security: Multi-tenancy - users can view risks from their tenant
        return $risk->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function canEdit(Risk $risk, User $user): bool
    {
        // Security: Users can edit risks from their tenant
        // Could be extended with risk owner checks
        return $risk->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function canDelete(User $user): bool
    {
        // Security: Only admins can delete
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
