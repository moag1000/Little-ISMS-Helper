<?php

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Control;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

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
 * - ROLE_ADMIN bypasses all checks
 * - Users can only access controls from their own tenant
 * - Tenant isolation strictly enforced
 * - Only admins can delete controls
 *
 * Multi-tenancy:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Tenant validation on all operations
 * - Future: Can be extended with control owner/responsible party checks
 */
class ControlVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

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

        // Security: Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($control, $user),
            self::EDIT => $this->canEdit($control, $user),
            self::DELETE => $this->canDelete($user),
            default => false,
        };
    }

    private function canView(Control $control, User $user): bool
    {
        // Security: Multi-tenancy - users can view controls from their tenant
        return $control->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function canEdit(Control $control, User $user): bool
    {
        // Security: Users can edit controls from their tenant
        // Could be extended with control owner checks
        return $control->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function canDelete(User $user): bool
    {
        // Security: Only admins can delete
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
